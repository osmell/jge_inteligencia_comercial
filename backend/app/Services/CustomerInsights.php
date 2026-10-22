<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Services\Customers\AbcClassification;
use Carbon\CarbonImmutable;

/**
 * Ficha de cliente de solo lectura (la búsqueda, las facturas y la clase ABC viven en `App\Services\Customers`): datos maestros (tabla `customers`) y su actividad en las ventas importadas.
 * Un cliente se identifica por compañía + código de origen (el código es único por compañía); nunca se fusionan clientes
 * por parecido de nombre. Las ventas parten de SalesDocument/SalesLine y los clientes de Customer, así que el ámbito de
 * compañía/sucursal del usuario se aplica por los global scopes.
 */
class CustomerInsights
{
    public function __construct(private readonly SalesStatistics $stats, private readonly CustomerActivity $activity, private readonly AbcClassification $abc) {}

    /**
     * Datos maestros y resumen de ventas de todo el historial disponible. Null si el cliente no existe en el ámbito del usuario.
     *
     * @return array<string, mixed>|null
     */
    public function profile(int $companyId, string $code, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today();
        $customer = Customer::query()->with(['company', 'branch', 'type', 'seller.supervisor', 'operator'])->where('company_id', $companyId)->where('code', $code)->first();
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
        $top = fn (string $dimension, int $limit) => $first !== null && $last !== null ? $this->stats->breakdown($dimension, $first, $last, $filters, 'net_total', $limit)['rows'] : [];
        $dates = $hasSales
            ? SalesDocument::query()->where('customer_code', $code)->where('company_id', $companyId)->distinct()->orderBy('issued_on')->pluck('issued_on')->map(fn ($d) => $this->date($d))->unique()->values()->all()
            : [];
        $dates = array_values($dates);

        return [
            'customer' => [
                'id' => $customer->id, 'company_id' => $customer->company_id, 'company' => $customer->company?->name, 'branch' => $customer->branch?->name, 'branch_id' => $customer->branch_id,
                'code' => $customer->code, 'name' => $customer->name, 'trade_name' => $customer->trade_name, 'contact_name' => $customer->contact_name,
                'phone' => $customer->phone, 'whatsapp' => $customer->whatsapp, 'email' => $customer->email, 'address' => $customer->address,
                'source' => $customer->source,
                'do_not_contact' => $customer->do_not_contact, 'do_not_contact_reason' => $customer->do_not_contact_reason,
                'type' => $customer->type ? ['id' => $customer->type->id, 'name' => $customer->type->name] : null,
                'abc' => $this->abc->classes($companyId, $today)[$code] ?? null,
            ],
            'assignment' => $this->assignment($customer),
            'activity' => ['status' => $this->activity->statusesFor($companyId, [$code], $today)[$companyId][$code]] + $this->activity->rules(),
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

    /**
     * Vendedor, facturador y supervisor del cliente. Sin vendedor asignado se usa la «Tienda» por defecto de su sucursal;
     * el supervisor es el del vendedor.
     *
     * @return array<string, mixed>
     */
    public function assignment(Customer $customer): array
    {
        $seller = $customer->seller;
        $assigned = $seller !== null;
        if ($seller === null && $customer->branch_id !== null) {
            $seller = Seller::query()->with('supervisor')->where('branch_id', $customer->branch_id)->where('is_default', true)->first();
        }

        return [
            'seller' => $seller ? ['id' => $seller->id, 'name' => $seller->name, 'assigned' => $assigned] : null,
            'operator' => $customer->operator ? ['id' => $customer->operator->id, 'name' => $customer->operator->name] : null,
            'supervisor' => $seller?->supervisor ? ['id' => $seller->supervisor->id, 'name' => $seller->supervisor->name] : null,
        ];
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse($value)->toDateString();
    }
}
