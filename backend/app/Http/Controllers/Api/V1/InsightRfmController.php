<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InsightRun;
use App\Models\RfmScore;
use App\Services\AuditLogger;
use App\Services\Reports\CsvSafe;
use App\Services\RfmSegmentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Segmentación RFM: consulta por compañía, ficha del cliente, exportación y recálculo. */
class InsightRfmController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly RfmSegmentation $rfm, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $company = Company::query()->findOrFail((int) $data['company_id']);
        $run = $this->latestRun($company->id);
        $page = max(1, (int) ($data['page'] ?? 1));

        $base = fn () => RfmScore::query()->visibleCustomers()->where('company_id', $company->id)
            ->when(! empty($data['q']), function (Builder $q) use ($data) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) ($data['q'] ?? ''))).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(customer_name) like ? escape '\\'", [$like])->orWhereRaw("lower(customer_code) like ? escape '\\'", [$like]));
            });
        $counts = array_fill_keys(array_keys(RfmSegmentation::SEGMENTS), 0);
        foreach ($base()->selectRaw('segment, count(*) as total')->groupBy('segment')->pluck('total', 'segment') as $segment => $total) {
            $counts[$segment] = (int) $total;
        }

        $rows = $base()->when(! empty($data['segment']), fn (Builder $q) => $q->where('segment', ($data['segment'] ?? null)))
            ->orderByDesc('monetary')->orderBy('customer_name')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (RfmScore $s) => $this->present($s))->values()->all(),
            'meta' => [
                'page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'counts' => $counts, 'run' => $this->presentRun($run),
                'segments' => collect(RfmSegmentation::SEGMENTS)->map(fn (array $s, string $key) => ['key' => $key] + $s)->values()->all(),
            ],
        ]);
    }

    /** Segmento de un cliente para su ficha. */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255']]);
        $score = RfmScore::query()->visibleCustomers()->where(['company_id' => $data['company_id'], 'customer_code' => $data['code']])->first();

        return response()->json(['data' => $score ? $this->present($score) : null, 'meta' => ['run' => $this->presentRun($this->latestRun((int) $data['company_id']))]]);
    }

    public function export(Request $request): Response
    {
        $data = $this->validated($request);
        $company = Company::query()->findOrFail((int) $data['company_id']);
        $run = $this->latestRun($company->id);
        $this->audit->record('insights.rfm_exported', $request->user(), new: ['company_id' => $company->id, 'segment' => $data['segment'] ?? null], companyId: $company->id);

        $rows = RfmScore::query()->visibleCustomers()->where('company_id', $company->id)->when(! empty($data['segment']), fn (Builder $q) => $q->where('segment', ($data['segment'] ?? null)))->orderByDesc('monetary')->orderBy('customer_name')->get();

        return response()->streamDownload(function () use ($rows, $run, $company) {
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('No se pudo abrir la salida de la descarga.');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Compañía', 'Código cliente', 'Cliente', 'Segmento', 'Última compra', 'Recencia (días)', 'Días con compra', 'Venta bruta', 'Devoluciones', 'Venta neta', 'R', 'F', 'M', 'Explicación', 'Calculado', 'Periodo']);
            foreach ($rows as $s) {
                fputcsv($out, [
                    CsvSafe::value($company->name), CsvSafe::value($s->customer_code), CsvSafe::value($s->customer_name), RfmSegmentation::SEGMENTS[$s->segment]['label'],
                    $s->last_purchase->toDateString(), $s->recency_days, $s->frequency, $s->gross, $s->returns, $s->monetary, $s->r, $s->f, $s->m, RfmSegmentation::explain($this->present($s)),
                    $run?->computed_at->toDateTimeString(), $run ? $run->period_from?->toDateString().' a '.$run->period_to?->toDateString() : '',
                ]);
            }
            fclose($out);
        }, 'segmentacion-rfm-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $run = $this->rfm->compute(Company::query()->findOrFail((int) $data['company_id']), $request->user());

        return response()->json(['data' => $this->presentRun($run)]);
    }

    /** @return array{company_id: int, segment?: string, q?: string, page?: int} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required', 'integer', 'min:1'], 'segment' => ['nullable', Rule::in(array_keys(RfmSegmentation::SEGMENTS))],
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
    }

    private function latestRun(int $companyId): ?InsightRun
    {
        return InsightRun::query()->where(['company_id' => $companyId, 'analysis' => 'rfm'])->orderByDesc('computed_at')->orderByDesc('id')->first();
    }

    /** @return array<string, mixed>|null */
    private function presentRun(?InsightRun $run): ?array
    {
        return $run === null ? null : [
            'id' => $run->id, 'status' => $run->status, 'computed_at' => $run->computed_at->toIso8601String(), 'period_from' => $run->period_from?->toDateString(), 'period_to' => $run->period_to?->toDateString(),
            'note' => $run->note, 'customers' => $run->summary['customers'] ?? null,
            'parameters' => array_intersect_key($run->parameters, array_flip(['rfm.period_months', 'rfm.min_history_months', 'rfm.min_customers', 'rfm.new_customer_days'])),
        ];
    }

    /** @return array<string, mixed> */
    private function present(RfmScore $s): array
    {
        $row = [
            'code' => $s->customer_code, 'name' => $s->customer_name, 'segment' => $s->segment, 'segment_label' => RfmSegmentation::SEGMENTS[$s->segment]['label'],
            'first_purchase' => $s->first_purchase->toDateString(), 'last_purchase' => $s->last_purchase->toDateString(), 'recency_days' => $s->recency_days, 'frequency' => $s->frequency,
            'gross' => $s->gross, 'returns' => $s->returns, 'monetary' => $s->monetary, 'r' => $s->r, 'f' => $s->f, 'm' => $s->m,
        ];
        $row['explanation'] = RfmSegmentation::explain($row);

        return $row;
    }
}
