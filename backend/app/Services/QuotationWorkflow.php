<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\QuotationEvent;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de las cotizaciones: numeración propia por compañía, transiciones de estado, vínculo explícito con la factura
 * y adjunto. Una cotización aceptada no es una venta: solo se convierte al vincular un documento de venta válido.
 */
class QuotationWorkflow
{
    private const AUDITED = ['external_reference', 'status', 'amount', 'valid_until', 'description', 'loss_reason', 'loss_note', 'sales_document_id'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{amount?: mixed, valid_until?: mixed, description?: mixed, external_reference?: mixed, status?: string}  $data */
    public function create(User $user, Customer $customer, array $data): Quotation
    {
        $status = $data['status'] ?? 'draft';
        unset($data['status']);

        $quotation = DB::transaction(function () use ($user, $customer, $data) {
            // Se bloquea la compañía para que dos altas simultáneas no tomen el mismo consecutivo.
            Company::query()->whereKey($customer->company_id)->lockForUpdate()->first();
            $sequence = (int) Quotation::withoutGlobalScopes()->where('company_id', $customer->company_id)->max('sequence') + 1;

            return Quotation::create($data + [
                'company_id' => $customer->company_id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'seller_id' => $customer->seller_id,
                'sequence' => $sequence, 'number' => 'COT-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT), 'status' => 'draft', 'amount' => $data['amount'] ?? 0,
            ]);
        });
        $this->event($quotation, $user, null, 'draft');
        $this->audit->record('quotations.created', $user, auditable: $quotation, new: $quotation->only(self::AUDITED) + ['number' => $quotation->number, 'customer_id' => $customer->id], companyId: $quotation->company_id);

        return $status === 'draft' ? $quotation : $this->changeStatus($quotation, $user, $status);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Quotation $quotation, User $user, array $data): Quotation
    {
        $this->assertOpen($quotation, 'La cotización ya está cerrada y no se puede editar.');
        $before = $quotation->only(self::AUDITED);
        $quotation->fill($data);
        if ($quotation->status !== 'draft') {
            $this->assertIssuable($quotation);
        }
        $quotation->save();

        if ($quotation->wasChanged(array_keys($data))) {
            $this->audit->record('quotations.updated', $user, auditable: $quotation, old: $before, new: $quotation->only(self::AUDITED), companyId: $quotation->company_id);
        }

        return $quotation;
    }

    public function changeStatus(Quotation $quotation, User $user, string $to, ?string $lossReason = null, ?string $lossNote = null, ?string $note = null): Quotation
    {
        $from = $quotation->status;
        if (! in_array($to, Quotation::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'No se puede pasar la cotización de «'.$from.'» a «'.$to.'».']);
        }

        if ($to === 'issued') {
            $this->assertIssuable($quotation);
            if ($quotation->valid_until->lt(now()->startOfDay())) {
                throw ValidationException::withMessages(['valid_until' => 'La fecha de vencimiento ya pasó.']);
            }
        }
        if ($to === 'sent' && $quotation->customer->do_not_contact) {
            throw ValidationException::withMessages(['status' => 'El cliente pidió no recibir comunicaciones: la cotización no se puede enviar.']);
        }
        if ($to === 'rejected' && $lossReason === null) {
            throw ValidationException::withMessages(['loss_reason' => 'Indica el motivo por el que se perdió la cotización.']);
        }

        $before = $quotation->only(self::AUDITED);
        $quotation->status = $to;
        if ($to === 'issued') {
            $quotation->issued_at = now();
        }
        if (in_array($to, ['rejected', 'cancelled'], true)) {
            $quotation->loss_reason = $lossReason;
            $quotation->loss_note = $lossNote;
        }
        $quotation->save();
        $this->event($quotation, $user, $from, $to, $note ?? $lossNote);
        $this->audit->record('quotations.status_changed', $user, auditable: $quotation, old: $before, new: $quotation->only(self::AUDITED), companyId: $quotation->company_id);

        return $quotation;
    }

