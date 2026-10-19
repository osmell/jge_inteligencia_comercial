<?php

namespace App\Services;

use App\Models\SalesDocument;
use App\Models\SalesLine;
use Carbon\CarbonImmutable;

/**
 * Ficha de cliente de solo lectura, construida a partir de las ventas importadas.
 * Un cliente se identifica por su código de origen (`customer_code`); no se crean ni se fusionan clientes por parecido de nombre.
 * Parte de SalesDocument/SalesLine, así que el ámbito de compañía/sucursal del usuario se aplica por el global scope.
 */
class CustomerInsights
{
    public const PER_PAGE = 20;

    public const SORTS = ['last_purchase', 'net_total', 'documents', 'name'];

    public function __construct(private readonly SalesStatistics $stats) {}

    /**
     * Búsqueda por código o nombre, con una fila por código de cliente.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function search(?string $term, ?int $companyId, string $sort, int $page): array
    {
        $query = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->when($companyId, fn ($q) => $q->where('sales_documents.company_id', $companyId));

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("lower(sales_documents.customer_code) like ? escape '\\'", [$like])
                ->orWhereRaw("lower(sales_documents.customer_name) like ? escape '\\'", [$like]));
        }

        $order = match ($sort) {
            'net_total' => 'sum(sl.net_total) desc',
            'documents' => 'count(distinct sales_documents.id) desc',
            'name' => 'max(sales_documents.customer_name) asc',
            default => 'max(sales_documents.issued_on) desc',
        };

        $rows = $query->toBase()
            ->selectRaw('sales_documents.customer_code as code, max(sales_documents.customer_name) as name')
            ->selectRaw('count(distinct sales_documents.id) as documents, sum(sl.net_total) as net_total')
            ->selectRaw('min(sales_documents.issued_on) as first_purchase, max(sales_documents.issued_on) as last_purchase')
            ->groupBy('sales_documents.customer_code')
            ->orderByRaw($order.', sales_documents.customer_code asc')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'code' => (string) $r->code, 'name' => (string) $r->name, 'documents' => (int) $r->documents,
                'net_total' => number_format((float) $r->net_total, 2, '.', ''),
                'first_purchase' => $this->date($r->first_purchase), 'last_purchase' => $this->date($r->last_purchase),
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Resumen del cliente sobre todo el historial disponible. Null si no tiene ventas en el ámbito del usuario.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $code, ?int $companyId, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $filters = ['customer_code' => $code] + ($companyId ? ['company_id' => $companyId] : []);

        $overall = $this->stats->overall($filters);
        if ($overall['first'] === null) {
            return null;
        }

        $first = CarbonImmutable::parse($overall['first']);
        $last = CarbonImmutable::parse($overall['last']);

        $docs = fn () => SalesDocument::query()->where('customer_code', $code)->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        $names = $docs()->toBase()->selectRaw('customer_name as name, count(*) as documents, max(issued_on) as last_seen')
            ->groupBy('customer_name')->orderByRaw('max(issued_on) desc')->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'documents' => (int) $r->documents, 'last_seen' => $this->date($r->last_seen)])->all();

        $companies = $docs()->join('companies', 'companies.id', '=', 'sales_documents.company_id')->toBase()
            ->selectRaw('companies.id as id, companies.name as name, count(*) as documents')->groupBy('companies.id', 'companies.name')->orderBy('companies.name')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'documents' => (int) $r->documents])->all();

        // Primera venta del sistema en el ámbito del usuario: lo anterior no está reflejado en la ficha.
        $historyStarts = SalesLine::query()->when($companyId, fn ($q) => $q->where('sales_lines.company_id', $companyId))->min('issued_on');

        return [
            'code' => $code,
            'name' => $names[0]['name'],
            'name_variants' => $names,
            'companies' => $companies,
            'first_purchase' => $overall['first'],
            'last_purchase' => $overall['last'],
            'days_since_last_purchase' => (int) $last->diffInDays($today, false),
            'history_starts_on' => $historyStarts ? CarbonImmutable::parse($historyStarts)->toDateString() : null,
            'totals' => $overall['kpis'],
            'frequency' => $this->frequency($docs()->distinct()->orderBy('issued_on')->pluck('issued_on')->map(fn ($d) => $this->date($d))->unique()->values()->all()),
            'monthly' => $this->monthly($filters, $today),
            'top_products' => $this->stats->breakdown('product', $first, $last, $filters, 'net_total', 10)['rows'],
            'top_categories' => $this->stats->breakdown('category', $first, $last, $filters, 'net_total', 5)['rows'],
            'sellers' => $this->stats->breakdown('seller', $first, $last, $filters, 'net_total', 10)['rows'],
            'branches' => $this->stats->breakdown('branch', $first, $last, $filters, 'net_total', 10)['rows'],
            'notes' => ['margin' => 'estimated_with_current_cost', 'documents' => 'distinct_per_group'],
        ];
    }

    /**
     * Facturas del cliente, de la más reciente a la más antigua.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function purchases(string $code, ?int $companyId, int $page): array
    {
        $rows = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->join('branches', 'branches.id', '=', 'sales_documents.branch_id')
            ->join('sellers', 'sellers.id', '=', 'sales_documents.seller_id')
            ->where('sales_documents.customer_code', $code)
            ->when($companyId, fn ($q) => $q->where('sales_documents.company_id', $companyId))
            ->toBase()
            ->selectRaw('sales_documents.id as id, sales_documents.series as series, sales_documents.number as number, sales_documents.issued_on as issued_on')
            ->selectRaw('max(branches.name) as branch, max(sellers.name) as seller, count(sl.id) as lines')
            ->selectRaw('sum(sl.net_total) as net_total, sum(sl.grand_total) as grand_total')
            ->groupBy('sales_documents.id', 'sales_documents.series', 'sales_documents.number', 'sales_documents.issued_on')
            ->orderByDesc('sales_documents.issued_on')->orderByDesc('sales_documents.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => "{$r->series}-{$r->number}", 'issued_on' => $this->date($r->issued_on),
                'branch' => (string) $r->branch, 'seller' => (string) $r->seller, 'lines' => (int) $r->lines,
                'net_total' => number_format((float) $r->net_total, 2, '.', ''), 'grand_total' => number_format((float) $r->grand_total, 2, '.', ''),
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Líneas de una factura del cliente. Null si la factura no es de ese cliente o está fuera del ámbito del usuario.
     *
     * @return list<array<string, mixed>>|null
     */
    public function documentLines(string $code, int $documentId): ?array
    {
        $document = SalesDocument::query()->where('customer_code', $code)->find($documentId);

        return $document?->lines()->toBase()->get()->map(fn ($l) => [
            'line' => (int) $l->line_number, 'product_code' => $l->product_code, 'product_name' => $l->product_name, 'category' => $l->category_name,
            'units' => number_format((float) $l->units, 2, '.', ''), 'net_total' => number_format((float) $l->net_total, 2, '.', ''),
            'grand_total' => number_format((float) $l->grand_total, 2, '.', ''),
        ])->all();
    }

    /**
     * Frecuencia a partir de los días distintos con compra. Sin umbrales de inactividad: esas reglas están por definir.
     *
     * @param  list<string>  $dates  fechas Y-m-d ordenadas
     * @return array{purchase_days: int, average_days_between: float|null}
     */
    private function frequency(array $dates): array
    {
        $n = count($dates);

        return [
            'purchase_days' => $n,
            'average_days_between' => $n > 1 ? round(CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse($dates[$n - 1])) / ($n - 1), 1) : null,
        ];
    }

    /**
     * Venta neta de los últimos 12 meses (incluido el actual), con ceros en los meses sin compras.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, net_total: string}>
     */
    private function monthly(array $filters, CarbonImmutable $today): array
    {
        $start = $today->startOfMonth()->subMonthsNoOverflow(11);
        $sales = collect($this->stats->breakdown('month', $start, $today, $filters, 'net_total', 12)['rows'])->pluck('net_total', 'key');

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $key = $start->addMonthsNoOverflow($i)->format('Y-m');
            $out[] = ['key' => $key, 'net_total' => $sales[$key] ?? '0.00'];
        }

        return $out;
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse($value)->toDateString();
    }
}
