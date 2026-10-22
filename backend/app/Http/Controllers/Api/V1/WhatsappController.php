<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappConsent;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use App\Services\AuditLogger;
use App\Services\WhatsappInbound;
use App\Services\WhatsappService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Administración de WhatsApp (cuenta, plantillas, registro), mensajes y consentimiento por cliente, y el webhook de Meta. */
class WhatsappController extends Controller
{
    public function __construct(private readonly WhatsappService $wa, private readonly AuditLogger $audit, private readonly WhatsappInbound $inbound) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->wa->describe()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'phone_number_id' => ['nullable', 'string', 'max:100'], 'business_account_id' => ['nullable', 'string', 'max:100'], 'api_version' => ['required', 'regex:/^v\d+\.\d+$/'],
            'unassigned_company_id' => ['nullable', 'integer', 'exists:companies,id'], 'access_token' => ['nullable', 'string', 'max:1000'], 'app_secret' => ['nullable', 'string', 'max:300'], 'verify_token' => ['nullable', 'string', 'max:200'],
            'away_enabled' => ['sometimes', 'boolean'], 'away_message' => ['nullable', 'string', 'max:1000'], 'away_start_hour' => ['sometimes', 'integer', 'between:0,23'], 'away_end_hour' => ['sometimes', 'integer', 'between:1,24', 'gt:away_start_hour'],
            'away_days' => ['sometimes', 'array', 'max:7'], 'away_days.*' => ['integer', 'between:1,7'],
        ]);
        $this->wa->save($request->user(), $data);

        return $this->show();
    }

    public function test(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->wa->testConnection($request->user())]);
    }

    public function templates(): JsonResponse
    {
        return response()->json(['data' => WhatsappTemplate::query()->orderBy('name')->get()->map(fn (WhatsappTemplate $t) => $this->template($t))->all()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $this->templateRules($request, null);
        $t = WhatsappTemplate::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('whatsapp.template_created', $request->user(), new: ['name' => $t->name, 'status' => $t->status]);

        return response()->json(['data' => $this->template($t)], 201);
    }

    public function updateTemplate(Request $request, int $template): JsonResponse
    {
        $t = WhatsappTemplate::query()->findOrFail($template);
        $before = $t->only(['body', 'status', 'category']);
        $t->update($this->templateRules($request, $t));
        $this->audit->record('whatsapp.template_updated', $request->user(), old: $before, new: $t->only(['body', 'status', 'category']));

        return response()->json(['data' => $this->template($t)]);
    }

    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['sent', 'delivered', 'read', 'failed', 'not_sent'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = WhatsappMessage::query()->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();
        $names = Customer::withoutGlobalScopes()->whereIn('id', $rows->pluck('customer_id')->filter())->pluck('name', 'id');

        return response()->json(['data' => $rows->take(25)->map(fn (WhatsappMessage $m) => $this->message($m) + ['customer' => $names[$m->customer_id] ?? null])->values()->all(), 'meta' => ['page' => $page, 'has_more' => $rows->count() > 25]]);
    }

    /** Estado de WhatsApp de un cliente: consentimiento, impedimentos, plantillas aprobadas e historial. */
    public function customer(int $customer): JsonResponse
    {
        $c = Customer::query()->findOrFail($customer);

        return response()->json(['data' => [
            'number' => $c->whatsapp, 'consent' => $this->wa->consentOf($c), 'blockers' => $this->wa->blockers($c), 'ready' => WhatsappSetting::current()->ready(),
            'consents' => WhatsappConsent::query()->where('customer_id', $c->id)->orderByDesc('id')->limit(10)->get()->map(fn (WhatsappConsent $x) => ['status' => $x->status, 'source' => $x->source, 'created_at' => $x->created_at->toIso8601String()])->all(),
            'templates' => WhatsappTemplate::query()->where('status', 'approved')->orderBy('name')->get()->map(fn (WhatsappTemplate $t) => $this->template($t))->all(),
            'messages' => WhatsappMessage::query()->where('customer_id', $c->id)->orderByDesc('id')->limit(20)->get()->map(fn (WhatsappMessage $m) => $this->message($m))->all(),
        ]]);
    }

    public function consent(Request $request, int $customer): JsonResponse
    {
        $c = Customer::query()->findOrFail($customer);
        $data = $request->validate(['status' => ['required', Rule::in(['granted', 'revoked'])], 'source' => ['required', 'string', 'min:3', 'max:300']]);
        $this->wa->recordConsent($request->user(), $c, $data['status'], $data['source']);

        return $this->customer($customer);
    }

    public function send(Request $request, int $customer): JsonResponse
    {
        $c = Customer::query()->findOrFail($customer);
        $data = $request->validate(['template_id' => ['required', 'integer'], 'params' => ['nullable', 'array', 'max:10'], 'params.*' => ['string', 'max:300']]);
        $template = WhatsappTemplate::query()->findOrFail((int) $data['template_id']);
        $log = $this->wa->send($request->user(), $c, $template, $data['params'] ?? []);

        return response()->json(['data' => $this->message($log)], 201);
    }

    /** Verificación del webhook que pide Meta al configurarlo. */
    public function verify(Request $request): Response
    {
        abort_unless($request->query('hub_mode') === 'subscribe' && $this->wa->validVerifyToken($request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    /** Estados de los mensajes (entregado, leído, fallido); solo con firma válida. */
    public function webhook(Request $request): JsonResponse
    {
        abort_unless($this->wa->validSignature($request->getContent(), $request->header('X-Hub-Signature-256')), 403, 'Firma no válida.');

        $payload = (array) $request->json()->all();

        return response()->json(['applied' => $this->wa->applyStatuses($payload), 'received' => $this->inbound->process($payload)]);
    }

    /** @return array<string, mixed> */
    private function templateRules(Request $request, ?WhatsappTemplate $t): array
    {
        return $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9_]{1,100}$/', Rule::unique('whatsapp_templates', 'name')->where('language', $request->input('language', 'es'))->ignore($t?->id)],
            'language' => ['required', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'], 'category' => ['required', Rule::in(WhatsappTemplate::CATEGORIES)],
            'body' => ['required', 'string', 'min:3', 'max:1024'], 'status' => ['required', Rule::in(WhatsappTemplate::STATUSES)],
        ], ['name.regex' => 'El nombre usa minúsculas, números y guion bajo (como lo pide Meta).']);
    }

    /** @return array<string, mixed> */
    private function template(WhatsappTemplate $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'body' => $t->body, 'status' => $t->status, 'variables' => $t->variableCount()];
    }

    /** @return array<string, mixed> */
    private function message(WhatsappMessage $m): array
    {
        return ['id' => $m->id, 'to' => $m->to_number, 'body' => $m->body, 'status' => $m->status, 'error' => $m->error, 'created_at' => $m->created_at->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(), 'read_at' => $m->read_at?->toIso8601String()];
    }
}
