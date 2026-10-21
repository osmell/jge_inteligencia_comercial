<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InsightAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Alertas de clientes (riesgo de inactividad, cambios anormales y líneas abandonadas), con el alcance del usuario. */
class InsightAlertController extends Controller
{
    private const PAGE_SIZE = 25;

    private const SEVERITY_ORDER = "case severity when 'high' then 0 when 'medium' then 1 else 2 end";

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'], 'type' => ['nullable', Rule::in(InsightAlert::TYPES)], 'severity' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'status' => ['nullable', Rule::in([InsightAlert::OPEN, InsightAlert::RESOLVED, InsightAlert::CLOSED])], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        Company::query()->findOrFail($data['company_id']);
        $page = max(1, (int) ($data['page'] ?? 1));

        $base = fn () => InsightAlert::query()->visibleCustomers()->where('insight_alerts.company_id', $data['company_id'])
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

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (InsightAlert $a) => $this->present($a))->values()->all(),
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

    /** @return array<string, mixed> */
    private function present(InsightAlert $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->customer_code, 'name' => $a->customer_name, 'type' => $a->type, 'severity' => $a->severity, 'status' => $a->status, 'explanation' => $a->explanation, 'facts' => $a->facts,
            'first_detected_at' => $a->first_detected_at->toIso8601String(), 'last_detected_at' => $a->last_detected_at->toIso8601String(), 'run_id' => $a->insight_run_id,
        ];
    }
}
