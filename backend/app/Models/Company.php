<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedByOrganization;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'is_active'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, HasFactory, ScopedByOrganization;

    public static function auditPrefix(): string
    {
        return 'company';
    }

    public function auditCompanyId(): ?int
    {
        return $this->id;
    }

    public static function organizationCompanyColumn(): string
    {
        return 'id';
    }

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
