<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CreditInvoice;
use App\Models\ImportBatch;
use App\Models\InsightAlert;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del dashboard inicial. Solo reúne lo que ya calculan los demás servicios, con el alcance de quien lo pide:
 * el vendedor ve lo de sus clientes y ventas; el supervisor, lo de los vendedores que supervisa; el resto, todo su ámbito de
 * compañías. Cada sección exige el mismo permiso que su pantalla de origen y se calcula por separado.
 */
class DashboardService
{
    public const PERIODS = ['month', 'prev_month', 'quarter', 'year'];

    /** Sección => permiso que exige (el mismo de su pantalla de origen). */
    private const SECTIONS = [
        'sales' => 'sales.view', 'goals' => 'sales.view', 'rankings' => 'sales.view', 'companies' => 'sales.view',
        'credit' => 'credit.view', 'quotations' => 'customers.view', 'followup' => 'interactions.manage', 'conversations' => 'conversations.view',
        'inventory' => 'inventory.view', 'insights' => 'insights.view',
    ];

    public function __construct(
        private readonly SalesStatistics $sales,
        private readonly SellerGoals $goals,
        private readonly CreditPortfolio $portfolio,
        private readonly PaymentPromises $promises,
        private readonly FollowUpAgenda $agenda,
        private readonly FollowUpScope $followScope,
        private readonly InventoryRotation $rotation,
        private readonly DataScope $scope,
        private readonly ConversationAccess $conversations,
    ) {}

    /**
     * Perfil de la persona: «seller» (vendedor), «team» (supervisor) o «company» (administración, gerencia y demás).
     *
     * @return array{profile: string, seller_ids: list<int>|null}
     */
    public function profile(User $user): array
    {
        return match ($user->role->slug) {
            Role::VENDEDOR => ['profile' => 'seller', 'seller_ids' => $this->ids(DB::table('sellers')->where('user_id', $user->id))],
            Role::SUPERVISOR_VENTAS => ['profile' => 'team', 'seller_ids' => $this->ids(DB::table('sellers')->where('supervisor_id', $user->id))],
            default => ['profile' => 'company', 'seller_ids' => null],
        };
    }

    /** @return list<string> secciones que esta persona puede ver, en el orden del dashboard */
    public function sectionsFor(User $user): array
    {
        $profile = $this->profile($user)['profile'];
        $keys = array_keys(array_filter(self::SECTIONS, fn (string $permission) => $user->hasPermission($permission)));

        // El comparativo por compañía solo tiene sentido para quien ve más de una.
        return array_values(array_filter($keys, fn (string $k) => $k !== 'companies' || ($profile === 'company' && ($this->scope->companyIds($user) === null || count($this->scope->companyIds($user)) > 1))));
    }

