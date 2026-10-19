<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use Carbon\CarbonImmutable;

/**
 * Ficha de cliente de solo lectura: datos maestros (tabla `customers`) y su actividad en las ventas importadas.
 * Un cliente se identifica por compañía + código de origen (el código es único por compañía); nunca se fusionan clientes
 * por parecido de nombre. Las ventas parten de SalesDocument/SalesLine y los clientes de Customer, así que el ámbito de
 * compañía/sucursal del usuario se aplica por los global scopes.
 */
class CustomerInsights
{
    public const PER_PAGE = 20;

    public const SORTS = ['last_purchase', 'net_total', 'documents', 'name'];

    public function __construct(private readonly SalesStatistics $stats) {}

    /**
     * Búsqueda por código, nombre o nombre comercial, con una fila por cliente (compañía + código).
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function search(?string $term, ?int $companyId, string $sort, int $page): array
    {
        $sales = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->toBase()
            ->selectRaw('sales_documents.company_id as company_id, sales_documents.customer_code as code')
            ->selectRaw('count(distinct sales_documents.id) as documents, sum(sl.net_total) as net_total')
            ->selectRaw('min(sales_documents.issued_on) as first_purchase, max(sales_documents.issued_on) as last_purchase')
            ->groupBy('sales_documents.company_id', 'sales_documents.customer_code');

        $query = Customer::query()
            ->join('companies', 'companies.id', '=', 'customers.company_id')
            ->leftJoinSub($sales, 's', fn ($j) => $j->on('s.company_id', '=', 'customers.company_id')->on('s.code', '=', 'customers.code'))
            ->when($companyId, fn ($q) => $q->where('customers.company_id', $companyId));

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("lower(customers.code) like ? escape '\\'", [$like])
                ->orWhereRaw("lower(customers.name) like ? escape '\\'", [$like])
                ->orWhereRaw("lower(coalesce(customers.trade_name, '')) like ? escape '\\'", [$like]));
        }

        $order = match ($sort) {
            'net_total' => 'coalesce(s.net_total, 0) desc',
            'documents' => 'coalesce(s.documents, 0) desc',
            'name' => 'customers.name asc',
            default => 's.last_purchase desc nulls last',
        };

        $rows = $query->toBase()
            ->selectRaw('customers.company_id as company_id, companies.name as company, customers.code as code, customers.name as name, customers.trade_name as trade_name')
            ->selectRaw('coalesce(s.documents, 0) as documents, coalesce(s.net_total, 0) as net_total, s.first_purchase as first_purchase, s.last_purchase as last_purchase')
            ->orderByRaw($order.', customers.name asc, customers.code asc')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'company' => (string) $r->company, 'code' => (string) $r->code, 'name' => (string) $r->name,
                'trade_name' => $r->trade_name, 'documents' => (int) $r->documents,
                'net_total' => number_format((float) $r->net_total, 2, '.', ''),
                'first_purchase' => $r->first_purchase ? $this->date($r->first_purchase) : null,
                'last_purchase' => $r->last_purchase ? $this->date($r->last_purchase) : null,
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Datos maestros y resumen de ventas de todo el historial disponible. Null si el cliente no existe en el ámbito del usuario.
     *
     * @return array<string, mixed>|null
     */
    public function profile(int $companyId, string $code, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $customer = Customer::query()->with(['company', 'branch'])->where('company_id', $companyId)->where('code', $code)->first();
        if (! $customer) {
            return null;
        }

        $filters = ['customer_code' => $code, 'company_id' => $companyId];
        $overall = $this->stats->overall($filters);
        $hasSales = $overall['first'] !== null;
        $first = $hasSales ? CarbonImmutable::parse($overall['first']) : null;
        $last = $hasSales ? CarbonImmutable::parse($overall['last']) : null;

        // Primera venta del sistema en esa compañía (en el ámbito del usuario): lo anterior no está reflejado en la ficha.
        $historyStarts = SalesLine::query()->where('sales_lines.company_id', $companyId)->min('issued_on');
        $top = fn (string $dimension, int $limit) => $hasSales ? $this->stats->breakdown($dimension, $first, $last, $filters, 'net_total', $limit)['rows'] : [];
        $dates = $hasSales
            ? SalesDocument::query()->where('customer_code', $code)->where('company_id', $companyId)->distinct()->orderBy('issued_on')->pluck('issued_on')->map(fn ($d) => $this->date($d))->unique()->values()->all()
            : [];

        return [
            'customer' => [
                'id' => $customer->id, 'company_id' => $customer->company_id, 'company' => $customer->company->name, 'branch' => $customer->branch?->name,
                'code' => $customer->code, 'name' => $customer->name, 'trade_name' => $customer->trade_name, 'contact_name' => $customer->contact_name,
                'phone' => $customer->phone, 'whatsapp' => $customer->whatsapp, 'email' => $customer->email, 'address' => $customer->address,
                'source' => $customer->source,
            ],
            'first_purchase' => $overall['first'],
            'last_purchase' => $overall['last'],
            'days_since_last_purchase' => $last ? (int) $last->diffInDays($today, false) : null,
            'history_starts_on' => $historyStarts ? CarbonImmutable::parse($historyStarts)->toDateString() : null,
            'totals' => $overall['kpis'],
            'frequency' => $this->frequency($dates),
            'monthly' => $this->monthly($filters, $today),
            'top_products' => $top('product', 10),
            'top_categories' => $top('category', 5),
            'sellers' => $top('seller', 10),
            'branches' => $top('branch', 10),
            'notes' => ['margin' => 'estimated_with_current_cost', 'documents' => 'distinct_per_group'],
        ];
    }

    /**
     * Facturas del cliente, de la más reciente a la más antigua.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function purchases(int $companyId, string $code, int $page): array
    {
        $rows = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->join('branches', 'branches.id', '=', 'sales_documents.branch_id')
            ->join('sellers', 'sellers.id', '=', 'sales_documents.seller_id')
            ->join('operators', 'operators.id', '=', 'sales_documents.operator_id')
            ->where('sales_documents.company_id', $companyId)
            ->where('sales_documents.customer_code', $code)
            ->toBase()
            ->selectRaw('sales_documents.id as id, sales_documents.series as series, sales_documents.number as number, sales_documents.issued_on as issued_on')
            ->selectRaw('max(branches.name) as branch, max(sellers.name) as seller, max(operators.name) as operator, count(sl.id) as lines')
            ->selectRaw('sum(sl.net_total) as net_total, sum(sl.grand_total) as grand_total')
            ->groupBy('sales_documents.id', 'sales_documents.series', 'sales_documents.number', 'sales_documents.issued_on')
            ->orderByDesc('sales_documents.issued_on')->orderByDesc('sales_documents.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => "{$r->series}-{$r->number}", 'issued_on' => $this->date($r->issued_on),
                'branch' => (string) $r->branch, 'seller' => (string) $r->seller, 'operator' => (string) $r->operator, 'lines' => (int) $r->lines,
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
    public function documentLines(int $companyId, string $code, int $documentId): ?array
    {
        $document = SalesDocument::query()->where('company_id', $companyId)->where('customer_code', $code)->find($documentId);

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
