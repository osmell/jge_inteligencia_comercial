<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Resumen para gerencia de una compañía y un periodo (inmutable: guarda las cifras tal como estaban al generarse).
 *
 * @property int $id
 * @property int $company_id
 * @property string $kind
 * @property string $period_key
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string $title
 * @property array<string, mixed> $data
 * @property Carbon $generated_at
 */
class Digest extends Model
{
    use Immutable;

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'data' => 'array', 'generated_at' => 'datetime'];
    }
}
