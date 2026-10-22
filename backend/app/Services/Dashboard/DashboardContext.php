<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;

/** Lo que cada sección necesita saber de la petición: quién la pide, su perfil, el periodo y los filtros de alcance. */
final class DashboardContext
{
    /**
     * @param  list<int>|null  $sellerIds  vendedores visibles para la persona (null = sin restricción de vendedor)
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, label: string, previous: array{0: CarbonImmutable, 1: CarbonImmutable}, year_ago: array{0: CarbonImmutable, 1: CarbonImmutable}|null}  $window
     * @param  array<string, mixed>  $filters  compañía y vendedores, listos para `SalesStatistics`
     */
    public function __construct(
        public readonly User $user,
        public readonly string $profile,
        public readonly ?array $sellerIds,
        public readonly array $window,
        public readonly array $filters,
        public readonly ?int $companyId,
        public readonly CarbonImmutable $today,
    ) {}
}
