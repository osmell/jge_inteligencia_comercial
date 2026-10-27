<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Services\CustomerActivity;
use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Búsqueda de clientes con su actividad de compra, estado y clase ABC (una fila por compañía + código). */
class CustomerSearch
{
    public const PER_PAGE = 20;

    public const SORTS = ['last_purchase', 'net_total', 'documents', 'name'];

    public function __construct(private readonly CustomerActivity $activity, private readonly AbcClassification $abc) {}

    /**
     * Búsqueda por código, nombre o nombre comercial, con una fila por cliente (compañía + código).
     *
     * @return array{rows: array<int, array<string, mixed>>, has_more: bool}
     */
    public function search(?string $term, ?int $companyId, string $sort, int $page, ?int $typeId = null, ?string $status = null): array
    {
        $sales = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->tap(fn ($q) => SalesBasis::scope($q, 'sl'))
            ->toBase()
            ->selectRaw('sales_documents.company_id as company_id, sales_documents.customer_code as code')
            ->selectRaw('count(distinct sales_documents.id) as documents, sum('.SalesBasis::net('sl').') as net_total')
            ->selectRaw('min(sales_documents.issued_on) as first_purchase, max(sales_documents.issued_on) as last_purchase')
            ->groupBy('sales_documents.company_id', 'sales_documents.customer_code');

        $query = Customer::query()
            ->join('companies', 'companies.id', '=', 'customers.company_id')
            ->leftJoinSub($sales, 's', fn ($j) => $j->on('s.company_id', '=', 'customers.company_id')->on('s.code', '=', 'customers.code'))
            ->leftJoin('sellers as asg', 'asg.id', '=', 'customers.seller_id')
            ->leftJoin('sellers as def', fn ($j) => $j->on('def.branch_id', '=', 'customers.branch_id')->where('def.is_default', true))
            ->leftJoin('customer_types', 'customer_types.id', '=', 'customers.customer_type_id')
            ->when($companyId, fn ($q) => $q->where('customers.company_id', $companyId))
            ->when($typeId, fn ($q) => $q->where('customers.customer_type_id', $typeId));

        if ($status !== null) {
            $this->filterByStatus($query, $status, $companyId);
        }

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
            ->selectRaw('customers.company_id as company_id, companies.name as company, customers.code as code, customers.name as name, customers.trade_name as trade_name, customer_types.name as type, coalesce(asg.name, def.name) as seller, (asg.id is null) as seller_is_default')
            ->selectRaw('coalesce(s.documents, 0) as documents, coalesce(s.net_total, 0) as net_total, s.first_purchase as first_purchase, s.last_purchase as last_purchase')
            ->orderByRaw($order.', customers.name asc, customers.code asc')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $page_rows = $rows->take(self::PER_PAGE);
        $statuses = [];
        foreach ($page_rows->groupBy('company_id') as $cid => $group) {
            $statuses += $this->activity->statusesFor((int) $cid, array_values($group->pluck('code')->map(fn ($c) => (string) $c)->all()));
        }
        $abc = $page_rows->pluck('company_id')->unique()->mapWithKeys(fn ($id) => [(int) $id => $this->abc->classes((int) $id)]);

        return [
            'rows' => $page_rows->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'company' => (string) $r->company, 'code' => (string) $r->code, 'name' => (string) $r->name,
                'trade_name' => $r->trade_name, 'type' => $r->type, 'seller' => $r->seller, 'seller_is_default' => $r->seller !== null && (bool) $r->seller_is_default, 'abc' => $abc[(int) $r->company_id][(string) $r->code] ?? null, 'activity' => $statuses[(int) $r->company_id][(string) $r->code] ?? 'no_sales', 'documents' => (int) $r->documents,
                'net_total' => number_format((float) $r->net_total, 2, '.', ''),
                'first_purchase' => $r->first_purchase ? $this->date($r->first_purchase) : null,
                'last_purchase' => $r->last_purchase ? $this->date($r->last_purchase) : null,
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Limita el listado a los clientes en un estado de actividad (o, para «sin compras», a los que no tienen ninguna).
     *
     * @param  Builder<Customer>  $query
     */
    private function filterByStatus(Builder $query, string $status, ?int $companyId): void
    {
        $byCompany = $this->activity->codesWithStatus($status, $companyId);
        $negate = $status === 'no_sales';
        if ($byCompany === []) {
            if (! $negate) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $pairs = function ($q) use ($byCompany) {
            foreach ($byCompany as $company => $codes) {
                foreach (array_chunk($codes, 500) as $chunk) {
                    $q->orWhere(fn ($w) => $w->where('customers.company_id', $company)->whereIn('customers.code', $chunk));
                }
            }
        };
        $negate ? $query->whereNot($pairs) : $query->where($pairs);
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse($value)->toDateString();
    }
}
