<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Estado de actividad de un cliente según sus días con compra. Reglas (días configurables):
 *  - sin compras: nunca ha comprado en las ventas importadas;
 *  - nuevo: su primera compra fue hace ≤ «días de inactividad» (solo si el historial de la compañía empieza antes: si no, no se sabe si es la primera);
 *  - recuperado: compró hace ≤ «días de inactividad» tras pasar más de ese plazo sin comprar;
 *  - activo: última compra hace ≤ «días de inactividad»;
 *  - inactivo: más de ese plazo sin comprar, hasta «días para perdido»;
 *  - perdido: más de «días para perdido» sin comprar.
 * Se calcula sobre toda la compañía (sin importar las sucursales del usuario) para que el estado sea el mismo para todos.
 */
class CustomerActivity
{
    public const STATUSES = ['new', 'recovered', 'active', 'inactive', 'lost', 'no_sales'];

    public const DEFAULT_INACTIVE_DAYS = 30;

    public const DEFAULT_LOST_DAYS = 90;

    private const KEY_INACTIVE = 'customers.inactive_days';

    private const KEY_LOST = 'customers.lost_days';

    /** @return array{inactive_days: int, lost_days: int} */
    public function rules(): array
    {
        $values = AppSetting::query()->whereIn('key', [self::KEY_INACTIVE, self::KEY_LOST])->pluck('value', 'key');

        return [
            'inactive_days' => (int) ($values[self::KEY_INACTIVE] ?? self::DEFAULT_INACTIVE_DAYS),
            'lost_days' => (int) ($values[self::KEY_LOST] ?? self::DEFAULT_LOST_DAYS),
        ];
    }

    /** @return array{inactive_days: int, lost_days: int} */
    public function saveRules(User $user, int $inactiveDays, int $lostDays, AuditLogger $audit): array
    {
        $old = $this->rules();
        $new = ['inactive_days' => $inactiveDays, 'lost_days' => $lostDays];
        AppSetting::updateOrCreate(['key' => self::KEY_INACTIVE], ['value' => (string) $inactiveDays]);
        AppSetting::updateOrCreate(['key' => self::KEY_LOST], ['value' => (string) $lostDays]);
        if ($old !== $new) {
            $audit->record('customers.activity_rules_updated', $user, old: $old, new: $new);
        }

        return $new;
    }

    /**
     * Fechas clave de cada cliente con compras: primera, última y la compra anterior a la última.
     * Opcionalmente acotado a una compañía y/o a una lista de códigos.
     *
     * @param  list<string>|null  $codes
     * @return array<int, array<string, array{first: CarbonImmutable, last: CarbonImmutable, prev: CarbonImmutable|null}>> compañía → código → fechas
     */
    public function facts(?int $companyId = null, ?array $codes = null): array
    {
        $days = DB::table('sales_documents')
            ->when($companyId, fn (Builder $q) => $q->where('company_id', $companyId))
            ->when($codes !== null, fn (Builder $q) => $q->whereIn('customer_code', $codes))
            ->select('company_id', 'customer_code as code', 'issued_on')
            ->distinct();

        $ranked = DB::query()->fromSub($days, 'd')
            ->selectRaw('company_id, code, issued_on as last')
            ->selectRaw('lag(issued_on) over (partition by company_id, code order by issued_on) as prev')
            ->selectRaw('min(issued_on) over (partition by company_id, code) as first')
            ->selectRaw('row_number() over (partition by company_id, code order by issued_on desc) as rn');

        $result = [];
        foreach (DB::query()->fromSub($ranked, 'r')->where('rn', 1)->get() as $r) {
            $result[(int) $r->company_id][(string) $r->code] = [
                'first' => CarbonImmutable::parse($r->first)->startOfDay(),
                'last' => CarbonImmutable::parse($r->last)->startOfDay(),
                'prev' => $r->prev ? CarbonImmutable::parse($r->prev)->startOfDay() : null,
            ];
        }

        return $result;
    }

    /** Primera venta de cada compañía (histórico completo): lo anterior no está en el sistema. */
    public function historyStarts(): array
    {
        return DB::table('sales_documents')->groupBy('company_id')->selectRaw('company_id, min(issued_on) as starts')->pluck('starts', 'company_id')
            ->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->all();
    }

    /**
     * @param  array{first: CarbonImmutable, last: CarbonImmutable, prev: CarbonImmutable|null}|null  $facts
     * @param  array{inactive_days: int, lost_days: int}  $rules
     */
    public function classify(?array $facts, ?CarbonImmutable $historyStart, CarbonImmutable $today, array $rules): string
    {
        if ($facts === null) {
            return 'no_sales';
        }
        $sinceLast = (int) $facts['last']->diffInDays($today, false);
        if ($sinceLast > $rules['lost_days']) {
            return 'lost';
        }
        if ($sinceLast > $rules['inactive_days']) {
            return 'inactive';
        }
        $sinceFirst = (int) $facts['first']->diffInDays($today, false);
        if ($sinceFirst <= $rules['inactive_days'] && $historyStart !== null && $facts['first']->greaterThan($historyStart)) {
            return 'new';
        }
        if ($facts['prev'] !== null && (int) $facts['prev']->diffInDays($facts['last']) > $rules['inactive_days']) {
            return 'recovered';
        }

        return 'active';
    }

    /**
     * Estado de cada cliente (compañía → código → estado) para una lista de pares ya conocidos.
     *
     * @param  list<string>  $codes
     * @return array<int, array<string, string>>
     */
    public function statusesFor(int $companyId, array $codes, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rules = $this->rules();
        $facts = $this->facts($companyId, $codes)[$companyId] ?? [];
        $start = $this->historyStarts()[$companyId] ?? null;

        return [$companyId => collect($codes)->mapWithKeys(fn ($c) => [$c => $this->classify($facts[$c] ?? null, $start, $today, $rules)])->all()];
    }

    /**
     * Códigos de los clientes en un estado, por compañía (para filtrar el listado). Para «sin compras» devuelve los que SÍ
     * tienen compras (el llamador los excluye).
     *
     * @return array<int, list<string>>
     */
    public function codesWithStatus(string $status, ?int $companyId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rules = $this->rules();
        $starts = $this->historyStarts();
        $out = [];
        foreach ($this->facts($companyId) as $company => $byCode) {
            foreach ($byCode as $code => $f) {
                if ($status === 'no_sales' || $this->classify($f, $starts[$company] ?? null, $today, $rules) === $status) {
                    $out[$company][] = (string) $code;
                }
            }
        }

        return $out;
    }
}
