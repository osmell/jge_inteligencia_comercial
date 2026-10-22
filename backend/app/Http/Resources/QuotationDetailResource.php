<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cotización completa: lo del listado (`QuotationResource`) más descripción, adjunto, transiciones posibles e historial de estados.
 * Los campos del listado se repiten aquí a propósito: el generador de la documentación de la API (Scramble) solo entiende un
 * `toArray` escrito como un arreglo literal; si se cambia uno, hay que cambiar el otro (lo cubre `QuotationsTest`).
 *
 * @mixin Quotation
 */
class QuotationDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['customer', 'user', 'seller', 'document', 'events.user']);

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
            'description' => $this->description,
            'loss_note' => $this->loss_note,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'attachment' => $this->attachment_path ? ['name' => $this->attachment_name, 'mime' => $this->attachment_mime, 'size' => $this->attachment_size] : null,
            'transitions' => array_values(array_diff(Quotation::TRANSITIONS[$this->status] ?? [], ['draft'])),
            'events' => $this->events->map(fn ($e) => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'note' => $e->note,
                'at' => $e->created_at->toIso8601String(),
                'user' => $e->user?->name,
            ])->all(),
        ];
    }
}
