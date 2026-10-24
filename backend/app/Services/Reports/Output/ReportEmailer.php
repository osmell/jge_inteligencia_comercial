<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\MailService;
use App\Services\Reports\ReportAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Envía un reporte por correo, como archivo adjunto, a las direcciones que la persona escribe. Es lo único irreversible de los reportes
 * (las cifras salen de la empresa), así que:
 *  - se envía un correo por destinatario (nadie ve a los demás) y cada uno queda en el registro de correos;
 *  - hay un tope de destinatarios por envío y por persona y hora, para que una cuenta comprometida no pueda vaciar los reportes;
 *  - queda en Auditoría quién lo envió, qué reporte, con qué filtros y a quién;
 *  - si el correo no está configurado, no se intenta por otra vía.
 */
class ReportEmailer
{
    public function __construct(private readonly MailService $mail, private readonly ReportFiles $files, private readonly ReportAudit $audit) {}

    /**
     * @param  list<string>  $recipients  direcciones ya validadas y sin repetir
     * @param  array<string, mixed>  $auditFilters
     * @return list<array{to: string, status: string, error: string|null}>
     */
    public function send(User $user, string $report, ReportDocument $document, string $format, array $recipients, ?string $message, CarbonImmutable $from, CarbonImmutable $to, array $auditFilters): array
    {
        if (! MailSetting::current()->ready()) {
            throw ValidationException::withMessages(['recipients' => 'El correo no está activado o configurado. Pide a administración que lo configure en Correo.']);
        }
        $this->spend($user, count($recipients));

        $file = $this->files->render($document, $format, $user, $from, $to);
        $subject = "{$document->title} · {$from->toDateString()} a {$to->toDateString()}";
        $body = implode("\n\n", array_filter([
            "{$user->name} te comparte el reporte «{$document->title}». Va adjunto en {$this->label($format)}.",
            $message !== null && trim($message) !== '' ? 'Mensaje: '.trim($message) : null,
            implode("\n", $document->lines),
            'Contiene información comercial de la empresa: no la reenvíes a quien no deba verla.',
        ]));

        $results = [];
        foreach ($recipients as $to_address) {
            $log = $this->mail->sendReport($user, $to_address, $subject, $body, $file);
            $results[] = ['to' => $to_address, 'status' => $log->status === MailLog::SENT ? 'sent' : 'failed', 'error' => $log->status === MailLog::SENT ? null : $log->error];
        }

        $this->audit->emailed($user, $report, $format, $recipients, $auditFilters + ['results' => array_column($results, 'status', 'to')]);

        return $results;
    }

    /** Cada destinatario gasta un cupo de la hora; sin cupo suficiente no se envía nada. */
    private function spend(User $user, int $count): void
    {
        $key = "report-email|{$user->id}";
        $limit = (int) config('reports.email_per_hour');
        $left = max(0, $limit - RateLimiter::attempts($key));
        if ($count > $left) {
            throw ValidationException::withMessages([
                'recipients' => $left === 0
                    ? "Alcanzaste el máximo de {$limit} correos de reportes por hora. Inténtalo más tarde."
                    : "Solo puedes enviar {$left} correo(s) más de reportes en esta hora (máximo {$limit}).",
            ])->status(429);
        }
        for ($i = 0; $i < $count; $i++) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function label(string $format): string
    {
        return $format === 'pdf' ? 'PDF' : 'Excel';
    }
}
