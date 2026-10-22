<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesLine;
use App\Models\Seller;
use Carbon\CarbonImmutable;

/**
 * Reporte de ventas por cliente en un periodo, con quién vendió y quién facturó. Una venta es del vendedor, salvo cuando
 * el vendedor y el facturador (operador) tienen el mismo nombre: entonces es una venta directa del facturador (columna
 * `direct_net_total`) y cuenta en sus estadísticas. Las cifras respetan el ámbito del usuario. Las devoluciones aún no
 * se descuentan: no hay formato de nota de crédito.
 */
class CustomerSalesReport
{
    public const PER_PAGE = 50;

    public const MAX_ROWS = 5000;

    /** Personas por cliente que se muestran en pantalla (el archivo exportado las incluye todas). */
    public const PEOPLE_ON_SCREEN = 3;

    /**
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function page(CarbonImmutable $from, CarbonImmutable $to, ?int $companyId, ?int $branchId, int $page): array
    {
        $result = $this->rows($from, $to, $companyId, $branchId, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);

        return [
            'rows' => array_map(fn ($r) => $this->trim($r), array_slice($result, 0, self::PER_PAGE)),
            'has_more' => count($result) > self::PER_PAGE,
        ];
    }

    /** @return list<array<string, mixed>> todas las filas (hasta MAX_ROWS), de mayor a menor venta neta */
    public function all(CarbonImmutable $from, CarbonImmutable $to, ?int $companyId, ?int $branchId): array
    {
        return $this->rows($from, $to, $companyId, $branchId, self::MAX_ROWS, 0);
    }

    /** @return list<array<string, mixed>> */
    private function rows(CarbonImmutable $from, CarbonImmutable $to, ?int $companyId, ?int $branchId, int $limit, int $offset): array
    {
        $base = fn () => SalesLine::query()
            ->join('sales_documents as sd', 'sd.id', '=', 'sales_lines.sales_document_id')
            ->join('sellers as se', 'se.id', '=', 'sd.seller_id')
            ->join('operators as op', 'op.id', '=', 'sd.operator_id')
            ->where('sales_lines.issued_on', '>=', $from->toDateString())
            ->where('sales_lines.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyId, fn ($q) => $q->where('sales_lines.company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('sales_lines.branch_id', $branchId))
            // Solo clientes que el usuario puede ver (p. ej. un vendedor ve únicamente los suyos).
            ->whereExists(Customer::query()->selectRaw('1')->whereColumn('customers.company_id', 'sd.company_id')->whereColumn('customers.code', 'sd.customer_code')->toBase());

        $totals = $base()->toBase()
            ->selectRaw('sd.company_id as company_id, sd.customer_code as code')
            ->selectRaw('sum(sales_lines.net_total) as net_total, count(distinct sd.id) as documents, max(sd.issued_on) as last_purchase')
            ->selectRaw('sum(case when se.normalized_name = op.normalized_name then sales_lines.net_total else 0 end) as direct_net_total')
            ->groupBy('sd.company_id', 'sd.customer_code')
            ->orderByRaw('sum(sales_lines.net_total) desc')->orderBy('sd.company_id')->orderBy('sd.customer_code')
            ->offset($offset)->limit($limit)
            ->get();
        if ($totals->isEmpty()) {
            return [];
        }

        $keys = $totals->map(fn ($r) => ((int) $r->company_id).'|'.$r->code);
        $codes = $totals->pluck('code')->unique()->all();
        $companies = $totals->pluck('company_id')->unique()->all();
        $people = fn (string $table) => $base()->toBase()
            ->whereIn('sd.customer_code', $codes)->whereIn('sd.company_id', $companies)
            ->selectRaw("sd.company_id as company_id, sd.customer_code as code, {$table}.name as person, sum(sales_lines.net_total) as net_total")
            ->groupBy('sd.company_id', 'sd.customer_code', "{$table}.id", "{$table}.name")
            ->orderByRaw('sum(sales_lines.net_total) desc')
            ->get()
            ->groupBy(fn ($r) => ((int) $r->company_id).'|'.$r->code)
            ->map(fn ($g) => $g->map(fn ($r) => ['name' => (string) $r->person, 'net_total' => $this->money($r->net_total)])->values()->all());
        $sellers = $people('se');
        $operators = $people('op');

        $masters = Customer::query()->with(['type', 'seller.supervisor', 'company', 'branch'])->whereIn('code', $codes)->whereIn('company_id', $companies)->get()
            ->keyBy(fn (Customer $c) => $c->company_id.'|'.$c->code);
        $defaults = Seller::query()->with('supervisor')->where('is_default', true)->whereIn('branch_id', $masters->pluck('branch_id')->filter()->unique()->all())->get()->keyBy('branch_id');

        $rows = [];
        foreach ($totals as $i => $t) {
            $key = $keys[$i];
            $customer = $masters[$key] ?? null;
            if ($customer === null) {
                continue;
            }
            $seller = $customer->seller ?? ($customer->branch_id ? $defaults[$customer->branch_id] ?? null : null);
            $rows[] = [
                'company_id' => (int) $t->company_id, 'company' => $customer->company->name, 'code' => (string) $t->code, 'name' => $customer->name,
                'type' => $customer->type?->name,
                'assigned_seller' => $seller?->name, 'assigned_seller_is_default' => $seller !== null && $customer->seller_id === null,
                'supervisor' => $seller?->supervisor?->name,
                'net_total' => $this->money($t->net_total), 'documents' => (int) $t->documents,
                'last_purchase' => CarbonImmutable::parse($t->last_purchase)->toDateString(),
                'direct_net_total' => $this->money($t->direct_net_total),
                'sellers' => $sellers[$key] ?? [], 'operators' => $operators[$key] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function trim(array $row): array
    {
        foreach (['sellers', 'operators'] as $k) {
            $row[$k.'_more'] = max(0, count($row[$k]) - self::PEOPLE_ON_SCREEN);
            $row[$k] = array_slice($row[$k], 0, self::PEOPLE_ON_SCREEN);
        }

        return $row;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
