<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\AppMail;
use App\Models\Digest;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Correo del sistema. La configuración del servidor vive en la base de datos (no en archivos), con la contraseña cifrada; cada correo
 * queda en un registro con su resultado. Reglas: si el correo no está activado o configurado, los correos se registran como «no
 * enviados» (nunca se pierden en silencio ni salen por otra vía); un correo se identifica por su clave de deduplicación, así que un
 * reintento no lo manda dos veces al mismo destinatario; los envíos fallidos se reintentan con espera creciente y luego se rinden.
 */
class MailService
{
    public const MAX_ATTEMPTS = 3;

    /** Minutos de espera antes de cada reintento. */
    private const BACKOFF = [10, 60];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> configuración sin la contraseña */
    public function describe(): array
    {
        $s = MailSetting::current();

        return [
            'enabled' => $s->enabled, 'host' => $s->host, 'port' => $s->port, 'encryption' => $s->encryption, 'username' => $s->username, 'has_password' => $s->password !== null && $s->password !== '',
            'from_address' => $s->from_address, 'from_name' => $s->from_name, 'ready' => $s->ready(), 'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  password vacío o ausente = conservar la actual
     */
    public function save(User $user, array $data): void
    {
        $current = MailSetting::current();
        $before = $this->describe();
        $password = array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '' ? (string) $data['password'] : $current->password;
        if ($data['enabled'] && (empty($data['host']) || empty($data['from_address']))) {
            throw ValidationException::withMessages(['host' => 'Para activar el correo indica el servidor y la dirección remitente.']);
        }

        $setting = $current->exists ? $current : new MailSetting(['id' => 1]);
        $setting->forceFill([
            'id' => 1, 'enabled' => (bool) $data['enabled'], 'host' => $data['host'] ?? null, 'port' => (int) $data['port'], 'encryption' => $data['encryption'], 'username' => ($data['username'] ?? '') === '' ? null : $data['username'],
            'password' => $password, 'from_address' => $data['from_address'] ?? null, 'from_name' => ($data['from_name'] ?? '') === '' ? null : $data['from_name'], 'updated_by' => $user->id, 'updated_at' => now(),
        ])->save();

        $after = $this->describe();
        $changed = array_keys(array_filter($after, fn ($v, $k) => $k !== 'updated_at' && $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $passwordChanged = ($data['password'] ?? '') !== '';
        if ($changed !== [] || $passwordChanged) {
            // Nunca se audita la contraseña: solo si cambió.
            $this->audit->record('mail.settings_updated', $user, old: array_intersect_key($before, array_flip($changed)), new: array_intersect_key($after, array_flip($changed)) + ($passwordChanged ? ['password' => 'cambiada'] : []));
        }
    }

    /** Envía ahora un correo de prueba con la configuración guardada y lo registra. */
    public function sendTest(User $user, string $to): MailLog
    {
        $log = MailLog::create([
            'user_id' => $user->id, 'to_address' => $to, 'kind' => 'test', 'subject' => 'Correo de prueba de JGE Inteligencia Comercial', 'body' => 'Si recibes este mensaje, el servidor de correo está bien configurado y el sistema puede enviar avisos y resúmenes.',
            'status' => MailLog::SENDING, 'attempt' => 1, 'created_at' => now(),
        ]);
        if (! MailSetting::current()->ready()) {
            $log->forceFill(['status' => MailLog::NOT_SENT, 'error' => 'El correo no está activado o le falta el servidor o el remitente.'])->save();
        } else {
            $this->deliver($log);
        }
        $this->audit->record('mail.test_sent', $user, new: ['to' => $to, 'status' => $log->status]);

        return $log->refresh();
    }

    /**
     * Envía ahora un reporte como archivo adjunto a una dirección y lo registra. Es inmediato (la persona espera el resultado) y no se
     * reintenta: un envío fallido se dice como fallido en vez de salir más tarde sin que nadie lo sepa.
     *
     * @param  array{data: string, name: string, mime: string}  $attachment
     */
    public function sendReport(User $user, string $to, string $subject, string $body, array $attachment): MailLog
    {
        $log = MailLog::create([
            'user_id' => $user->id, 'to_address' => $to, 'kind' => 'report', 'subject' => $subject, 'body' => $body,
            'status' => MailLog::SENDING, 'attempt' => 1, 'created_at' => now(),
        ]);
        $this->deliver($log, $attachment);

        return $log->refresh();
    }

    /**
     * Registra el correo de un aviso interno para su destinatario, si lo pidió. Sin correo activado queda como «no enviado».
     */
    public function queueNotice(User $user, UserNotification $notice): ?MailLog
    {
        $key = "notice:{$notice->id}";
        if (MailLog::query()->where(['user_id' => $user->id, 'dedupe_key' => $key])->exists()) {
            return null;
        }
        [$status, $error] = match (true) {
            $user->email === null || $user->email === '' => [MailLog::NOT_SENT, 'La persona no tiene correo registrado.'],
            ! MailSetting::current()->ready() => [MailLog::NOT_SENT, 'El correo no está activado o configurado.'],
            default => [MailLog::PENDING, null],
        };

        return MailLog::create([
            'user_id' => $user->id, 'to_address' => $user->email, 'kind' => 'notice', 'subject' => $notice->title, 'body' => $this->bodyFor($notice), 'action_url' => $notice->url === null ? null : rtrim((string) config('app.frontend_url'), '/').$notice->url,
            'dedupe_key' => $key, 'status' => $status, 'error' => $error, 'created_at' => now(), 'next_attempt_at' => $status === MailLog::PENDING ? now() : null,
        ]);
    }

    /**
     * Envía los correos pendientes que ya les toca (reintentos incluidos).
     *
     * @return array{sent: int, failed: int, retry: int, stale: int}
     */
    public function dispatchPending(int $limit = 100): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'stale' => 0];
        // Un envío «en curso» que lleva demasiado no se reenvía (podría haber salido): se marca para revisión.
        $result['stale'] = MailLog::query()->where('status', MailLog::SENDING)->where('created_at', '<', now()->subMinutes(30))->where('kind', 'notice')
            ->update(['status' => MailLog::FAILED, 'error' => 'No se confirmó el envío; revisa el registro antes de reenviar.']);

        if (! MailSetting::current()->ready()) {
            return $result;
        }
        foreach (MailLog::query()->where('status', MailLog::PENDING)->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get() as $log) {
            $log->forceFill(['status' => MailLog::SENDING, 'attempt' => $log->attempt + 1])->save();
            $this->deliver($log);
            $log->refresh();
            $result[$log->status === MailLog::SENT ? 'sent' : ($log->status === MailLog::PENDING ? 'retry' : 'failed')]++;
        }

        return $result;
    }

    /** Envía un registro con el servidor configurado y anota el resultado; en un fallo programa el reintento o se rinde. */
    /** @param  array{data: string, name: string, mime: string}|null  $attachment */
    private function deliver(MailLog $log, ?array $attachment = null): void
    {
        $s = MailSetting::current();
        try {
            config(['mail.mailers.app' => [
                'transport' => 'smtp', 'scheme' => $s->encryption === 'ssl' ? 'smtps' : 'smtp', 'host' => $s->host, 'port' => $s->port, 'username' => $s->username, 'password' => $s->password, 'timeout' => 15,
            ]]);
            Mail::mailer('app')->to((string) $log->to_address)->send(new AppMail($log->subject, $log->body, $log->action_url, (string) $s->from_address, $s->from_name, $attachment));
            $log->forceFill(['status' => MailLog::SENT, 'sent_at' => now(), 'error' => null, 'next_attempt_at' => null])->save();
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 900);
            $wait = $log->kind === 'notice' ? (self::BACKOFF[$log->attempt - 1] ?? null) : null;
            $log->forceFill($wait === null
                ? ['status' => MailLog::FAILED, 'error' => $message, 'next_attempt_at' => null]
                : ['status' => MailLog::PENDING, 'error' => $message, 'next_attempt_at' => now()->addMinutes($wait)])->save();
        }
    }

    /** Texto del correo: el del aviso y, si es un resumen, sus cifras. */
    private function bodyFor(UserNotification $notice): string
    {
        $text = trim(($notice->body ?? ''));
        if ($notice->type === 'digest' && preg_match('#^/resumenes/(\d+)$#', (string) $notice->url, $m) && ($digest = Digest::query()->find((int) $m[1])) !== null) {
            $lines = [];
            foreach ($digest->data['sections'] as $section) {
                $lines[] = $section['title'];
                foreach ($section['metrics'] as $metric) {
                    $value = match ($metric['format']) {
                        'money' => number_format((float) $metric['value'], 2, ',', '.'),
                        'pct' => number_format((float) $metric['value'], 1, ',', '.').' %',
                        default => (string) $metric['value'],
                    };
                    $lines[] = "  · {$metric['label']}: {$value}".(isset($metric['hint']) ? " ({$metric['hint']})" : '');
                }
                $lines[] = '';
            }
            $text = trim(implode("\n", $lines));
        }

        return $text === '' ? $notice->title : $text;
    }
}
