<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Services\ConversationAccess;
use App\Services\ConversationList;
use App\Services\ConversationPresenter;
use App\Services\ConversationSender;
use App\Services\WhatsappService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Conversaciones de WhatsApp: lista, mensajes paginados hacia atrás, lectura, notas internas y archivos, siempre con el alcance de la persona. */
class ConversationController extends Controller
{
    private const PAGE_SIZE = 30;

    private const MESSAGES = 50;

    /** Tipos que el navegador puede mostrar en línea; el resto siempre se descarga. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'application/pdf'];

    public function __construct(private readonly ConversationAccess $access, private readonly ConversationSender $sender, private readonly WhatsappService $wa, private readonly ConversationPresenter $presenter, private readonly ConversationList $list) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'unassigned' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:open,archived'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $user = $request->user();
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = $this->list->page($user, $data, $page, self::PAGE_SIZE);
        $labels = $this->list->labels($rows->pluck('id'));
        $customers = Customer::withoutGlobalScopes()->whereIn('id', $rows->pluck('customer_id')->filter())->get(['id', 'code', 'name', 'company_id'])->keyBy('id');

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (WhatsappConversation $c) => $this->present($c, $customers->get($c->customer_id), (int) $c->getAttribute('unread'), $user, $labels->get($c->id, collect())->all()))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE],
        ]);
    }

    /** Cuántas conversaciones abiertas tienen mensajes del cliente sin leer (para la insignia del menú). */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->list->unreadCount($user);

