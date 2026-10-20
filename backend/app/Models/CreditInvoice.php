<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Factura de crédito (la misma factura de venta). Su saldo no se guarda: es la suma de sus movimientos en el libro.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $series
 * @property string $number
 * @property Carbon $issued_on
 * @property string $customer_code
 * @property string $customer_name
 * @property int $seller_id
 * @property int $operator_id
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property int|null $sales_document_id
 */
#[Fillable(['company_id', 'branch_id', 'series', 'number', 'issued_on', 'customer_code', 'customer_name', 'seller_id', 'operator_id', 'subtotal', 'discount_total', 'tax_total', 'total', 'sales_document_id', 'import_batch_id', 'content_hash'])]
class CreditInvoice extends Model
{
    use Immutable, ScopedByOrganization;

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4'];
    }

    /** Un usuario con rol Vendedor solo ve las facturas de crédito que le corresponden como vendedor. */
    protected static function booted(): void
    {
        static::addGlobalScope('own_invoices_for_sellers', function (Builder $query) {
            $user = Auth::user();
            if ($user instanceof User && $user->role->slug === Role::VENDEDOR) {
                $query->whereIn('credit_invoices.seller_id', DB::table('sellers')->where('user_id', $user->id)->select('id'));
            }
        });
    }

    public function documentNumber(): string
    {
        return "{$this->series}-{$this->number}";
    }

    /** @return HasMany<CreditMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CreditMovement::class)->orderBy('occurred_on')->orderBy('id');
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
