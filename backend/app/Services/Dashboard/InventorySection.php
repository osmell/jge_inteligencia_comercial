<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\InventoryRotation;

/** Sección «inventory» del dashboard inicial. */
final class InventorySection extends DashboardSection
{
    public function __construct(private readonly InventoryRotation $rotation) {}

    public function key(): string
    {
        return 'inventory';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->counts($context->user, $context->companyId);
    }

    /** @return array<string, mixed> */
    private function counts(User $user, ?int $companyId): array
    {
        $counts = $this->rotation->report($user, $companyId === null ? [] : ['company_id' => $companyId])['counts'];

        return ['out_of_stock' => $counts['out_of_stock'], 'below_min' => $counts['below_min'], 'no_rotation' => $counts['no_rotation'], 'above_max' => $counts['above_max']];
    }
}