        return response()->json(['data' => ['conversations' => $count]]);
    }

    /** Mensajes de una conversación, de a 50, del más reciente hacia atrás (`before` = id del más antiguo ya cargado). */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $c = $this->find($request, $conversation);
        if (empty($data['before'])) {
            $this->access->recordView($request->user(), $c);
        }

        $rows = WhatsappMessage::query()->where('conversation_id', $c->id)->when(! empty($data['before']), fn (Builder $q) => $q->where('id', '<', $data['before']))
            ->orderByDesc('id')->limit(self::MESSAGES + 1)->get();
        $page = $rows->take(self::MESSAGES)->reverse()->values();
        $attachments = WhatsappAttachment::query()->whereIn('message_id', $page->pluck('id'))->get()->groupBy('message_id');
        $replies = WhatsappMessage::query()->whereIn('id', $page->pluck('reply_to_id')->filter())->get()->keyBy('id');
        $authors = $this->list->authorNames($page->pluck('user_id'));

        return response()->json([
            'data' => $page->map(fn (WhatsappMessage $m) => $this->presenter->message($m, $attachments->get($m->id, collect()), $replies->get($m->reply_to_id), $authors[$m->user_id] ?? null))->all(),
            'meta' => ['has_more' => $rows->count() > self::MESSAGES, 'oldest_id' => $page->first()?->id, 'window_open' => $c->windowOpen(), 'can_reply' => $this->access->canReply($request->user(), $c)],
        ]);
    }

    /** Envía un mensaje libre (texto y/o un archivo, con cita opcional); queda guardado y en cola de envío. */
    public function reply(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        abort_unless($this->access->canReply($request->user(), $c), 403, 'No puedes escribir en esta conversación.');
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'], 'file' => ['nullable', 'file', 'max:102400'], 'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'client_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ]);
        $m = $this->sender->send($request->user(), $c, $data['body'] ?? null, $request->file('file'), isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null, $data['client_key'] ?? null);

        return response()->json(['data' => $this->presenter->one($m)], 201);
    }

    /** Reenvía un mensaje libre que había fallado. */
    public function retry(Request $request, int $message): JsonResponse
    {
        $m = WhatsappMessage::query()->findOrFail($message);
        $c = $this->find($request, (int) $m->conversation_id);
        abort_unless($this->access->canReply($request->user(), $c), 403, 'No puedes escribir en esta conversación.');

        return response()->json(['data' => $this->presenter->one($this->sender->retry($request->user(), $m))]);
    }

    /** Plantillas aprobadas, para escribir al cliente cuando la ventana de 24 horas está cerrada. */
    public function templates(): JsonResponse
    {
        return response()->json(['data' => WhatsappTemplate::query()->where('status', 'approved')->orderBy('name')->get()->map(fn (WhatsappTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'body' => $t->body, 'variables' => $t->variableCount()])->all()]);
    }

    public function sendTemplate(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        abort_unless($this->access->canReply($request->user(), $c), 403, 'No puedes escribir en esta conversación.');
        $data = $request->validate(['template_id' => ['required', 'integer'], 'params' => ['nullable', 'array', 'max:10'], 'params.*' => ['string', 'max:300']]);
        $customer = Customer::withoutGlobalScopes()->findOrFail($c->customer_id);
        if ($this->wa->number($customer->whatsapp) !== $c->wa_number) {
            return response()->json(['message' => 'El número de esta conversación ya no es el que tiene el cliente.'], 422);
        }
        $m = $this->wa->send($request->user(), $customer, WhatsappTemplate::query()->findOrFail($data['template_id']), $data['params'] ?? []);
        if ($m->status === WhatsappMessage::NOT_SENT) {
            return response()->json(['message' => (string) $m->error], 422);
        }

        return response()->json(['data' => $this->presenter->one($m)], 201);
    }

    /** Marca como leída la conversación (hasta su último mensaje) para quien la abre. */
    public function read(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $last = (int) WhatsappMessage::query()->where('conversation_id', $c->id)->max('id');
        $this->list->markRead($request->user(), $c, $last);

        return response()->json(['data' => ['last_read_message_id' => $last]]);
    }

    public function notes(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $notes = ConversationNote::query()->where('conversation_id', $c->id)->orderByDesc('id')->limit(100)->get();
        $authors = $this->list->authorNames($notes->pluck('user_id'));

        return response()->json(['data' => $notes->reverse()->values()->map(fn (ConversationNote $n) => ['id' => $n->id, 'body' => $n->body, 'author' => $authors[$n->user_id] ?? null, 'created_at' => $n->created_at->toIso8601String()])->all()]);
    }

    public function storeNote(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        abort_unless($this->access->canReply($request->user(), $c), 403, 'No puedes agregar notas en esta conversación.');
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);
        $note = ConversationNote::create(['conversation_id' => $c->id, 'user_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now()]);

        return response()->json(['data' => ['id' => $note->id, 'body' => $note->body, 'author' => $request->user()->name, 'created_at' => $note->created_at->toIso8601String()]], 201);
    }

    /** Archivo adjunto: solo para quien ve la conversación; nunca se ejecuta en el navegador. */
    public function attachment(Request $request, int $attachment): StreamedResponse
    {
        $file = WhatsappAttachment::query()->findOrFail($attachment);
        $conversationId = WhatsappMessage::query()->whereKey($file->message_id)->value('conversation_id');
        abort_if($conversationId === null, 404);
        $c = $this->find($request, (int) $conversationId);
        abort_unless(Storage::disk('conversations')->exists($file->path), 404, 'El archivo no está disponible.');
        $this->access->recordView($request->user(), $c);

        $inline = in_array($file->mime, self::INLINE, true) && $request->boolean('inline', true);

        return Storage::disk('conversations')->response($file->path, $file->original_name ?? basename($file->path), [
            'Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=3600',
        ], $inline ? 'inline' : 'attachment');
    }

    private function find(Request $request, int $id): WhatsappConversation
    {
        return $this->access->visible($request->user())->whereKey($id)->firstOrFail();
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function present(WhatsappConversation $c, ?Customer $customer, int $unread, User $user, array $labels = []): array
    {
        return [
            'id' => $c->id, 'company_id' => $c->company_id, 'customer' => $customer === null ? null : ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'wa_number' => $c->wa_number, 'profile_name' => $c->profile_name, 'status' => $c->status, 'preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'window_open' => $c->windowOpen(), 'labels' => $labels, 'unread' => $unread, 'can_reply' => $this->access->canReply($user, $c),
        ];
    }
}