    /** Vincula la factura que respalda la venta: es lo único que convierte la cotización en venta. */
    public function link(Quotation $quotation, User $user, SalesDocument $document): Quotation
    {
        if (! in_array($quotation->status, Quotation::LINKABLE, true)) {
            throw ValidationException::withMessages(['document_id' => 'Solo se vincula una factura a una cotización emitida, enviada, en seguimiento o aceptada.']);
        }
        if ($document->company_id !== $quotation->company_id || $document->customer_code !== $quotation->customer->code) {
            throw ValidationException::withMessages(['document_id' => 'La factura debe ser de la misma compañía y del mismo cliente de la cotización.']);
        }
        if ($quotation->issued_at !== null && $document->issued_on->lt($quotation->issued_at->copy()->startOfDay())) {
            throw ValidationException::withMessages(['document_id' => 'La factura es anterior a la emisión de la cotización.']);
        }
        if (Quotation::withoutGlobalScopes()->where('sales_document_id', $document->id)->exists()) {
            throw ValidationException::withMessages(['document_id' => 'Esa factura ya está vinculada a otra cotización.']);
        }

        $from = $quotation->status;
        $before = $quotation->only(self::AUDITED);
        $quotation->forceFill(['sales_document_id' => $document->id, 'status' => 'converted', 'converted_at' => now()])->save();
        $this->event($quotation, $user, $from, 'converted', "{$document->series}-{$document->number}");
        $this->audit->record('quotations.linked', $user, auditable: $quotation, old: $before, new: $quotation->only(self::AUDITED), companyId: $quotation->company_id);

        return $quotation;
    }

    /** Corrige un vínculo equivocado: la cotización vuelve a «Aceptada». */
    public function unlink(Quotation $quotation, User $user): Quotation
    {
        if ($quotation->status !== 'converted') {
            throw ValidationException::withMessages(['document_id' => 'La cotización no está vinculada a una factura.']);
        }
        $before = $quotation->only(self::AUDITED);
        $quotation->forceFill(['sales_document_id' => null, 'status' => 'accepted', 'converted_at' => null])->save();
        $this->event($quotation, $user, 'converted', 'accepted', 'Vínculo con la factura retirado');
        $this->audit->record('quotations.unlinked', $user, auditable: $quotation, old: $before, new: $quotation->only(self::AUDITED), companyId: $quotation->company_id);

        return $quotation;
    }

    public function attach(Quotation $quotation, User $user, UploadedFile $file): Quotation
    {
        $this->assertOpen($quotation, 'La cotización ya está cerrada: no se pueden cambiar sus adjuntos.');
        $previous = $quotation->attachment_path;
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = Storage::disk('quotations')->putFileAs((string) $quotation->id, $file, bin2hex(random_bytes(8)).'.'.$extension);

        $quotation->forceFill([
            'attachment_path' => $path, 'attachment_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'attachment_mime' => $file->getMimeType(), 'attachment_size' => $file->getSize(),
        ])->save();
        if ($previous !== null) {
            Storage::disk('quotations')->delete($previous);
        }
        $this->audit->record('quotations.attachment_added', $user, auditable: $quotation, new: ['name' => $quotation->attachment_name, 'size' => $quotation->attachment_size], companyId: $quotation->company_id);

        return $quotation;
    }

    public function detach(Quotation $quotation, User $user): Quotation
    {
        $this->assertOpen($quotation, 'La cotización ya está cerrada: no se pueden cambiar sus adjuntos.');
        if ($quotation->attachment_path !== null) {
            Storage::disk('quotations')->delete($quotation->attachment_path);
            $this->audit->record('quotations.attachment_removed', $user, auditable: $quotation, old: ['name' => $quotation->attachment_name], companyId: $quotation->company_id);
            $quotation->forceFill(['attachment_path' => null, 'attachment_name' => null, 'attachment_mime' => null, 'attachment_size' => null])->save();
        }

        return $quotation;
    }

    private function assertOpen(Quotation $quotation, string $message): void
    {
        if (in_array($quotation->status, Quotation::TERMINAL, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /** Para emitir (y para editar una ya emitida) se necesita importe y vencimiento. */
    private function assertIssuable(Quotation $quotation): void
    {
        $errors = [];
        if ((float) $quotation->amount <= 0) {
            $errors['amount'] = 'El importe debe ser mayor que cero.';
        }
        if ($quotation->valid_until === null) {
            $errors['valid_until'] = 'Indica hasta cuándo es válida la cotización.';
        } elseif ($quotation->issued_at !== null && $quotation->valid_until->lt($quotation->issued_at->copy()->startOfDay())) {
            $errors['valid_until'] = 'El vencimiento no puede ser anterior a la emisión.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function event(Quotation $quotation, User $user, ?string $from, string $to, ?string $note = null): void
    {
        QuotationEvent::create(['quotation_id' => $quotation->id, 'user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);
    }
}
