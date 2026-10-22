<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotationAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.implode(',', config('quotations.attachment_mimes')), 'max:'.config('quotations.max_attachment_kb')],
        ];
    }

    public function messages(): array
    {
        $mb = round((int) config('quotations.max_attachment_kb') / 1024);

        return [
            'file.max' => "El archivo supera el límite de {$mb} MB.",
            'file.mimes' => 'Solo se admiten PDF o imágenes (JPG, PNG o WebP).',
            'file.uploaded' => "No se pudo subir el archivo; el límite es de {$mb} MB.",
        ];
    }
}
