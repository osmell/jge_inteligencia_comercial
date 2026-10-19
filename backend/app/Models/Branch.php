<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['company_id', 'code', 'name', 'is_active'])]
class Branch extends Model
{
    use Auditable, HasFactory, ScopedByOrganization;

    public static function auditPrefix(): string
    {
        return 'branch';
    }

    public function auditCompanyId(): ?int
    {
        return $this->company_id;
    }

    public static function organizationBranchColumn(): ?string
    {
        return 'id';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
