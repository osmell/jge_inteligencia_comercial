<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerInteraction;
use App\Models\Digest;
use App\Models\InsightAlert;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resúmenes para gerencia de una compañía. El diario mira el día anterior (la venta contra el promedio de ese mismo día de la semana
 * en las cuatro semanas previas) y el estado de cartera, cotizaciones, seguimiento e inventario; el semanal, la semana anterior
 * completa (lunes a domingo) con sus principales clientes y productos, el avance de metas por vendedor y las recomendaciones.
 * Cada resumen se genera una vez por periodo y guarda las cifras tal como estaban en ese momento. Son cifras de toda la compañía.
 *
 * Formato: una lista de secciones, cada una con métricas {label, value, format (money|int|pct|text), hint, tone} y, a veces, una tabla.
 */
class DigestBuilder
{
    private const DAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __construct(
        private readonly CreditPortfolio $portfolio,
        private readonly PaymentPromises $promises,
        private readonly InventoryRotation $rotation,
        private readonly SellerGoals $goals,
    ) {}

    /** Resumen del día anterior a $today (si ya existe para ese día, devuelve el existente). */
    public function daily(Company $company, ?CarbonImmutable $today = null): Digest
    {
        $today ??= CarbonImmutable::today();
        $day = $today->subDay();
        $key = $day->toDateString();

        return $this->existing($company, Digest::DAILY, $key) ?? Digest::create([
            'company_id' => $company->id, 'kind' => Digest::DAILY, 'period_key' => $key, 'period_from' => $key, 'period_to' => $key, 'generated_at' => now(),
            'title' => "Resumen diario de {$company->name}: ".self::DAYS[$day->dayOfWeek]." {$day->format('d/m/Y')}",
            'data' => ['sections' => array_values(array_filter([$this->dailySales($company, $day), $this->credit($company, $today), $this->quotations($company, $today, null), $this->followUps($company, $today), $this->inventory($company)]))],
        ]);
    }

    /** Resumen de la semana anterior (lunes a domingo) a $today. */
    public function weekly(Company $company, ?CarbonImmutable $today = null): Digest
    {
        $today ??= CarbonImmutable::today();
        $from = $today->startOfWeek()->subWeek();
        $to = $from->addDays(6);
        $key = $from->format('o-\WW');

        return $this->existing($company, Digest::WEEKLY, $key) ?? Digest::create([
            'company_id' => $company->id, 'kind' => Digest::WEEKLY, 'period_key' => $key, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'generated_at' => now(),
            'title' => "Resumen semanal de {$company->name}: {$from->format('d/m')} al {$to->format('d/m/Y')}",
            'data' => ['sections' => array_values(array_filter([
                $this->weeklySales($company, $from, $to), $this->goalsSection($company, $today), $this->credit($company, $today), $this->quotations($company, $today, [$from, $to]), $this->insights($company), $this->inventory($company),
            ]))],
        ]);
    }

    private function existing(Company $company, string $kind, string $key): ?Digest
    {
        return Digest::query()->where(['company_id' => $company->id, 'kind' => $kind, 'period_key' => $key])->first();
    }

