<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\ConversationBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\AuditLogger;
use App\Services\ConversationAccess;
use App\Services\ConversationPresenter;
use App\Services\ConversationSender;
use App\Services\WhatsappService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Herramientas del equipo en las conversaciones: asignar números desconocidos, etiquetas, respuestas rápidas y cotizaciones. */
class ConversationToolsController extends Controller
{
    public function __construct(
        private readonly ConversationAccess $access,
        private readonly AuditLogger $audit,
        private readonly ConversationSender $sender,
        private readonly ConversationPresenter $presenter,
        private readonly WhatsappService $wa,
    ) {}

    /** Clientes del equipo de quien supervisa a los que se puede asignar el número desconocido (misma compañía). */
    public function candidates(Request $request, int $conversation): JsonResponse
    {
        $c = $this->unassigned($request, $conversation);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $request->query('q', ''))).'%';
        $rows = $this->team($request)->where('customers.company_id', $c->company_id)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(customers.name) like ?', [$term])->orWhereRaw('lower(customers.code) like ?', [$term])))
            ->orderBy('customers.name')->limit(15)->get(['customers.id', 'customers.code', 'customers.name', 'customers.whatsapp']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'whatsapp' => $r->whatsapp])->all()]);
    }

    /** Un número desconocido pasa a ser de un cliente del equipo: desde entonces lo ve y le escribe su vendedor. */
    public function assign(Request $request, int $conversation): JsonResponse
    {
        $c = $this->unassigned($request, $conversation);
        $data = $request->validate(['customer_id' => ['required', 'integer']]);
        $customer = $this->team($request)->where('customers.company_id', $c->company_id)->where('customers.id', $data['customer_id'])->first(['customers.id']);
        abort_if($customer === null, 422, 'Solo puedes asignarlo a un cliente de tu equipo en la misma compañía.');
        $model = Customer::withoutGlobalScopes()->findOrFail($customer->id);
        DB::transaction(function () use ($c, $model, $request) {
            $c->forceFill(['customer_id' => $model->id])->save();
            if ($this->wa->number($model->whatsapp) === '') {
                $model->forceFill(['whatsapp' => '+'.$c->wa_number])->save();      // para poder usar plantillas con este número
            }
            DB::table('whatsapp_messages')->where('conversation_id', $c->id)->whereNull('customer_id')->update(['customer_id' => $model->id]);
            $this->audit->record('conversations.assigned', $request->user(), auditable: $c, new: ['customer_id' => $model->id, 'wa_number' => $c->wa_number], companyId: $c->company_id);
        });
        $last = WhatsappMessage::query()->where('conversation_id', $c->id)->max('id');
        if ($last !== null) {
            ConversationBroadcast::dispatch((int) $last, 'updated');         // las listas de quienes ya ven la conversación se actualizan
        }

        return response()->json(['data' => $this->presenter->conversation($c->refresh()) + ['can_reply' => $this->access->canReply($request->user(), $c)]]);
    }

    public function labels(Request $request, int $conversation): JsonResponse
    {
        $c = $this->writable($request, $conversation);
        $data = $request->validate(['labels' => ['present', 'array', 'max:5'], 'labels.*' => ['string', Rule::in((array) config('conversations.labels'))]]);
        $new = array_values(array_unique($data['labels']));
        DB::transaction(function () use ($c, $new, $request) {
            $old = DB::table('conversation_labels')->where('conversation_id', $c->id)->pluck('label')->all();
            DB::table('conversation_labels')->where('conversation_id', $c->id)->whereNotIn('label', $new)->delete();
            foreach (array_diff($new, $old) as $label) {
                DB::table('conversation_labels')->insert(['conversation_id' => $c->id, 'label' => $label, 'user_id' => $request->user()->id, 'created_at' => now()]);
            }
            if (array_diff($new, $old) !== [] || array_diff($old, $new) !== []) {
                $this->audit->record('conversations.labels_changed', $request->user(), auditable: $c, old: ['labels' => $old], new: ['labels' => $new], companyId: $c->company_id);
            }
        });
        $last = WhatsappMessage::query()->where('conversation_id', $c->id)->max('id');
        if ($last !== null) {
            ConversationBroadcast::dispatch((int) $last, 'updated');
        }

        return response()->json(['data' => ['labels' => $new]]);
    }

    /** Respuestas rápidas activas: las propias y las compartidas por supervisión. */
    public function quickReplies(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = DB::table('quick_replies as q')->join('users as u', 'u.id', '=', 'q.user_id')->where('q.is_active', true)->where(fn ($w) => $w->where('q.user_id', $user->id)->orWhere('q.shared', true))
            ->orderBy('q.title')->get(['q.id', 'q.title', 'q.body', 'q.shared', 'q.user_id', 'u.name as author']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'body' => $r->body, 'shared' => (bool) $r->shared, 'mine' => (int) $r->user_id === $user->id, 'author' => $r->author])->all()]);
    }

    public function storeQuickReply(Request $request): JsonResponse
    {
        $data = $this->quickReplyData($request);
        $id = DB::table('quick_replies')->insertGetId($data + ['user_id' => $request->user()->id, 'is_active' => true, 'created_at' => now()]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function updateQuickReply(Request $request, int $reply): JsonResponse
    {
        $row = DB::table('quick_replies')->where(['id' => $reply, 'user_id' => $request->user()->id])->first();
        abort_if($row === null, 404);
        $data = $request->has('is_active') && ! $request->has('title') ? ['is_active' => $request->validate(['is_active' => ['required', 'boolean']])['is_active']] : $this->quickReplyData($request);
        DB::table('quick_replies')->where('id', $reply)->update($data + ['updated_at' => now()]);

        return response()->json(['data' => ['id' => $reply]]);
    }

    /** Cotizaciones del cliente que tienen un archivo adjunto, para enviarlas por la conversación. */
    public function quotations(Request $request, int $conversation): JsonResponse
    {
        $c = $this->writable($request, $conversation);
        $rows = Quotation::query()->where('customer_id', $c->customer_id)->whereNotNull('attachment_path')->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn (Quotation $q) => ['id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'amount' => $q->amount, 'name' => $q->attachment_name, 'size' => $q->attachment_size, 'mime' => $q->attachment_mime])->all()]);
    }

    public function sendQuotation(Request $request, int $conversation): JsonResponse
    {
        $c = $this->writable($request, $conversation);
        $data = $request->validate(['quotation_id' => ['required', 'integer'], 'body' => ['nullable', 'string', 'max:1000'], 'client_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/']]);
        $q = Quotation::query()->where('customer_id', $c->customer_id)->whereNotNull('attachment_path')->findOrFail($data['quotation_id']);
        abort_unless(Storage::disk('quotations')->exists((string) $q->attachment_path), 404, 'La cotización ya no tiene su archivo.');

        $tmp = tempnam(sys_get_temp_dir(), 'jge');
        file_put_contents($tmp, (string) Storage::disk('quotations')->get((string) $q->attachment_path));
        try {
            $file = new UploadedFile($tmp, (string) $q->attachment_name, null, null, true);
            $m = $this->sender->send($request->user(), $c, $data['body'] ?? null, $file, null, $data['client_key'] ?? null);
        } finally {
            @unlink($tmp);
        }
        $this->audit->record('conversations.quotation_sent', $request->user(), auditable: $c, new: ['quotation' => $q->number], companyId: $c->company_id);

        return response()->json(['data' => $this->presenter->one($m)], 201);
    }

    /** @return array{title: string, body: string, shared: bool} */
    private function quickReplyData(Request $request): array
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:2', 'max:60'], 'body' => ['required', 'string', 'min:1', 'max:1000'], 'shared' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']]);
        $shared = (bool) ($data['shared'] ?? false);
        abort_if($shared && $request->user()->role->slug !== Role::SUPERVISOR_VENTAS, 403, 'Solo supervisión puede compartir respuestas rápidas con el equipo.');

        return ['title' => $data['title'], 'body' => $data['body'], 'shared' => $shared] + (isset($data['is_active']) ? ['is_active' => (bool) $data['is_active']] : []);
    }

    private function writable(Request $request, int $id): WhatsappConversation
    {
        $c = $this->access->visible($request->user())->whereKey($id)->firstOrFail();
        abort_unless($this->access->canReply($request->user(), $c), 403, 'No puedes escribir en esta conversación.');

        return $c;
    }

    /** Conversación sin cliente, visible para quien supervisa (la única persona que puede asignarla). */
    private function unassigned(Request $request, int $id): WhatsappConversation
    {
        $c = $this->access->visible($request->user())->whereKey($id)->firstOrFail();
        abort_unless($request->user()->role->slug === Role::SUPERVISOR_VENTAS && $request->user()->hasPermission('conversations.reply'), 403, 'Solo supervisión puede asignar números desconocidos.');
        abort_if($c->customer_id !== null, 422, 'Esta conversación ya tiene un cliente asignado.');

        return $c;
    }

    /** @return Builder clientes de los vendedores que supervisa la persona */
    private function team(Request $request)
    {
        return DB::table('customers')->whereIn('customers.seller_id', DB::table('sellers')->where('supervisor_id', $request->user()->id)->select('id'));
    }
}
