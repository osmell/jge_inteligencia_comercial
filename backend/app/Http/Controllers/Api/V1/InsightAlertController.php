<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InsightAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InsightAlertFollowUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Alertas de clientes (riesgo de inactividad, cambios anormales y líneas abandonadas), con el alcance del usuario. */
class InsightAlertController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly InsightAlertFollowUp $followUps) {}

    private const PAGE_SIZE = 25;

    private const SEVERITY_ORDER = "case severity when 'high' then 0 when 'medium' then 1 else 2 end";

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'], 'seller_id' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', Rule::in(InsightAlert::TYPES)], 'severity' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in([InsightAlert::OPEN, InsightAlert::RESOLVED, InsightAlert::CLOSED])], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        Company::query()->findOrFail((int) $data['company_id']);
        $page = max(1, (int) ($data['page'] ?? 1));

        $base = fn () => InsightAlert::query()->visibleCustomers()->where('insight_alerts.company_id', $data['company_id'])
            ->when(! empty($data['seller_id']), fn (Builder $q) => $q->whereExists(Customer::query()->selectRaw('1')->whereColumn('customers.company_id', 'insight_alerts.company_id')->whereColumn('customers.code', 'insight_alerts.customer_code')->where('customers.seller_id', $data['seller_id'])->toBase()))
            ->when(! empty($data['q']), function (Builder $q) use ($data) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($data['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(customer_name) like ? escape '\\'", [$like])->orWhereRaw("lower(customer_code) like ? escape '\\'", [$like]));
            });
        $status = $data['status'] ?? InsightAlert::OPEN;
        $counts = array_fill_keys(InsightAlert::TYPES, 0);
        foreach ($base()->where('status', $status)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type') as $type => $total) {
            $counts[$type] = (int) $total;
        }

        $rows = $base()->where('status', $status)->when(! empty($data['type']), fn (Builder $q) => $q->where('type', $data['type']))->when(! empty($data['severity']), fn (Builder $q) => $q->where('severity', $data['severity']))
            ->orderByRaw(self::SEVERITY_ORDER)->orderBy('customer_name')->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        $slice = $rows->take(self::PAGE_SIZE);
        $closers = User::withoutGlobalScopes()->whereIn('id', $slice->pluck('closed_by')->filter())->pluck('name', 'id')->all();

        return response()->json([
            'data' => $slice->map(fn (InsightAlert $a) => $this->present($a, $closers))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'counts' => $counts],
        ]);
    }

    /** Alertas abiertas de un cliente para su ficha. */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255']]);
        $rows = InsightAlert::query()->visibleCustomers()->where(['insight_alerts.company_id' => $data['company_id'], 'customer_code' => $data['code'], 'status' => InsightAlert::OPEN])->orderByRaw(self::SEVERITY_ORDER)->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (InsightAlert $a) => $this->present($a))->all()]);
    }

    /** Cierra una alerta con un motivo (con «Otro», una nota). Una alerta cerrada no se reabre por el mismo hecho. */
    public function close(Request $request, int $alert): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(InsightAlert::CLOSE_REASONS)], 'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other']]);
        $record = InsightAlert::query()->visibleCustomers()->findOrFail($alert);
        abort_unless($record->status === InsightAlert::OPEN, 422, 'Solo se puede cerrar una alerta abierta.');

        $record->forceFill(['status' => InsightAlert::CLOSED, 'closed_at' => now(), 'closed_by' => $request->user()->id, 'close_reason' => $data['reason'], 'close_note' => $data['note'] ?? null])->save();
        $this->audit->record('insights.alert_closed', $request->user(), auditable: $record, new: ['type' => $record->type, 'customer_code' => $record->customer_code, 'reason' => $data['reason'], 'note' => $data['note'] ?? null], companyId: $record->company_id);

        return response()->json(['data' => $this->present($record, [$request->user()->id => $request->user()->name])]);
    }

    /**
     * Crea, a petición de una persona, una acción en la agenda de seguimiento a partir de la alerta. No es un contacto: queda
     * «planeada» y solo es una próxima acción con fecha; nunca se crea sola.
     */
    public function followUp(Request $request, int $alert): JsonResponse
    {
        $data = $request->validate(['next_action' => ['required', 'string', 'max:500'], 'next_action_at' => ['required', 'date', 'after:now']]);
        $record = InsightAlert::query()->visibleCustomers()->findOrFail($alert);
        abort_unless($record->status === InsightAlert::OPEN, 422, 'Solo se puede crear una acción desde una alerta abierta.');
        abort_if($record->follow_up_interaction_id !== null, 422, 'Esta alerta ya tiene una acción en la agenda.');
        $customer = Customer::query()->where(['company_id' => $record->company_id, 'code' => $record->customer_code])->first() ?? abort(404, 'Cliente no encontrado.');
        abort_if($customer->do_not_contact, 422, 'El cliente pidió no recibir comunicaciones: no se programan acciones de contacto.');

        $interaction = $this->followUps->create($request->user(), $record, $customer, $data['next_action'], $data['next_action_at']);

        return response()->json(['data' => ['interaction_id' => $interaction->id]], 201);
    }

    /**
     * @param  array<int|string, string>  $closers
     * @return array<string, mixed>
     */
    private function present(InsightAlert $a, array $closers = []): array
    {
        return [
            'follow_up' => $a->follow_up_interaction_id !== null,
            'closed' => $a->status === InsightAlert::CLOSED ? ['at' => $a->closed_at?->toIso8601String(), 'reason' => $a->close_reason, 'note' => $a->close_note, 'by' => $closers[$a->closed_by] ?? null] : null,
            'id' => $a->id, 'code' => $a->customer_code, 'name' => $a->customer_name, 'type' => $a->type, 'severity' => $a->severity, 'status' => $a->status, 'explanation' => $a->explanation, 'facts' => $a->facts,
            'first_detected_at' => $a->first_detected_at->toIso8601String(), 'last_detected_at' => $a->last_detected_at->toIso8601String(), 'run_id' => $a->insight_run_id,
        ];
    }
}
