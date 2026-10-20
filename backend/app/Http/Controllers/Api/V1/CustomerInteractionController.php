<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgendaRequest;
use App\Http\Requests\CompleteActionRequest;
use App\Http\Requests\ContactPreferenceRequest;
use App\Http\Requests\CustomerInteractionRequest;
use App\Http\Requests\CustomersRequest;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUpAgenda;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Seguimiento comercial: contactos con un cliente (canal, resultado, respuesta y próxima acción) y la preferencia
 * «no contactar». Un vendedor solo llega a sus clientes (alcance del modelo Customer). Los contactos no se borran.
 */
class CustomerInteractionController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly AuditLogger $audit, private readonly FollowUpAgenda $agenda) {}

    public function index(CustomersRequest $request): JsonResponse
    {
        $customer = $this->customer((int) $request->validated('company_id'), $request->validated('code'));
        $page = $request->page();

        $rows = CustomerInteraction::query()->with('user')->where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (CustomerInteraction $i) => $this->present($i, $request->user()))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'contact_preference' => $this->preference($customer)],
        ]);
    }

    public function store(CustomerInteractionRequest $request): JsonResponse
    {
        $customer = $this->customer((int) $request->validated('company_id'), $request->validated('code'));
        $data = $request->safe()->only(['channel', 'direction', 'outcome', 'occurred_at', 'response', 'next_action', 'next_action_at']);
        $this->guardDoNotContact($customer, $data);

        $interaction = CustomerInteraction::create($data + ['company_id' => $customer->company_id, 'customer_id' => $customer->id, 'user_id' => $request->user()->id]);
        $this->audit->record('customer_interactions.created', $request->user(), auditable: $interaction, new: $this->audited($interaction), companyId: $customer->company_id);

        return response()->json(['data' => $this->present($interaction->load('user'), $request->user())], 201);
    }

    public function agenda(AgendaRequest $request): JsonResponse
    {
        $result = $this->agenda->build($request->user(), $request->options());

        return response()->json(['data' => ['overdue' => $result['overdue'], 'upcoming' => $result['upcoming'], 'reactivate' => $result['reactivate']], 'meta' => $result['meta']]);
    }

    /** Marca la próxima acción de un contacto como cumplida (o la reabre). */
    public function complete(CompleteActionRequest $request, int $interaction): JsonResponse
    {
        $record = CustomerInteraction::query()->findOrFail($interaction);
        $this->customerById($record->customer_id);
        abort_if($record->next_action_at === null, 422, 'Este contacto no tiene una próxima acción.');

        $done = $request->boolean('done');
        if (($record->next_action_done_at !== null) !== $done) {
            $record->forceFill(['next_action_done_at' => $done ? now() : null, 'next_action_done_by' => $done ? $request->user()->id : null])->save();
            $this->audit->record($done ? 'customer_interactions.action_completed' : 'customer_interactions.action_reopened', $request->user(), auditable: $record, new: ['customer_id' => $record->customer_id, 'next_action' => $record->next_action], companyId: $record->company_id);
        }

        return response()->json(['data' => $this->present($record->load('user'), $request->user())]);
    }

    /** Corrige un contacto: solo quien lo registró o quien gestiona clientes. */
    public function update(CustomerInteractionRequest $request, int $interaction): JsonResponse
    {
        $record = CustomerInteraction::query()->findOrFail($interaction);
        $customer = $this->customerById($record->customer_id);
        abort_unless($this->canEdit($record, $request->user()), 403, 'Solo quien registró el contacto puede corregirlo.');

        $data = $request->safe()->only(['channel', 'direction', 'outcome', 'occurred_at', 'response', 'next_action', 'next_action_at']);
        $this->guardDoNotContact($customer, $data);
        $before = $this->audited($record);
        $record->fill($data);
        if ($record->isDirty(['next_action', 'next_action_at'])) {
            // Cambiar la próxima acción la vuelve a dejar pendiente.
            $record->next_action_done_at = null;
            $record->next_action_done_by = null;
        }
        $record->save();
        if ($record->wasChanged(array_keys($data))) {
            $this->audit->record('customer_interactions.updated', $request->user(), auditable: $record, old: $before, new: $this->audited($record), companyId: $record->company_id);
        }

        return response()->json(['data' => $this->present($record->load('user'), $request->user())]);
    }

    /** @return array{do_not_contact: bool, reason: string|null, since: string|null} */
    private function preference(Customer $customer): array
    {
        return [
            'do_not_contact' => $customer->do_not_contact,
            'reason' => $customer->do_not_contact_reason,
            'since' => $customer->do_not_contact_at?->toIso8601String(),
        ];
    }

    public function updatePreference(ContactPreferenceRequest $request): JsonResponse
    {
        $customer = $this->customer((int) $request->validated('company_id'), $request->validated('code'));
        $on = $request->boolean('do_not_contact');
        $before = $this->preference($customer);

        $customer->forceFill([
            'do_not_contact' => $on,
            'do_not_contact_reason' => $on ? $request->validated('reason') : null,
            'do_not_contact_at' => $on ? now() : null,
            'do_not_contact_by' => $on ? $request->user()->id : null,
        ])->save();

        if ($before['do_not_contact'] !== $on || $before['reason'] !== $customer->do_not_contact_reason) {
            $this->audit->record('customers.contact_preference_updated', $request->user(), auditable: $customer, old: $before, new: $this->preference($customer), companyId: $customer->company_id);
        }

        return response()->json(['data' => $this->preference($customer)]);
    }

    /** @param  array<string, mixed>  $data */
    private function guardDoNotContact(Customer $customer, array $data): void
    {
        if (! $customer->do_not_contact) {
            return;
        }
        if (($data['direction'] ?? null) === 'outbound') {
            throw ValidationException::withMessages(['direction' => 'El cliente pidió no recibir comunicaciones: solo se registran los contactos que él inicia.']);
        }
        if (! empty($data['next_action_at'])) {
            throw ValidationException::withMessages(['next_action_at' => 'El cliente pidió no recibir comunicaciones: no se programan próximas acciones.']);
        }
    }

    private function customer(int $companyId, string $code): Customer
    {
        return Customer::query()->where('company_id', $companyId)->where('code', $code)->first() ?? abort(404, 'Cliente no encontrado.');
    }

    private function customerById(int $id): Customer
    {
        return Customer::query()->find($id) ?? abort(404, 'Contacto no encontrado.');
    }

    private function canEdit(CustomerInteraction $record, User $user): bool
    {
        return $record->user_id === $user->id || $user->hasPermission('customers.manage');
    }

    /** @return array<string, mixed> */
    private function present(CustomerInteraction $i, User $viewer): array
    {
        return [
            'id' => $i->id, 'channel' => $i->channel, 'direction' => $i->direction, 'outcome' => $i->outcome,
            'occurred_at' => $i->occurred_at->toIso8601String(), 'response' => $i->response,
            'next_action' => $i->next_action, 'next_action_at' => $i->next_action_at?->toIso8601String(),
            'next_action_done_at' => $i->next_action_done_at?->toIso8601String(),
            'user' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->name] : null,
            'can_edit' => $viewer->hasPermission('interactions.manage') && $this->canEdit($i, $viewer),
        ];
    }

    /** @return array<string, mixed> */
    private function audited(CustomerInteraction $i): array
    {
        return $i->only(['customer_id', 'channel', 'direction', 'outcome', 'response', 'next_action']) + [
            'occurred_at' => $i->occurred_at->toIso8601String(),
            'next_action_at' => $i->next_action_at instanceof CarbonInterface ? $i->next_action_at->toIso8601String() : null,
        ];
    }
}
