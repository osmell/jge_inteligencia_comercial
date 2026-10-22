<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CrossSellOpportunity;
use App\Models\CrossSellRule;
use App\Models\InsightRun;
use App\Services\CrossSellAnalysis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Venta cruzada: reglas de asociación, oportunidades por cliente (con el alcance del usuario) y recálculo. */
class InsightCrossSellController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly CrossSellAnalysis $analysis) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        Company::query()->findOrFail($data['company_id']);
        $run = $this->latestRun((int) $data['company_id']);

        return response()->json([
            'data' => CrossSellRule::query()->where('company_id', $data['company_id'])->orderByDesc('confidence')->orderByDesc('lift')->limit(100)->get()->map(fn (CrossSellRule $r) => $this->presentRule($r))->all(),
            'meta' => ['run' => $this->presentRun($run)],
        ]);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'target' => ['nullable', 'string', 'max:255'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));

        $base = CrossSellOpportunity::query()->visibleCustomers()->where('cross_sell_opportunities.company_id', $data['company_id'])
            ->when(! empty($data['target']), fn (Builder $q) => $q->whereHas('rule', fn (Builder $r) => $r->where('consequent', $data['target'])))
            ->when(! empty($data['q']), function (Builder $q) use ($data) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($data['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(customer_name) like ? escape '\\'", [$like])->orWhereRaw("lower(customer_code) like ? escape '\\'", [$like]));
            });
        $rows = (clone $base)->with('rule')->orderBy('customer_name')->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (CrossSellOpportunity $o) => $this->presentOpportunity($o))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'total' => (clone $base)->count(), 'run' => $this->presentRun($this->latestRun((int) $data['company_id']))],
        ]);
    }

    /** Oportunidades de un cliente para su ficha. */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255']]);
        $rows = CrossSellOpportunity::query()->visibleCustomers()->with('rule')->where(['cross_sell_opportunities.company_id' => $data['company_id'], 'customer_code' => $data['code']])->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (CrossSellOpportunity $o) => $this->presentOpportunity($o))->all(), 'meta' => ['run' => $this->presentRun($this->latestRun((int) $data['company_id']))]]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->presentRun($this->analysis->compute(Company::query()->findOrFail($data['company_id']), $request->user()))]);
    }

    private function latestRun(int $companyId): ?InsightRun
    {
        return InsightRun::query()->where(['company_id' => $companyId, 'analysis' => 'cross_sell'])->orderByDesc('computed_at')->orderByDesc('id')->first();
    }

    /** @return array<string, mixed>|null */
    private function presentRun(?InsightRun $run): ?array
    {
        return $run === null ? null : [
            'id' => $run->id, 'status' => $run->status, 'computed_at' => $run->computed_at->toIso8601String(), 'period_from' => $run->period_from?->toDateString(), 'period_to' => $run->period_to?->toDateString(),
            'note' => $run->note, 'customers' => $run->summary['customers'] ?? null, 'rules' => $run->summary['rules'] ?? null, 'opportunities' => $run->summary['opportunities'] ?? null,
            'level' => ($run->parameters['cross_sell.level'] ?? 2) === 1 ? 'línea' : 'categoría',
            'parameters' => array_intersect_key($run->parameters, array_flip(['cross_sell.min_confidence_pct', 'cross_sell.min_support_customers', 'cross_sell.period_months', 'cross_sell.level', 'cross_sell.max_per_customer'])),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRule(CrossSellRule $r): array
    {
        return [
            'id' => $r->id, 'antecedent' => $r->antecedent, 'consequent' => $r->consequent, 'antecedent_customers' => $r->antecedent_customers, 'consequent_customers' => $r->consequent_customers,
            'both_customers' => $r->both_customers, 'total_customers' => $r->total_customers, 'confidence_pct' => round((float) $r->confidence * 100, 1), 'lift' => round((float) $r->lift, 2), 'explanation' => $r->explain(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOpportunity(CrossSellOpportunity $o): array
    {
        $r = $o->rule;

        return [
            'code' => $o->customer_code, 'name' => $o->customer_name, 'suggestion' => $r->consequent, 'because' => $r->antecedent, 'confidence_pct' => round((float) $r->confidence * 100, 1),
            'support' => $r->both_customers, 'lift' => round((float) $r->lift, 2),
            'explanation' => "Compra «{$r->antecedent}» y todavía no «{$r->consequent}». ".$r->explain(),
        ];
    }
}
