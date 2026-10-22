<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_active
 */
#[Fillable(['company_id', 'name', 'normalized_name', 'is_active'])]
class Operator extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Misma normalización para buscar y para guardar. */
    public static function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
