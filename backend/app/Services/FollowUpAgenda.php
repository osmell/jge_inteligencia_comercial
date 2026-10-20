<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agenda de seguimiento: próximas acciones pendientes (vencidas y próximas) y clientes inactivos o perdidos sin contacto reciente.
 *
 * Alcance: el vendedor ve solo sus clientes (alcance del modelo Customer); el supervisor, los clientes de los vendedores que
 * supervisa y los contactos que él mismo registró; el resto de roles, todo lo de su ámbito de compañías.
 */
class FollowUpAgenda
{
    public const LIMIT = 200;

    public const REACTIVATE_LIMIT = 50;

    public function __construct(private readonly CustomerActivity $activity, private readonly FollowUpScope $scope) {}

    /**
     * @param  array{company_id?: int|null, seller_id?: int|null, mine?: bool, horizon_days: int, no_contact_days: int}  $opts
     * @return array{overdue: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, reactivate: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function build(User $user, array $opts, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $pending = $this->pending($user, $opts, $now);
        $overdue = $pending->filter(fn (CustomerInteraction $i) => $i->next_action_at < $now)->values();
        $upcoming = $pending->filter(fn (CustomerInteraction $i) => $i->next_action_at >= $now)->values();
        $reactivate = $this->reactivate($user, $opts, $now);

        return [
            'overdue' => $overdue->take(self::LIMIT)->map(fn (CustomerInteraction $i) => $this->presentAction($i, $now))->all(),
            'upcoming' => $upcoming->take(self::LIMIT)->map(fn (CustomerInteraction $i) => $this->presentAction($i, $now))->all(),
            'reactivate' => array_slice($reactivate, 0, self::REACTIVATE_LIMIT),
            'meta' => [
                'overdue_total' => $overdue->count(), 'upcoming_total' => $upcoming->count(), 'reactivate_total' => count($reactivate),
                'horizon_days' => $opts['horizon_days'], 'no_contact_days' => $opts['no_contact_days'],
                'truncated' => $pending->count() > self::LIMIT * 2 || count($reactivate) > self::REACTIVATE_LIMIT,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return Collection<int, CustomerInteraction>
     */
    private function pending(User $user, array $opts, CarbonImmutable $now)
    {
        $query = CustomerInteraction::query()->with(['customer.seller', 'user'])
            ->whereNotNull('next_action_at')->whereNull('next_action_done_at')
            ->where('next_action_at', '<', $now->addDays($opts['horizon_days'])->endOfDay())
            ->when(! empty($opts['mine']), fn (Builder $q) => $q->where('user_id', $user->id))
            ->orderBy('next_action_at')->orderBy('id')
            ->limit(self::LIMIT * 2 + 1);

        $this->scope->restrict($query, $user, $opts);

        return $query->get();
    }

    /**
     * Clientes inactivos o perdidos, que aceptan comunicaciones y sin contacto registrado en los últimos N días.
     *
     * @param  array<string, mixed>  $opts
     * @return list<array<string, mixed>>
     */
    private function reactivate(User $user, array $opts, CarbonImmutable $now): array
    {
        $customers = Customer::query()->with(['seller', 'company'])->where('do_not_contact', false)
            ->tap(fn (Builder $q) => $this->scope->filterCustomers($q, $opts))
            ->when($this->scope->isSupervisor($user), fn (Builder $q) => $this->scope->team($q, $user))
            ->get();
        if ($customers->isEmpty()) {
            return [];
        }

        $rules = $this->activity->rules();
        $today = $now->startOfDay();
        $starts = $this->activity->historyStarts();
        $facts = [];
        foreach ($customers->pluck('company_id')->unique() as $companyId) {
            $facts[$companyId] = $this->activity->facts((int) $companyId)[(int) $companyId] ?? [];
        }

        $candidates = [];
        foreach ($customers as $c) {
            $f = $facts[$c->company_id][$c->code] ?? null;
            $status = $f === null ? 'no_sales' : $this->activity->classify($f, $starts[$c->company_id] ?? null, $today, $rules);
            if ($f !== null && in_array($status, ['inactive', 'lost'], true)) {
                $candidates[$c->id] = [$c, $status, $f['last']];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $lastContact = [];
        foreach (array_chunk(array_keys($candidates), 500) as $ids) {
            foreach (DB::table('customer_interactions')->whereIn('customer_id', $ids)->groupBy('customer_id')->selectRaw('customer_id, max(occurred_at) as last_contact')->get() as $r) {
                $lastContact[(int) $r->customer_id] = CarbonImmutable::parse($r->last_contact);
            }
        }

        $limit = $now->subDays($opts['no_contact_days']);
        $rows = [];
        foreach ($candidates as $id => [$c, $status, $last]) {
            if (isset($lastContact[$id]) && $lastContact[$id] >= $limit) {
                continue;
            }
            $rows[] = [
                'company_id' => $c->company_id, 'company' => $c->company->name, 'code' => $c->code, 'name' => $c->name,
                'phone' => $c->phone, 'whatsapp' => $c->whatsapp,
                'status' => $status, 'last_purchase' => $last->toDateString(), 'days_since_last_purchase' => (int) $last->diffInDays($today),
                'seller' => $c->seller?->name, 'last_contact_at' => isset($lastContact[$id]) ? $lastContact[$id]->toIso8601String() : null,
            ];
        }

        // Primero los inactivos (más recuperables), luego los perdidos; dentro de cada grupo, la compra más reciente.
        usort($rows, fn ($a, $b) => [$a['status'] === 'lost', $b['last_purchase']] <=> [$b['status'] === 'lost', $a['last_purchase']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function presentAction(CustomerInteraction $i, CarbonImmutable $now): array
    {
        $c = $i->customer;

        return [
            'id' => $i->id,
            'customer' => ['company_id' => $c->company_id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'whatsapp' => $c->whatsapp, 'seller' => $c->seller?->name],
            'next_action' => $i->next_action, 'next_action_at' => $i->next_action_at?->toIso8601String(),
            'days_overdue' => $i->next_action_at < $now ? (int) $i->next_action_at->startOfDay()->diffInDays($now->startOfDay()) : 0,
            'origin' => ['channel' => $i->channel, 'outcome' => $i->outcome, 'occurred_at' => $i->occurred_at->toIso8601String()],
            'user' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->name] : null,
        ];
    }
}
