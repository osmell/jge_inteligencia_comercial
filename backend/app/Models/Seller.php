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
 * @property int|null $branch_id
 * @property bool $is_default
 * @property int|null $supervisor_id
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable(['company_id', 'name', 'normalized_name', 'is_active', 'user_id', 'starts_on', 'ends_on', 'branch_id', 'is_default', 'supervisor_id'])]
class Seller extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** Misma normalización para buscar y para guardar. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    /** Crea (o reutiliza) el vendedor «Tienda <sucursal>» por defecto de una sucursal. */
    public static function ensureStoreSeller(Branch $branch): self
    {
        $name = 'Tienda '.$branch->name;
        $seller = self::firstOrCreate(
            ['company_id' => $branch->company_id, 'normalized_name' => self::normalize($name)],
            ['name' => $name, 'is_active' => true],
        );
        $seller->update(['branch_id' => $branch->id, 'is_default' => true]);

        return $seller;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
