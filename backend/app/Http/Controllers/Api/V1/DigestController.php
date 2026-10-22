<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Digest;
use App\Services\AuditLogger;
use App\Services\DataScope;
use App\Services\DigestBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Resúmenes para gerencia: solo de las compañías a las que la persona tiene acceso completo (son cifras de toda la compañía). */
class DigestController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly DataScope $scope, private readonly DigestBuilder $builder, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', Rule::in([Digest::DAILY, Digest::WEEKLY])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = $this->visible($request)->when(! empty($data['company_id']), fn (Builder $q) => $q->where('company_id', $data['company_id']))->when(! empty($data['kind']), fn (Builder $q) => $q->where('kind', $data['kind']))
            ->orderByDesc('period_to')->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get(['id', 'company_id', 'kind', 'period_key', 'period_from', 'period_to', 'title', 'generated_at']);

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (Digest $d) => $this->summary($d))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'companies' => Company::query()->whereIn('id', $this->companyIds($request))->orderBy('name')->get(['id', 'name'])->all()],
        ]);
    }

    public function show(Request $request, int $digest): JsonResponse
    {
        $d = $this->visible($request)->findOrFail($digest);

        return response()->json(['data' => $this->summary($d) + ['sections' => $d->data['sections']]]);
    }

    /** Genera (o devuelve, si ya existe) el resumen del periodo actual; solo quien administra las automatizaciones. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::in([Digest::DAILY, Digest::WEEKLY])]]);
        abort_unless($request->user()->hasPermission('automations.manage'), 403, 'No tienes permiso para realizar esta acción.');
        abort_unless(in_array((int) $data['company_id'], $this->companyIds($request), true), 404, 'Compañía no encontrada.');
        $company = Company::query()->findOrFail($data['company_id']);
        $digest = $data['kind'] === Digest::DAILY ? $this->builder->daily($company) : $this->builder->weekly($company);
        $this->audit->record('digests.generated', $request->user(), auditable: $digest, new: ['kind' => $digest->kind, 'period' => $digest->period_key], companyId: $company->id);

        return response()->json(['data' => $this->summary($digest)], 201);
    }

    /** @return Builder<Digest> */
    private function visible(Request $request): Builder
    {
        return Digest::query()->whereIn('company_id', $this->companyIds($request));
    }

    /** @return list<int> compañías de las que la persona ve el resumen */
    private function companyIds(Request $request): array
    {
        $full = $this->scope->fullCompanyIds($request->user());

        return $full ?? Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function summary(Digest $d): array
    {
        return ['id' => $d->id, 'company_id' => $d->company_id, 'kind' => $d->kind, 'period_key' => $d->period_key, 'period_from' => $d->period_from->toDateString(), 'period_to' => $d->period_to->toDateString(), 'title' => $d->title, 'generated_at' => $d->generated_at->toIso8601String()];
    }
}
