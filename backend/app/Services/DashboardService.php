<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\SalesLine;
use App\Models\User;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del dashboard inicial. Solo reúne lo que ya calculan los demás servicios, con el alcance de quien lo pide:
 * el vendedor ve lo de sus clientes y ventas; el supervisor, lo de los vendedores que supervisa; el resto, todo su ámbito de
 * compañías. Cada sección vive en su propia clase (`App\Services\Dashboard`), exige el mismo permiso que su pantalla de origen y se
 * calcula por separado.
 */
class DashboardService
{
    public const PERIODS = ['month', 'prev_month', 'quarter', 'year'];

    /** @param  iterable<DashboardSection>  $sections  en el orden del dashboard (ver `AppServiceProvider`) */
    public function __construct(private readonly iterable $sections, private readonly DataScope $scope) {}

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
        $keys = [];
        foreach ($this->sections as $section) {
            if ($user->hasPermission($section->permission()) && $section->availableFor($user, $profile)) {
                $keys[] = $section->key();
            }
        }

        return $keys;
    }

    /** @return list<string> nombres de los vendedores de quien supervisa o vendedor vinculado a la persona (vacío para el resto) */
    public function teamNames(User $user): array
    {
        $ids = $this->profile($user)['seller_ids'];

        return $ids === null ? [] : array_values(DB::table('sellers')->whereIn('id', $ids)->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all());
    }

    /** @return list<int> compañías del ámbito de la persona (para el selector) */
    public function companyIds(User $user): array
    {
        return $this->scope->companyIds($user) ?? array_values(DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all());
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
        $filters = ['company_id' => $companyId] + ($p['seller_ids'] === null ? [] : ['seller_ids' => $p['seller_ids']]);
        $context = new DashboardContext($user, $p['profile'], $p['seller_ids'], $this->period($period, $today), $filters, $companyId, $today);

        foreach ($this->sections as $section) {
            if ($section->key() === $key) {
                return ['key' => $key, 'profile' => $p['profile']] + $section->build($context);
            }
        }

        return null;
    }

    /**
     * Fecha de la última venta visible y de la última carga de ventas.
     *
     * @return array{last_sale: string|null, last_import: string|null}
     */
    public function freshness(): array
    {
        $last = SalesLine::query()->max('issued_on');
        $import = ImportBatch::query()->where('type', 'sales')->where('status', 'imported')->max('created_at');

        return ['last_sale' => $last === null ? null : CarbonImmutable::parse($last)->toDateString(), 'last_import' => $import === null ? null : CarbonImmutable::parse($import)->toIso8601String()];
    }

    /** @return list<int> */
    private function ids(QueryBuilder $query): array
    {
        return array_values($query->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
