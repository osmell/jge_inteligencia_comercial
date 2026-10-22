<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\NotificationPreferences;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Los avisos internos de la persona que inició sesión: lista, marcar como vistos o descartar, y sus preferencias. */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly Notifier $notifier, private readonly NotificationPreferences $preferences) {}

    /** Contador de nuevos y los más recientes, para la campana. */
    public function summary(Request $request): JsonResponse
    {
        $base = fn (): Builder => UserNotification::query()->where('user_id', $request->user()->id);

        return response()->json(['data' => [
            'unread' => $base()->where('status', UserNotification::NEW)->count(),
            'latest' => $base()->where('status', '!=', UserNotification::DISMISSED)->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()->map(fn (UserNotification $n) => $this->present($n))->all(),
            'paused' => $this->notifier->isPaused($request->user()),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['active', 'new', 'dismissed'])], 'type' => ['nullable', Rule::in(array_keys($this->notifier->types()))], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $status = $data['status'] ?? 'active';

        $rows = UserNotification::query()->where('user_id', $request->user()->id)
            ->when($status === 'active', fn (Builder $q) => $q->where('status', '!=', UserNotification::DISMISSED))
            ->when($status === 'new', fn (Builder $q) => $q->where('status', UserNotification::NEW))
            ->when($status === 'dismissed', fn (Builder $q) => $q->where('status', UserNotification::DISMISSED))
            ->when(! empty($data['type']), fn (Builder $q) => $q->where('type', $data['type']))
            ->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json(['data' => $rows->take(self::PAGE_SIZE)->map(fn (UserNotification $n) => $this->present($n))->values()->all(), 'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE]]);
    }

    public function seen(Request $request, int $notification): JsonResponse
    {
        $record = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        if ($record->status === UserNotification::NEW) {
            $record->forceFill(['status' => UserNotification::SEEN, 'seen_at' => now()])->save();
        }

        return response()->json(['data' => $this->present($record)]);
    }

    public function seenAll(Request $request): JsonResponse
    {
        $count = UserNotification::query()->where(['user_id' => $request->user()->id, 'status' => UserNotification::NEW])->update(['status' => UserNotification::SEEN, 'seen_at' => now()]);

        return response()->json(['data' => ['marked' => $count]]);
    }

    public function dismiss(Request $request, int $notification): JsonResponse
    {
        $record = UserNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        if ($record->status !== UserNotification::DISMISSED) {
            $record->forceFill(['status' => UserNotification::DISMISSED, 'dismissed_at' => now(), 'seen_at' => $record->seen_at ?? now()])->save();
        }

        return response()->json(['data' => $this->present($record)]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => ['types' => $this->notifier->typesFor($request->user()), 'paused' => $this->notifier->isPaused($request->user()), 'email' => $request->user()->email]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['paused' => ['nullable', 'boolean'], 'types' => ['nullable', 'array'], 'types.*' => ['boolean'], 'email' => ['nullable', 'array'], 'email.*' => ['boolean']]);
        $this->preferences->update($user, $data);

        return $this->preferences($request);
    }

    /** Envía a la propia persona un aviso de prueba para comprobar la campana y sus preferencias. */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $notification = $this->notifier->send($user, 'system', 'Aviso de prueba', 'Si ves este aviso en la campana, las notificaciones internas funcionan.', '/notificaciones', 'test:'.now()->format('YmdHis'));

        return response()->json(['data' => $notification ? $this->present($notification) : null, 'meta' => ['delivered' => $notification !== null]], $notification ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function present(UserNotification $n): array
    {
        return [
            'id' => $n->id, 'type' => $n->type, 'type_label' => $this->notifier->types()[$n->type]['label'] ?? $n->type, 'severity' => $n->severity, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
            'status' => $n->status, 'created_at' => $n->created_at->toIso8601String(), 'company_id' => $n->company_id,
        ];
    }
}