    /** @return list<string> nombres de los vendedores de quien supervisa o vendedor vinculado a la persona (vacío para el resto) */
    public function teamNames(User $user): array
    {
        $ids = $this->profile($user)['seller_ids'];

        return $ids === null ? [] : DB::table('sellers')->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    /** @return list<int> compañías del ámbito de la persona (para el selector) */
    public function companyIds(User $user): array
    {
        return $this->scope->companyIds($user) ?? DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Periodo elegido, el equivalente anterior (mismo tramo) y el del año anterior.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, previous: array{0: CarbonImmutable, 1: CarbonImmutable}, year_ago: array{0: CarbonImmutable, 1: CarbonImmutable}|null}
     */
    public function period(string $period, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        [$from, $to, $months, $label] = match ($period) {
            'prev_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), 1, 'Mes anterior'],
            'quarter' => [$today->startOfQuarter(), $today, 3, 'Trimestre en curso'],
            'year' => [$today->startOfYear(), $today, 12, 'Año en curso'],
            default => [$today->startOfMonth(), $today, 1, 'Mes en curso'],
        };

        return [
            'from' => $from, 'to' => $to, 'label' => $label,
            'previous' => [$from->subMonthsNoOverflow($months), $period === 'prev_month' ? $from->subDay() : $to->subMonthsNoOverflow($months)],
            'year_ago' => $period === 'year' ? null : [$from->subYear(), $to->subYear()],
        ];
    }

    /**
     * Calcula una sección. Devuelve null si la persona no puede verla.
     *
     * @return array<string, mixed>|null
     */
    public function section(User $user, string $key, string $period, ?int $companyId, ?CarbonImmutable $today = null): ?array
    {
        if (! in_array($key, $this->sectionsFor($user), true)) {
            return null;
        }
        $today ??= CarbonImmutable::today();
        $p = $this->profile($user);
        $window = $this->period($period, $today);
        $filters = ['company_id' => $companyId] + ($p['seller_ids'] === null ? [] : ['seller_ids' => $p['seller_ids']]);

        return ['key' => $key, 'profile' => $p['profile']] + match ($key) {
            'sales' => $this->salesSection($window, $filters),
            'goals' => $this->goalsSection($user, $p['seller_ids'], $window['to'], $companyId, $today),
            'rankings' => $this->rankingsSection($window, $filters, $p['profile']),
            'companies' => $this->companiesSection($window, $filters),
            'credit' => $this->creditSection($p['seller_ids'], $companyId, $today),
            'quotations' => $this->quotationsSection($user, $window, $companyId, $today),
            'followup' => $this->followupSection($user, $today),
            'conversations' => $this->conversationsSection($user, $window, $companyId),
            'inventory' => $this->inventorySection($user, $companyId),
            default => $this->insightsSection($p['seller_ids'], $companyId),
        };
    }

    /** Fecha de la última venta visible y de la última carga de ventas. @return array{last_sale: string|null, last_import: string|null} */
    public function freshness(): array
    {
        $last = SalesLine::query()->max('issued_on');
        $import = ImportBatch::query()->where('type', 'sales')->where('status', 'imported')->max('created_at');

        return ['last_sale' => $last === null ? null : CarbonImmutable::parse($last)->toDateString(), 'last_import' => $import === null ? null : CarbonImmutable::parse($import)->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function salesSection(array $w, array $filters): array
    {
        $summary = $this->sales->summary($w['from'], $w['to'], $filters, $w['previous']);
        $yearAgo = $w['year_ago'] === null ? null : $this->sales->summary($w['from'], $w['to'], $filters, $w['year_ago']);
        $cur = $summary['current'];
        $block = fn (array $kpis, array $range, ?float $change) => ['from' => $range[0]->toDateString(), 'to' => $range[1]->toDateString(), 'net_total' => $kpis['net_total'], 'documents' => $kpis['documents'], 'change_pct' => $change];
        $first = $this->sales->overall($filters)['first'];

        return [
            'period' => ['from' => $w['from']->toDateString(), 'to' => $w['to']->toDateString(), 'label' => $w['label']],
            'current' => ['net_total' => $cur['net_total'], 'documents' => $cur['documents'], 'customers' => $cur['customers'], 'average_ticket' => $cur['average_ticket']],
            'previous' => $block($summary['previous'], $w['previous'], $summary['change_pct']['net_total'] ?? null),
            'year_ago' => $yearAgo === null ? null : $block($yearAgo['previous'], $w['year_ago'], $yearAgo['change_pct']['net_total'] ?? null),
            'daily' => $this->series('day', $w['from'], $w['to'], $filters, 'date'),
            'daily_previous' => $this->series('day', $w['previous'][0], $w['previous'][1], $filters, 'date'),
            'monthly' => $this->series('month', $w['to']->subMonthsNoOverflow(11)->startOfMonth(), $w['to'], $filters, 'month'),
            'history_since' => $first,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function series(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $name): array
    {
        return array_map(fn (array $r) => [$name => substr((string) $r['key'], 0, $dimension === 'month' ? 7 : 10), 'net_total' => $r['net_total'], 'documents' => $r['documents']],
            $this->sales->breakdown($dimension, $from, $to, $filters, 'net_total', 400)['rows']);
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function goalsSection(User $user, ?array $sellerIds, CarbonImmutable $monthOf, ?int $companyId, CarbonImmutable $today): array
    {
        $progress = $this->goals->progress($user, $monthOf->format('Y-m'), $companyId, $today);
        $rows = array_values(array_filter($progress['rows'], fn (array $r) => $sellerIds === null || in_array($r['seller_id'], $sellerIds, true)));
        $withGoal = array_values(array_filter($rows, fn (array $r) => ! in_array($r['status'], ['no_goal', 'exempt'], true)));
        $goal = array_sum(array_column($withGoal, 'effective_goal'));
        $actual = array_sum(array_column($withGoal, 'actual'));
        $projected = array_sum(array_map(fn (array $r) => $r['projected'] ?? $r['actual'], $withGoal));
        // Días que quedan del mes (hoy incluido) y lo que habría que vender por día para llegar a la meta.
        $daysLeft = $progress['state'] === 'current' ? $today->daysInMonth - $today->day + 1 : 0;
        $remaining = max(0, $goal - $actual);

        return [
            'month' => $progress['month'], 'state' => $progress['state'],
            'totals' => ['goal' => round($goal, 2), 'actual' => round($actual, 2), 'remaining' => round(max(0, $goal - $actual), 2), 'compliance_pct' => $goal > 0 ? round($actual / $goal * 100, 2) : null,
                'projected' => $progress['state'] === 'current' ? round($projected, 2) : null, 'projected_pct' => $progress['state'] === 'current' && $goal > 0 ? round($projected / $goal * 100, 2) : null,
                'days_left' => $daysLeft, 'needed_per_day' => $daysLeft > 0 && $remaining > 0 ? round($remaining / $daysLeft, 2) : null, 'sellers_with_goal' => count($withGoal), 'met' => count(array_filter($withGoal, fn (array $r) => $r['status'] === 'met'))],
            'rows' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['seller_id', 'seller', 'effective_goal', 'actual', 'compliance_pct', 'projected', 'projected_pct', 'status', 'behind_pace'])), $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function rankingsSection(array $w, array $filters, string $profile): array
    {
        $top = fn (string $dimension, int $limit) => array_map(fn (array $r) => ['key' => $r['key'], 'label' => $r['label'], 'net_total' => $r['net_total'], 'documents' => $r['documents']],
            $this->sales->breakdown($dimension, $w['from'], $w['to'], $filters, 'net_total', $limit)['rows']);

        return ['customers' => $top('customer', 5), 'products' => $top('product', 5)] + ($profile === 'seller' ? [] : ['sellers' => $top('seller', 10)]);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function companiesSection(array $w, array $filters): array
    {
        $previous = collect($this->sales->breakdown('company', $w['previous'][0], $w['previous'][1], $filters, 'net_total', 50)['rows'])->keyBy('key');

        return ['rows' => array_map(function (array $r) use ($previous) {
            $before = (float) ($previous[$r['key']]['net_total'] ?? 0);

            return ['company_id' => (int) $r['key'], 'company' => $r['label'], 'net_total' => $r['net_total'], 'documents' => $r['documents'], 'previous_net_total' => number_format($before, 2, '.', ''),
                'change_pct' => $before > 0 ? round(((float) $r['net_total'] - $before) / $before * 100, 1) : null];
        }, $this->sales->breakdown('company', $w['from'], $w['to'], $filters, 'net_total', 50)['rows'])];
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function creditSection(?array $sellerIds, ?int $companyId, CarbonImmutable $today): array
    {
        $aging = $this->portfolio->aging($companyId, null, $today, $sellerIds);
        $total = (float) $aging['totals']['total'];
        $current = (float) $aging['totals']['current'];
        $buckets = array_map(fn (string $b) => ['bucket' => $b, 'amount' => $aging['totals'][$b]], $aging['buckets']);
        $invoices = array_sum(array_column($aging['customers'], 'invoices'));

        $scoped = $sellerIds === null ? null : CreditInvoice::query()->whereIn('seller_id', $sellerIds)->toBase()->select('company_id', 'customer_code')->distinct()->get()->map(fn ($r) => $r->company_id.'|'.$r->customer_code)->flip();
        $rows = array_filter($this->promises->list(null, ['status' => 'all', 'company_id' => $companyId], $today)['rows'], fn (array $p) => $scoped === null || isset($scoped[$p['company_id'].'|'.$p['customer']['code']]));
        $soon = $today->addDays(7)->toDateString();
        $open = array_filter($rows, fn (array $p) => $p['status'] === PaymentPromises::OPEN && $p['promised_on'] <= $soon);
        $broken = array_filter($rows, fn (array $p) => $p['status'] === PaymentPromises::BROKEN && $p['days_overdue'] <= 30);
        $attention = array_map(fn (array $p) => ['company_id' => $p['company_id'], 'code' => $p['customer']['code'], 'name' => $p['customer']['name'], 'promised_on' => $p['promised_on'], 'amount' => $p['amount'], 'status' => $p['status'], 'days_overdue' => $p['days_overdue']],
            array_slice(array_merge($broken, $open), 0, 5));

        return [
            'total' => $aging['totals']['total'], 'overdue' => number_format($total - $current, 2, '.', ''), 'overdue_pct' => $total > 0 ? round(($total - $current) / $total * 100, 1) : null,
            'invoices' => $invoices, 'customers' => count($aging['customers']), 'buckets' => $buckets,
            'top_debtors' => array_map(fn (array $c) => ['company_id' => $c['company_id'], 'code' => $c['code'], 'name' => $c['name'], 'total' => $c['total'], 'oldest_days' => $c['oldest_days']], array_slice($aging['customers'], 0, 5)),
            'promises' => ['due_next_7_days' => count($open), 'due_amount' => number_format(array_sum(array_map(fn (array $p) => (float) $p['amount'], $open)), 2, '.', ''), 'broken_30_days' => count($broken), 'attention' => $attention],
        ];
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function quotationsSection(User $user, array $w, ?int $companyId, CarbonImmutable $today): array
    {
        $query = fn () => tap(Quotation::query()->when($companyId !== null, fn ($q) => $q->where('quotations.company_id', $companyId)), fn ($q) => $this->followScope->restrict($q, $user, []));
        $open = $query()->whereIn('status', Quotation::EXPIRABLE)->get(['status', 'amount', 'valid_until']);
        $alive = $open->filter(fn (Quotation $q) => $q->valid_until === null || $q->valid_until->gte($today));
        $soon = $alive->filter(fn (Quotation $q) => $q->valid_until !== null && $q->valid_until->lte($today->addDays(3)));
        $range = [$w['from']->startOfDay(), $w['to']->endOfDay()];
        $issued = $query()->whereBetween('issued_at', $range)->get(['amount']);
        $converted = $query()->whereBetween('converted_at', $range)->get(['amount']);

        return [
            'open' => ['count' => $alive->count(), 'amount' => number_format((float) $alive->sum('amount'), 2, '.', '')],
            'expiring' => ['count' => $soon->count(), 'amount' => number_format((float) $soon->sum('amount'), 2, '.', '')],
            'expired_unclosed' => $open->count() - $alive->count(),
            'issued' => ['count' => $issued->count(), 'amount' => number_format((float) $issued->sum('amount'), 2, '.', '')],
            'converted' => ['count' => $converted->count(), 'amount' => number_format((float) $converted->sum('amount'), 2, '.', '')],
        ];
    }

    /**
     * Atención por WhatsApp: clientes esperando respuesta, mensajes del periodo y rapidez de respuesta (con el alcance de la persona).
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $window
     * @return array<string, mixed>
     */
    private function conversationsSection(User $user, array $window, ?int $companyId): array
    {
        $now = CarbonImmutable::now();
        $limit = max(1, (int) config('conversations.waiting_minutes'));
        $scope = fn () => $this->conversations->visible($user)->when($companyId !== null, fn ($q) => $q->where('whatsapp_conversations.company_id', $companyId));
        $open = $scope()->where('whatsapp_conversations.status', 'open');

        $waiting = (clone $open)->whereNotNull('whatsapp_conversations.customer_id')->whereColumn('whatsapp_conversations.last_inbound_at', '>=', 'whatsapp_conversations.last_message_at')->orderBy('whatsapp_conversations.last_message_at')->get();
        $names = DB::table('customers')->whereIn('id', $waiting->pluck('customer_id'))->pluck('name', 'id');
        $minutes = fn ($c) => (int) $c->last_message_at->diffInMinutes($now);
        $unassigned = (clone $open)->whereNull('whatsapp_conversations.customer_id')->count();

        // Rapidez de respuesta: de cada mensaje del cliente que sigue a una respuesta (o abre la conversación) hasta la siguiente respuesta de una persona.
        $ids = $scope()->pluck('whatsapp_conversations.id');
        $rows = DB::table('whatsapp_messages')->whereIn('conversation_id', $ids)->whereBetween('created_at', [$window['from']->startOfDay(), $window['to']->endOfDay()])->where('message_type', '!=', 'reaction')
            ->orderBy('conversation_id')->orderBy('id')->limit(20000)->get(['conversation_id', 'direction', 'user_id', 'created_at']);
        $pending = [];
        $waits = [];
        $received = 0;
        $sent = 0;
        foreach ($rows as $m) {
            $at = CarbonImmutable::parse($m->created_at);
            if ($m->direction === 'in') {
                $received++;
                $pending[$m->conversation_id] ??= $at;
            } elseif ($m->user_id !== null) {
                $sent++;
                if (isset($pending[$m->conversation_id])) {
                    $waits[] = $pending[$m->conversation_id]->diffInMinutes($at);
                    unset($pending[$m->conversation_id]);
                }
            }
        }
        sort($waits);
        $median = $waits === [] ? null : (int) round($waits[intdiv(count($waits), 2)]);

        return [
            'limit_minutes' => $limit, 'waiting' => $waiting->count(), 'over_limit' => $waiting->filter(fn ($c) => $minutes($c) >= $limit)->count(), 'unassigned' => $unassigned,
            'top' => $waiting->take(5)->map(fn ($c) => ['id' => $c->id, 'customer' => $names[$c->customer_id] ?? '—', 'minutes' => $minutes($c)])->values()->all(),
            'received' => $received, 'sent' => $sent, 'answered' => count($waits), 'median_minutes' => $median,
            'within_limit_pct' => $waits === [] ? null : (int) round(100 * count(array_filter($waits, fn ($w) => $w <= $limit)) / count($waits)),
            'from' => $window['from']->toDateString(), 'to' => $window['to']->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function followupSection(User $user, CarbonImmutable $today): array
    {
        $days = 30;
        $a = $this->agenda->build($user, ['horizon_days' => 0, 'no_contact_days' => $days], CarbonImmutable::now());

        return [
            'overdue' => $a['meta']['overdue_total'], 'today' => $a['meta']['upcoming_total'], 'date' => $today->toDateString(),
            'actions' => array_map(fn (array $x) => ['customer' => ['company_id' => $x['customer']['company_id'], 'code' => $x['customer']['code'], 'name' => $x['customer']['name']], 'next_action' => $x['next_action'], 'next_action_at' => $x['next_action_at'], 'days_overdue' => $x['days_overdue']],
                array_slice(array_merge($a['overdue'], $a['upcoming']), 0, 5)),
            'no_contact' => [
                'count' => $a['meta']['reactivate_total'], 'days' => $days,
                'top' => array_map(fn (array $r) => ['code' => $r['code'], 'company_id' => $r['company_id'], 'name' => $r['name'], 'status' => $r['status'], 'days_since_last_purchase' => $r['days_since_last_purchase'], 'seller' => $r['seller']], array_slice($a['reactivate'], 0, 5)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inventorySection(User $user, ?int $companyId): array
    {
        $counts = $this->rotation->report($user, $companyId === null ? [] : ['company_id' => $companyId])['counts'];

        return ['out_of_stock' => $counts['out_of_stock'], 'below_min' => $counts['below_min'], 'no_rotation' => $counts['no_rotation'], 'above_max' => $counts['above_max']];
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function insightsSection(?array $sellerIds, ?int $companyId): array
    {
        $rows = InsightAlert::query()->where('status', InsightAlert::OPEN)
            ->when($companyId !== null, fn ($q) => $q->where('insight_alerts.company_id', $companyId))
            ->when($sellerIds !== null, fn ($q) => $q->whereExists(DB::table('customers')->selectRaw('1')->whereColumn('customers.company_id', 'insight_alerts.company_id')->whereColumn('customers.code', 'insight_alerts.customer_code')->whereIn('customers.seller_id', $sellerIds)))
            ->get(['type', 'severity']);

        return [
            'open' => $rows->count(), 'by_severity' => ['high' => $rows->where('severity', 'high')->count(), 'medium' => $rows->where('severity', 'medium')->count(), 'low' => $rows->where('severity', 'low')->count()],
            'at_risk' => $rows->where('type', InsightAlert::RFM_AT_RISK)->count() + $rows->where('type', InsightAlert::INACTIVITY_RISK)->count(), 'sales_drop' => $rows->where('type', InsightAlert::SALES_DROP)->count(),
        ];
    }

    /** @return list<int> */
    private function ids(QueryBuilder $query): array
    {
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
