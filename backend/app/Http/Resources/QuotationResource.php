<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cotización en un listado. Requiere cargados `customer`, `user`, `seller` y `document`.
 *
 * @mixin Quotation
 */
class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'external_reference' => $this->external_reference,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus(),
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'valid_until' => $this->valid_until?->toDateString(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'customer' => [
                'company_id' => $this->company_id,
                'code' => $this->customer?->code,
                'name' => $this->customer?->name,
                'do_not_contact' => $this->customer?->do_not_contact,
            ],
            'seller' => $this->seller?->name,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'has_attachment' => $this->attachment_path !== null,
            'document' => $this->document ? [
                'id' => $this->document->id,
                'number' => "{$this->document->series}-{$this->document->number}",
                'issued_on' => $this->document->issued_on->toDateString(),
            ] : null,
            'loss_reason' => $this->loss_reason,
        ];
    }
}
