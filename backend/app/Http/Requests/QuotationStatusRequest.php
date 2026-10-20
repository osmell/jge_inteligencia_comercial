<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // «Convertida» solo se alcanza vinculando una factura; «vencida» se deduce de la fecha.
            'status' => ['required', Rule::in(array_diff(Quotation::STATUSES, ['draft', 'converted']))],
            'loss_reason' => ['nullable', Rule::in(Quotation::LOSS_REASONS)],
            'loss_note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
