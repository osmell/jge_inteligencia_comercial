<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Immutable;
use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un cálculo de inteligencia comercial (inmutable): análisis, compañía, periodo, parámetros usados y resultado.
 *
 * @property int $id
 * @property int $company_id
 * @property string $analysis
 * @property string $status
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property array<string, mixed> $parameters
 * @property array<string, mixed>|null $summary
 * @property string|null $note
 * @property Carbon $computed_at
 */
#[Fillable(['company_id', 'analysis', 'status', 'period_from', 'period_to', 'parameters', 'summary', 'note', 'user_id', 'computed_at'])]
class InsightRun extends Model
{
    use Immutable, ScopedByOrganization;

    public const OK = 'ok';

    public const INSUFFICIENT = 'insufficient';

    public $timestamps = false;

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'computed_at' => 'datetime', 'parameters' => 'array', 'summary' => 'array'];
    }
}
