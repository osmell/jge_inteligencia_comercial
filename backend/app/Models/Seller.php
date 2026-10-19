<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_active
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable(['company_id', 'name', 'normalized_name', 'is_active', 'user_id', 'starts_on', 'ends_on'])]
class Seller extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** Misma normalización para buscar y para guardar. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
