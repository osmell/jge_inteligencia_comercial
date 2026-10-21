<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Conversaciones de WhatsApp: lista, mensajes paginados hacia atrás, lectura, notas internas y archivos, siempre con el alcance de la persona. */
class ConversationController extends Controller
{
    private const PAGE_SIZE = 30;

    private const MESSAGES = 50;

    /** Tipos que el navegador puede mostrar en línea; el resto siempre se descarga. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'application/pdf'];

    public function __construct(private readonly ConversationAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'unassigned' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:open,archived'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $user = $request->user();
        $page = max(1, (int) ($data['page'] ?? 1));
        $unread = DB::table('whatsapp_messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id', 'whatsapp_conversations.id')->where('m.direction', 'in')
            ->whereRaw('m.id > coalesce((select r.last_read_message_id from conversation_reads r where r.conversation_id = whatsapp_conversations.id and r.user_id = ?), 0)', [$user->id]);

        $rows = $this->access->visible($user)->select('whatsapp_conversations.*')->selectSub($unread, 'unread')
            ->where('whatsapp_conversations.status', $data['status'] ?? WhatsappConversation::OPEN)
            ->when(! empty($data['unassigned']), fn (Builder $q) => $q->whereNull('whatsapp_conversations.customer_id'))
            ->when(! empty($data['q']), function (Builder $q) use ($data) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($data['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(whatsapp_conversations.wa_number) like ?', [$term])->orWhereRaw('lower(coalesce(whatsapp_conversations.profile_name, \'\')) like ?', [$term])
                    ->orWhereIn('whatsapp_conversations.customer_id', DB::table('customers')->select('id')->whereRaw('lower(name) like ?', [$term])));
            })
            ->orderByRaw('whatsapp_conversations.last_message_at desc nulls last')->orderByDesc('whatsapp_conversations.id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();
        $customers = Customer::withoutGlobalScopes()->whereIn('id', $rows->pluck('customer_id')->filter())->get(['id', 'code', 'name', 'company_id'])->keyBy('id');

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (WhatsappConversation $c) => $this->present($c, $customers->get($c->customer_id), (int) $c->getAttribute('unread'), $user))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE],
        ]);
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
        $authors = DB::table('users')->whereIn('id', $page->pluck('user_id')->filter())->pluck('name', 'id');

        return response()->json([
            'data' => $page->map(fn (WhatsappMessage $m) => $this->message($m, $attachments->get($m->id, collect()), $replies->get($m->reply_to_id), $authors[$m->user_id] ?? null))->all(),
            'meta' => ['has_more' => $rows->count() > self::MESSAGES, 'oldest_id' => $page->first()?->id, 'window_open' => $c->windowOpen(), 'can_reply' => $this->access->canReply($request->user(), $c)],
        ]);
    }

    /** Marca como leída la conversación (hasta su último mensaje) para quien la abre. */
    public function read(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $last = (int) WhatsappMessage::query()->where('conversation_id', $c->id)->max('id');
        DB::table('conversation_reads')->upsert([['user_id' => $request->user()->id, 'conversation_id' => $c->id, 'last_read_message_id' => $last, 'updated_at' => now()]], ['user_id', 'conversation_id'], ['last_read_message_id', 'updated_at']);

        return response()->json(['data' => ['last_read_message_id' => $last]]);
    }

    public function notes(Request $request, int $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $notes = ConversationNote::query()->where('conversation_id', $c->id)->orderByDesc('id')->limit(100)->get();
        $authors = DB::table('users')->whereIn('id', $notes->pluck('user_id')->filter())->pluck('name', 'id');

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

    /** @return array<string, mixed> */
    private function present(WhatsappConversation $c, ?Customer $customer, int $unread, User $user): array
    {
        return [
            'id' => $c->id, 'company_id' => $c->company_id, 'customer' => $customer === null ? null : ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'wa_number' => $c->wa_number, 'profile_name' => $c->profile_name, 'status' => $c->status, 'preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'window_open' => $c->windowOpen(), 'unread' => $unread, 'can_reply' => $this->access->canReply($user, $c),
        ];
    }

    /**
     * @param  Collection<int, WhatsappAttachment>  $files
     * @return array<string, mixed>
     */
    private function message(WhatsappMessage $m, $files, ?WhatsappMessage $reply, ?string $author): array
    {
        return [
            'id' => $m->id, 'direction' => $m->direction, 'type' => $m->message_type, 'body' => $m->body, 'status' => $m->status, 'error' => $m->error, 'author' => $author,
            'created_at' => $m->created_at->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(), 'read_at' => $m->read_at?->toIso8601String(),
            'reply_to' => $reply === null ? null : ['id' => $reply->id, 'direction' => $reply->direction, 'body' => mb_substr($reply->body, 0, 120)],
            'attachments' => $files->map(fn (WhatsappAttachment $a) => ['id' => $a->id, 'kind' => $a->kind, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size])->values()->all(),
        ];
    }
}
