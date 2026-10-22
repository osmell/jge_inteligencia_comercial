<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\ConversationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Sección «conversations» del dashboard inicial. */
final class ConversationsSection extends DashboardSection
{
    public function __construct(private readonly ConversationAccess $conversations) {}

    public function key(): string
    {
        return 'conversations';
    }

    public function permission(): string
    {
        return 'conversations.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->attention($context->user, $context->window, $context->companyId);
    }

    /**
     * Atención por WhatsApp: clientes esperando respuesta, mensajes del periodo y rapidez de respuesta (con el alcance de la persona).
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $window
     * @return array<string, mixed>
     */
    private function attention(User $user, array $window, ?int $companyId): array
    {
        $now = CarbonImmutable::now();
        $limit = max(1, (int) config('conversations.waiting_minutes'));
        $scope = fn () => $this->conversations->visible($user)->when($companyId !== null, fn ($q) => $q->where('whatsapp_conversations.company_id', $companyId));
        $open = $scope()->where('whatsapp_conversations.status', 'open');

        $waiting = (clone $open)->whereNotNull('whatsapp_conversations.customer_id')->whereColumn('whatsapp_conversations.last_inbound_at', '>=', 'whatsapp_conversations.last_message_at')->orderBy('whatsapp_conversations.last_message_at')->get();
        $names = DB::table('customers')->whereIn('id', $waiting->pluck('customer_id'))->pluck('name', 'id');
        $minutes = fn ($c) => (int) $c->last_message_at->diffInMinutes($now);
        $unassigned = (clone $open)->whereNull('whatsapp_conversations.customer_id')->count();

        // Rapidez de respuesta: de cada mensaje del cliente que sigue a una respuesta (o abre la conversación) hasta la siguiente respuesta de una persona.
        $ids = $scope()->pluck('whatsapp_conversations.id');
        $rows = DB::table('whatsapp_messages')->whereIn('conversation_id', $ids)->whereBetween('created_at', [$window['from']->startOfDay(), $window['to']->endOfDay()])->where('message_type', '!=', 'reaction')
            ->orderBy('conversation_id')->orderBy('id')->limit(20000)->get(['conversation_id', 'direction', 'user_id', 'created_at']);
        $pending = [];
        $waits = [];
        $received = 0;
        $sent = 0;
        foreach ($rows as $m) {
            $at = CarbonImmutable::parse($m->created_at);
            if ($m->direction === 'in') {
                $received++;
                $pending[$m->conversation_id] ??= $at;
            } elseif ($m->user_id !== null) {
                $sent++;
                if (isset($pending[$m->conversation_id])) {
                    $waits[] = $pending[$m->conversation_id]->diffInMinutes($at);
                    unset($pending[$m->conversation_id]);
                }
            }
        }
        sort($waits);
        $median = $waits === [] ? null : (int) round($waits[intdiv(count($waits), 2)]);

        return [
            'limit_minutes' => $limit, 'waiting' => $waiting->count(), 'over_limit' => $waiting->filter(fn ($c) => $minutes($c) >= $limit)->count(), 'unassigned' => $unassigned,
            'top' => $waiting->take(5)->map(fn ($c) => ['id' => $c->id, 'customer' => $names[$c->customer_id] ?? '—', 'minutes' => $minutes($c)])->values()->all(),
            'received' => $received, 'sent' => $sent, 'answered' => count($waits), 'median_minutes' => $median,
            'within_limit_pct' => $waits === [] ? null : (int) round(100 * count(array_filter($waits, fn ($w) => $w <= $limit)) / count($waits)),
            'from' => $window['from']->toDateString(), 'to' => $window['to']->toDateString(),
        ];
    }
}
