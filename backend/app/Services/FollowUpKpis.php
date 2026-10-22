<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del seguimiento comercial de un periodo: contactos, cumplimiento de próximas acciones, clientes nuevos y
 * recuperados, y conversión de cotizaciones por cohorte (las emitidas en el periodo).
 *
 * No se atribuye ninguna venta a un contacto ni a una cotización: «nuevos» y «recuperados» describen la cartera, no el efecto
 * del seguimiento; solo una cotización con factura vinculada cuenta como convertida.
 */
class FollowUpKpis
{
    /** Resultados que cuentan como contacto efectivo (se habló con el cliente). */
    public const EFFECTIVE = ['spoke', 'interested', 'quote_requested', 'callback_requested', 'not_interested'];

    public function __construct(private readonly CustomerActivity $activity, private readonly FollowUpScope $scope) {}

    /**
     * @param  array{company_id?: int|null, seller_id?: int|null}  $opts
     * @return array<string, mixed>
     */
    public function build(User $user, CarbonImmutable $from, CarbonImmutable $to, array $opts, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        $contacts = $this->contactRows($user, $opts, $start, $end);
        $due = $this->dueRows($user, $opts, $start, $end);
        $quotes = $this->quotationRows($user, $opts, $start, $end);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'contacts' => $this->contacts($contacts),
            'actions' => $this->actions($due, $now),
            'customers' => $this->customers($user, $opts, $start, $end),
            'quotations' => $this->quotations($quotes, $now),
            'by_user' => $this->byUser($contacts, $due, $quotes, $now),
        ];
    }

    /**
     * @param  array<string, mixed>  $opts  @return \Illuminate\Support\Collection<int, CustomerInteraction>
     * @param  array<string, mixed>  $opts
     * @return Collection<int, CustomerInteraction>
     */
    private function contactRows(User $user, array $opts, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = CustomerInteraction::query()->where('outcome', '!=', CustomerInteraction::PLANNED)->whereBetween('occurred_at', [$start, $end]);
        $this->scope->restrict($query, $user, $opts);

        return $query->get(['id', 'user_id', 'customer_id', 'channel', 'direction', 'outcome', 'occurred_at']);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return Collection<int, CustomerInteraction>
     */
    private function dueRows(User $user, array $opts, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = CustomerInteraction::query()->whereNotNull('next_action_at')->whereBetween('next_action_at', [$start, $end]);
        $this->scope->restrict($query, $user, $opts);

        return $query->get(['id', 'user_id', 'customer_id', 'next_action_at', 'next_action_done_at']);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return Collection<int, Quotation>
     */
    private function quotationRows(User $user, array $opts, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = Quotation::query()->whereNotNull('issued_at')->whereBetween('issued_at', [$start, $end]);
        $this->scope->restrict($query, $user, $opts);

        return $query->get(['id', 'user_id', 'customer_id', 'status', 'amount', 'valid_until', 'issued_at', 'converted_at', 'loss_reason']);
    }

    /**
     * @param  Collection<int, CustomerInteraction>  $rows
     * @return array<string, mixed>
     */
    private function contacts($rows): array
    {
        $effective = $rows->filter(fn (CustomerInteraction $i) => in_array($i->outcome, self::EFFECTIVE, true))->count();

        return [
            'total' => $rows->count(),
            'outbound' => $rows->where('direction', 'outbound')->count(),
            'inbound' => $rows->where('direction', 'inbound')->count(),
            'customers' => $rows->pluck('customer_id')->unique()->count(),
            'effective' => $effective,
            'effective_pct' => $rows->count() > 0 ? round($effective / $rows->count() * 100, 1) : null,
            'by_channel' => $rows->countBy('channel')->all(),
            'by_outcome' => $rows->countBy('outcome')->all(),
        ];
    }

    /**
     * Acciones con fecha dentro del periodo. «Vencidas» = ya pasó su día; cumplida a tiempo = cerrada hasta el final de su día.
     *
     * @param  Collection<int, CustomerInteraction>  $rows
     * @return array<string, mixed>
     */
    private function actions($rows, CarbonImmutable $now): array
    {
        $count = $this->classifyActions($rows, $now);
        $due = $count['on_time'] + $count['late'] + $count['open_overdue'];

        return $count + [
            'due' => $due,
            'on_time_pct' => $due > 0 ? round($count['on_time'] / $due * 100, 1) : null,
            'completed_pct' => $due > 0 ? round(($count['on_time'] + $count['late']) / $due * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, CustomerInteraction>  $rows
     * @return array{on_time: int, late: int, open_overdue: int, upcoming: int}
     */
    private function classifyActions($rows, CarbonImmutable $now): array
    {
        $out = ['on_time' => 0, 'late' => 0, 'open_overdue' => 0, 'upcoming' => 0];
        foreach ($rows as $i) {
            $dueDay = $i->next_action_at?->endOfDay();
            if ($i->next_action_done_at !== null) {
                $out[$i->next_action_done_at <= $dueDay ? 'on_time' : 'late']++;
            } elseif ($i->next_action_at < $now) {
                $out['open_overdue']++;
            } else {
                $out['upcoming']++;
            }
        }

        return $out;
    }

    /**
     * Clientes nuevos (primera compra en el periodo, dentro del historial cargado) y recuperados (compraron en el periodo tras
     * más días sin comprar que el plazo de «inactivo»). Describe la cartera: no se atribuye al seguimiento.
     *
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    private function customers(User $user, array $opts, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $visible = Customer::query()->tap(fn (Builder $q) => $this->scope->filterCustomers($q, $opts))
            ->when($this->scope->isSupervisor($user), fn (Builder $q) => $this->scope->team($q, $user))
            ->get(['company_id', 'code'])->mapWithKeys(fn (Customer $c) => ["{$c->company_id}|{$c->code}" => true])->all();

        $rules = $this->activity->rules();
        $starts = $this->activity->historyStarts();

        $days = DB::table('sales_documents')->select('company_id', 'customer_code as code', 'issued_on')->distinct()
            ->when(! empty($opts['company_id']), fn ($q) => $q->where('company_id', $opts['company_id']));
        $ranked = DB::query()->fromSub($days, 'd')
            ->selectRaw('company_id, code, issued_on, lag(issued_on) over (partition by company_id, code order by issued_on) as prev, min(issued_on) over (partition by company_id, code) as first');
        $rows = DB::query()->fromSub($ranked, 'r')->where('issued_on', '>=', $start->toDateString())->where('issued_on', '<=', $end->toDateString())->get();

        $new = [];
        $recovered = [];
        foreach ($rows as $r) {
            $key = "{$r->company_id}|{$r->code}";
            if (! isset($visible[$key])) {
                continue;
            }
            $first = CarbonImmutable::parse($r->first)->startOfDay();
            $history = $starts[(int) $r->company_id] ?? null;
            if ($first >= $start && $first <= $end && $history !== null && $first->greaterThan($history)) {
                $new[$key] = true;
            } elseif ($r->prev !== null && (int) CarbonImmutable::parse($r->prev)->startOfDay()->diffInDays(CarbonImmutable::parse($r->issued_on)->startOfDay()) > $rules['inactive_days']) {
                $recovered[$key] = true;
            }
        }

        return ['new' => count($new), 'recovered' => count(array_diff_key($recovered, $new)), 'inactive_days' => $rules['inactive_days']];
    }

    /**
     * Cohorte: cotizaciones emitidas en el periodo. Conversión = convertidas (con factura vinculada) / emitidas; las que siguen abiertas
     * se muestran aparte porque la tasa todavía puede subir.
     *
     * @param  Collection<int, Quotation>  $rows
     * @return array<string, mixed>
     */
    private function quotations($rows, CarbonImmutable $now): array
    {
        $by = ['converted' => 0, 'accepted' => 0, 'rejected' => 0, 'cancelled' => 0, 'expired' => 0, 'open' => 0];
        $amount = ['issued' => 0.0, 'converted' => 0.0];
        $days = [];
        $reasons = [];
        foreach ($rows as $q) {
            $amount['issued'] += (float) $q->amount;
            $state = $q->effectiveStatus($now);
            if ($state === 'converted') {
                $amount['converted'] += (float) $q->amount;
                if ($q->converted_at !== null && $q->issued_at !== null) {
                    $days[] = $q->issued_at->diffInDays($q->converted_at, true);
                }
            }
            if ($state === 'rejected' && $q->loss_reason !== null) {
                $reasons[$q->loss_reason] = ($reasons[$q->loss_reason] ?? 0) + 1;
            }
            $key = match ($state) {
                'issued', 'sent', 'following' => 'open',
                default => $state,
            };
            $by[$key] = ($by[$key] ?? 0) + 1;
        }
        $issued = $rows->count();

        return [
            'issued' => $issued,
            'issued_amount' => number_format($amount['issued'], 2, '.', ''),
            'converted' => $by['converted'],
            'converted_amount' => number_format($amount['converted'], 2, '.', ''),
            'accepted_without_invoice' => $by['accepted'],
            'rejected' => $by['rejected'],
            'cancelled' => $by['cancelled'],
            'expired' => $by['expired'],
            'open' => $by['open'],
            'conversion_pct' => $issued > 0 ? round($by['converted'] / $issued * 100, 1) : null,
            'amount_conversion_pct' => $amount['issued'] > 0 ? round($amount['converted'] / $amount['issued'] * 100, 1) : null,
            'avg_days_to_convert' => $days === [] ? null : round(array_sum($days) / count($days), 1),
            'loss_reasons' => $reasons,
        ];
    }

    /**
     * @param  Collection<int, CustomerInteraction>  $contacts
     * @param  Collection<int, CustomerInteraction>  $due
     * @param  Collection<int, Quotation>  $quotes
     * @return list<array<string, mixed>>
     */
    private function byUser($contacts, $due, $quotes, CarbonImmutable $now): array
    {
        $ids = $contacts->pluck('user_id')->merge($due->pluck('user_id'))->merge($quotes->pluck('user_id'))->unique()->filter()->values();
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $rows = [];
        foreach ($ids as $id) {
            $mine = $contacts->where('user_id', $id);
            $actions = $this->actions($due->where('user_id', $id), $now);
            $issued = $quotes->where('user_id', $id);
            $converted = $issued->filter(fn (Quotation $q) => $q->status === 'converted')->count();
            $rows[] = [
                'user' => ['id' => (int) $id, 'name' => (string) ($names[$id] ?? '—')],
                'contacts' => $mine->count(),
                'customers' => $mine->pluck('customer_id')->unique()->count(),
                'effective' => $mine->filter(fn (CustomerInteraction $i) => in_array($i->outcome, self::EFFECTIVE, true))->count(),
                'actions_due' => $actions['due'],
                'actions_completed' => $actions['on_time'] + $actions['late'],
                'actions_open_overdue' => $actions['open_overdue'],
                'compliance_pct' => $actions['completed_pct'],
                'quotations_issued' => $issued->count(),
                'quotations_converted' => $converted,
                'conversion_pct' => $issued->count() > 0 ? round($converted / $issued->count() * 100, 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['contacts'], $a['user']['name']] <=> [$a['contacts'], $b['user']['name']]);

        return $rows;
    }
}
