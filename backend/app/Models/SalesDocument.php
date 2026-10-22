<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Immutable;
use App\Models\Concerns\ScopedByOrganization;
use Database\Factories\SalesDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Factura de venta (cabecera lógica). Identificador único: compañía + serie + número.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $series
 * @property string $number
 * @property Carbon $issued_on
 * @property string $customer_code
 * @property string $customer_name
 * @property int $lines_count
 * @property string $content_hash
 */
#[Fillable([
    'company_id', 'branch_id', 'series', 'number', 'issued_on', 'issued_at', 'customer_code', 'customer_name',
    'seller_id', 'operator_id', 'import_batch_id', 'lines_count', 'content_hash',
])]
class SalesDocument extends Model
{
    /** @use HasFactory<SalesDocumentFactory> */
    use HasFactory, Immutable, ScopedByOrganization;

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'issued_at' => 'datetime'];
    }

    /** @return HasMany<SalesLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesLine::class)->orderBy('line_number');
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