    /** @return array{net: float, documents: int} */
    private function sales(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $r = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')->where('d.company_id', $companyId)
            ->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])->selectRaw('coalesce(sum(l.net_total), 0) as net, count(distinct d.id) as documents')->first();

        return ['net' => round((float) $r?->net, 2), 'documents' => (int) $r?->documents];
    }

    private function change(float $now, ?float $before): ?float
    {
        return $before === null || $before <= 0 ? null : round(($now - $before) / $before * 100, 1);
    }

    private function tone(?float $change): string
    {
        return $change === null ? 'neutral' : ($change >= 0 ? 'good' : ($change <= -20 ? 'bad' : 'neutral'));
    }

    /** @return array<string, mixed> */
    private function dailySales(Company $company, CarbonImmutable $day): array
    {
        $now = $this->sales($company->id, $day, $day);
        $samples = [];
        for ($i = 1; $i <= 4; $i++) {
            $other = $this->sales($company->id, $day->subWeeks($i), $day->subWeeks($i));
            if ($other['documents'] > 0) {
                $samples[] = $other['net'];
            }
        }
        $average = count($samples) >= 2 ? round(array_sum($samples) / count($samples), 2) : null;
        $change = $this->change($now['net'], $average);
        $name = self::DAYS[$day->dayOfWeek];
        $month = $this->sales($company->id, $day->startOfMonth(), $day);

        return ['key' => 'sales', 'title' => 'Ventas', 'metrics' => [
            ['label' => "Venta neta del {$name} {$day->format('d/m')}", 'value' => $now['net'], 'format' => 'money', 'tone' => $this->tone($change),
                'hint' => $average === null ? 'Sin suficientes días comparables en las últimas 4 semanas.' : 'Contra el promedio de los '.count($samples)." {$name}s anteriores con ventas (".number_format($average, 2, ',', '.').')'.($change === null ? '' : ($change >= 0 ? ' · +' : ' · ').number_format($change, 1, ',', '.').' %')],
            ['label' => 'Facturas del día', 'value' => $now['documents'], 'format' => 'int'],
            ['label' => 'Venta neta del mes a la fecha', 'value' => $month['net'], 'format' => 'money', 'hint' => "Del 1 al {$day->day} de ".$day->format('m/Y')],
        ]];
    }

    /** @return array<string, mixed> */
    private function weeklySales(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = $this->sales($company->id, $from, $to);
        $before = $this->sales($company->id, $from->subWeek(), $to->subWeek());
        $change = $this->change($now['net'], $before['documents'] > 0 ? $before['net'] : null);

        $customers = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')->where('d.company_id', $company->id)->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('d.customer_code')->selectRaw('max(d.customer_name) as name, sum(l.net_total) as net')->orderByDesc('net')->limit(5)->get();
        $products = DB::table('sales_lines as l')->where('l.company_id', $company->id)->whereBetween('l.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.product_code')->selectRaw('max(l.product_name) as name, sum(l.net_total) as net')->orderByDesc('net')->limit(5)->get();

        return ['key' => 'sales', 'title' => 'Ventas de la semana', 'metrics' => [
            ['label' => 'Venta neta de la semana', 'value' => $now['net'], 'format' => 'money', 'tone' => $this->tone($change),
                'hint' => $before['documents'] > 0 ? 'Semana anterior: '.number_format($before['net'], 2, ',', '.').($change === null ? '' : ($change >= 0 ? ' · +' : ' · ').number_format($change, 1, ',', '.').' %') : 'Sin ventas la semana anterior para comparar.'],
            ['label' => 'Facturas', 'value' => $now['documents'], 'format' => 'int'],
        ], 'tables' => [
            ['title' => 'Principales clientes', 'columns' => [['key' => 'name', 'label' => 'Cliente', 'format' => 'text'], ['key' => 'net', 'label' => 'Venta neta', 'format' => 'money']], 'rows' => $customers->map(fn ($r) => ['name' => (string) $r->name, 'net' => round((float) $r->net, 2)])->all()],
            ['title' => 'Principales productos', 'columns' => [['key' => 'name', 'label' => 'Producto', 'format' => 'text'], ['key' => 'net', 'label' => 'Venta neta', 'format' => 'money']], 'rows' => $products->map(fn ($r) => ['name' => (string) $r->name, 'net' => round((float) $r->net, 2)])->all()],
        ]];
    }

    /** @return array<string, mixed>|null */
    private function goalsSection(Company $company, CarbonImmutable $today): ?array
    {
        $reader = User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', Role::ADMINISTRADOR))->first();
        if ($reader === null) {
            return null;
        }
        $progress = $this->goals->progress($reader, $today->subDay()->format('Y-m'), $company->id, $today->subDay());
        $rows = array_values(array_filter($progress['rows'], fn (array $r) => $r['status'] !== 'no_goal' && $r['status'] !== 'exempt'));
        if ($rows === []) {
            return null;
        }
        $labels = ['met' => 'Cumplida', 'warning' => 'Cerca', 'danger' => 'Lejos'];

        return ['key' => 'goals', 'title' => 'Metas del mes por vendedor', 'metrics' => [
            ['label' => 'Cumplimiento del equipo', 'value' => $progress['totals']['compliance_pct'], 'format' => 'pct', 'hint' => 'Vendedores con meta: '.$progress['totals']['with_goal']." · cumplidas: {$progress['totals']['met']}"],
        ], 'tables' => [[
            'title' => "Avance de {$progress['month']}", 'columns' => [['key' => 'seller', 'label' => 'Vendedor', 'format' => 'text'], ['key' => 'actual', 'label' => 'Venta neta', 'format' => 'money'], ['key' => 'goal', 'label' => 'Meta', 'format' => 'money'], ['key' => 'compliance', 'label' => 'Avance', 'format' => 'pct'], ['key' => 'status', 'label' => 'Estado', 'format' => 'text']],
            'rows' => array_map(fn (array $r) => ['seller' => $r['seller'], 'actual' => $r['actual'], 'goal' => $r['effective_goal'], 'compliance' => $r['compliance_pct'], 'status' => $labels[$r['status']] ?? $r['status']], $rows),
        ]]];
    }

    /** @return array<string, mixed> */
    private function credit(Company $company, CarbonImmutable $today): array
    {
        $aging = $this->portfolio->aging($company->id, null, $today);
        $total = (float) $aging['totals']['total'];
        $current = (float) $aging['totals']['current'];
        $invoices = array_sum(array_column($aging['customers'], 'invoices'));
        $promises = $this->promises->list(null, ['company_id' => $company->id, 'status' => 'all'], $today)['rows'];
        $dueToday = count(array_filter($promises, fn (array $p) => $p['status'] === PaymentPromises::OPEN && $p['promised_on'] === $today->toDateString()));
        $broken = count(array_filter($promises, fn (array $p) => $p['status'] === PaymentPromises::BROKEN && $p['days_overdue'] <= 7));
        $over90 = array_sum(array_map(fn (string $b) => (float) $aging['totals'][$b], array_filter($aging['buckets'], fn (string $b) => str_ends_with($b, '+') || (int) $b >= 91)));

        return ['key' => 'credit', 'title' => 'Cartera', 'metrics' => [
            ['label' => 'Saldo por cobrar', 'value' => $total, 'format' => 'money', 'hint' => "{$invoices} facturas con saldo"],
            ['label' => 'Saldo vencido (con 1 día o más)', 'value' => round($total - $current, 2), 'format' => 'money', 'tone' => $total - $current > 0 ? 'bad' : 'good'],
            ['label' => 'Saldo con más de 90 días', 'value' => round($over90, 2), 'format' => 'money', 'tone' => $over90 > 0 ? 'bad' : 'good'],
            ['label' => 'Promesas de pago que vencen hoy', 'value' => $dueToday, 'format' => 'int'],
            ['label' => 'Promesas incumplidas en los últimos 7 días', 'value' => $broken, 'format' => 'int', 'tone' => $broken > 0 ? 'bad' : 'good'],
        ]];
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $week
     * @return array<string, mixed>
     */
    private function quotations(Company $company, CarbonImmutable $today, ?array $week): array
    {
        $open = Quotation::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('status', Quotation::EXPIRABLE)->get(['status', 'amount', 'valid_until']);
        $alive = $open->filter(fn (Quotation $q) => $q->valid_until === null || CarbonImmutable::parse($q->valid_until)->gte($today));
        $soon = $alive->filter(fn (Quotation $q) => $q->valid_until !== null && CarbonImmutable::parse($q->valid_until)->lte($today->addDays(3)));
        $expired = $open->count() - $alive->count();
        $metrics = [
            ['label' => 'Cotizaciones abiertas', 'value' => $alive->count(), 'format' => 'int', 'hint' => 'Monto: '.number_format((float) $alive->sum('amount'), 2, ',', '.')],
            ['label' => 'Por vencer en 3 días', 'value' => $soon->count(), 'format' => 'int', 'hint' => 'Monto: '.number_format((float) $soon->sum('amount'), 2, ',', '.'), 'tone' => $soon->count() > 0 ? 'bad' : 'neutral'],
            ['label' => 'Vencidas sin cerrar', 'value' => $expired, 'format' => 'int', 'tone' => $expired > 0 ? 'bad' : 'good'],
        ];
        if ($week !== null) {
            $issued = Quotation::withoutGlobalScopes()->where('company_id', $company->id)->whereBetween('issued_at', [$week[0]->startOfDay(), $week[1]->endOfDay()])->get(['amount']);
            $converted = Quotation::withoutGlobalScopes()->where('company_id', $company->id)->whereBetween('converted_at', [$week[0]->startOfDay(), $week[1]->endOfDay()])->get(['amount']);
            $metrics[] = ['label' => 'Cotizaciones emitidas en la semana', 'value' => $issued->count(), 'format' => 'int', 'hint' => 'Monto: '.number_format((float) $issued->sum('amount'), 2, ',', '.')];
            $metrics[] = ['label' => 'Convertidas en factura en la semana', 'value' => $converted->count(), 'format' => 'int', 'hint' => 'Monto: '.number_format((float) $converted->sum('amount'), 2, ',', '.')];
        }

        return ['key' => 'quotations', 'title' => 'Cotizaciones', 'metrics' => $metrics];
    }

    /** @return array<string, mixed> */
    private function followUps(Company $company, CarbonImmutable $today): array
    {
        $overdue = CustomerInteraction::query()->where('company_id', $company->id)->whereNotNull('next_action_at')->whereNull('next_action_done_at')->where('next_action_at', '<', $today->startOfDay())->count();
        $dueToday = CustomerInteraction::query()->where('company_id', $company->id)->whereNotNull('next_action_at')->whereNull('next_action_done_at')->whereBetween('next_action_at', [$today->startOfDay(), $today->endOfDay()])->count();

        return ['key' => 'followup', 'title' => 'Seguimiento comercial', 'metrics' => [
            ['label' => 'Acciones vencidas de toda la compañía', 'value' => $overdue, 'format' => 'int', 'tone' => $overdue > 0 ? 'bad' : 'good'],
            ['label' => 'Acciones para hoy', 'value' => $dueToday, 'format' => 'int'],
        ]];
    }

    /** @return array<string, mixed> */
    private function inventory(Company $company): array
    {
        $counts = $this->rotation->report(null, ['company_id' => $company->id])['counts'];

        return ['key' => 'inventory', 'title' => 'Inventario', 'metrics' => [
            ['label' => 'Productos agotados con demanda', 'value' => $counts['out_of_stock'], 'format' => 'int', 'tone' => $counts['out_of_stock'] > 0 ? 'bad' : 'good'],
            ['label' => 'Productos bajo el mínimo', 'value' => $counts['below_min'], 'format' => 'int', 'tone' => $counts['below_min'] > 0 ? 'bad' : 'good'],
            ['label' => 'Productos sin rotación', 'value' => $counts['no_rotation'], 'format' => 'int'],
        ]];
    }

    /** @return array<string, mixed> */
    private function insights(Company $company): array
    {
        $open = InsightAlert::withoutGlobalScopes()->where(['company_id' => $company->id, 'status' => InsightAlert::OPEN])->get(['type', 'severity']);
        $count = fn (string $type) => $open->where('type', $type)->count();

        return ['key' => 'insights', 'title' => 'Recomendaciones comerciales', 'metrics' => [
            ['label' => 'Recomendaciones abiertas de gravedad alta', 'value' => $open->where('severity', 'high')->count(), 'format' => 'int', 'tone' => $open->where('severity', 'high')->count() > 0 ? 'bad' : 'good'],
            ['label' => 'Clientes en riesgo (segmentación RFM)', 'value' => $count(InsightAlert::RFM_AT_RISK), 'format' => 'int'],
            ['label' => 'Clientes con caída de venta', 'value' => $count(InsightAlert::SALES_DROP), 'format' => 'int'],
            ['label' => 'Clientes en riesgo de inactividad', 'value' => $count(InsightAlert::INACTIVITY_RISK), 'format' => 'int'],
        ]];
    }
}
