<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\User;
use App\Services\Notifier;

/**
 * Base de las automatizaciones que envían avisos internos: lleva la cuenta de los destinatarios y de los avisos realmente nuevos
 * (un aviso repetido por clave de deduplicación no cuenta, así que reintentar una ejecución no cambia nada).
 */
abstract class NoticeHandler implements Handler
{
    /** @var array<int, true> */
    private array $recipients = [];

    private int $created = 0;

    public function __construct(protected readonly Notifier $notifier) {}

    /** @param  array<string, int>  $params */
    abstract protected function notices(array $params): void;

    public function run(array $params): array
    {
        $this->recipients = [];
        $this->created = 0;
        $this->notices($params);

        return ['recipients' => count($this->recipients), 'notices' => $this->created];
    }

    protected function notify(User $user, string $type, string $title, ?string $body, ?string $url, string $key, string $severity = 'info', ?int $companyId = null): void
    {
        $notice = $this->notifier->send($user, $type, $title, $body, $url, $key, $severity, $companyId);
        if ($notice !== null && $notice->wasRecentlyCreated) {
            $this->created++;
            $this->recipients[$user->id] = true;
        }
    }

    protected function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
