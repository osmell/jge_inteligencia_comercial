<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cotización a un cliente. «Aceptada» no es una venta: solo pasa a «Convertida en venta» al vincularla con una factura.
 * «Vencida» no se guarda: se deduce de la fecha de vencimiento mientras la cotización siga abierta.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property int|null $user_id
 * @property int|null $seller_id
 * @property int $sequence
 * @property string $number
 * @property string|null $external_reference
 * @property string $status
 * @property string $amount
 * @property Carbon|null $valid_until
 * @property string|null $description
 * @property Carbon|null $issued_at
 * @property string|null $loss_reason
 * @property string|null $loss_note
 * @property int|null $sales_document_id
 * @property Carbon|null $converted_at
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 */
#[Fillable(['company_id', 'customer_id', 'user_id', 'seller_id', 'sequence', 'number', 'external_reference', 'status', 'amount', 'valid_until', 'description', 'issued_at', 'loss_reason', 'loss_note', 'sales_document_id', 'converted_at', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'])]
class Quotation extends Model
{
    use ScopedByOrganization;

    public const STATUSES = ['draft', 'issued', 'sent', 'following', 'accepted', 'converted', 'rejected', 'cancelled'];

    /** Estado que se muestra pero no se guarda. */
    public const EXPIRED = 'expired';

    /** Solo estas cotizaciones pueden vencer; una aceptada espera su factura. */
    public const EXPIRABLE = ['issued', 'sent', 'following'];

    /** Estados desde los que se puede vincular una factura. */
    public const LINKABLE = ['issued', 'sent', 'following', 'accepted'];

    public const TERMINAL = ['converted', 'rejected', 'cancelled'];

    /** «Convertida» solo se alcanza vinculando una factura. */
    public const TRANSITIONS = [
        'draft' => ['issued', 'cancelled'],
        'issued' => ['sent', 'following', 'accepted', 'rejected', 'cancelled'],
        'sent' => ['following', 'accepted', 'rejected', 'cancelled'],
        'following' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['rejected', 'cancelled'],
        'converted' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public const LOSS_REASONS = ['price', 'competitor', 'no_budget', 'no_response', 'availability', 'other'];

    /** Los clientes de la compañía, no por sucursal (igual que el cliente). */
    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'issued_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    public function effectiveStatus(?CarbonInterface $today = null): string
    {
        $today ??= Carbon::today();
        if (in_array($this->status, self::EXPIRABLE, true) && $this->valid_until !== null && $this->valid_until->lt($today->startOfDay())) {
            return self::EXPIRED;
        }

        return $this->status;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /** @return HasMany<QuotationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(QuotationEvent::class)->orderBy('created_at')->orderBy('id');
    }
}
