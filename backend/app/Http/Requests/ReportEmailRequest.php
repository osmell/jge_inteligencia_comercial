<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Envío de un reporte por correo: destinatarios, mensaje y la confirmación expresa de que se revisaron. */
class ReportEmailRequest extends ReportExportRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('reports.email_max_recipients')],
            'recipients.*' => ['required', 'string', 'email:rfc', 'max:190'],
            'message' => ['nullable', 'string', 'max:1000'],
            // Quien envía confirma que revisó a quién le mandará las cifras: sin esto la API tampoco envía.
            'confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipients.max' => 'Puedes enviar a '.(int) config('reports.email_max_recipients').' destinatarios como máximo por vez.',
            'recipients.*.email' => 'La dirección «:input» no es válida.',
            'confirmed.accepted' => 'Confirma los destinatarios antes de enviar.',
        ];
    }

    /** @return list<string> direcciones en minúsculas y sin repetir */
    public function recipients(): array
    {
        return array_values(array_unique(array_map(fn (mixed $a): string => mb_strtolower(trim((string) $a)), (array) $this->validated('recipients'))));
    }

    public function note(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) ? $message : null;
    }
}
