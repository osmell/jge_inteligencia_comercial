<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditInvoicesRequest;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Services\CreditAging;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de facturas de crédito con su saldo (calculado desde el libro de movimientos) y su antigüedad.
 * Un vendedor solo ve las facturas que le corresponden; el resto, su ámbito de compañías y sucursales.
 */
class CreditInvoiceController extends Controller
{
    private const PAGE_SIZE = 20;

    public function index(CreditInvoicesRequest $request): JsonResponse
    {
        $today = CarbonImmutable::today();
        $query = $this->withBalance()
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('credit_invoices.company_id', $request->integer('company_id')))
            ->when($request->filled('code'), fn (Builder $q) => $q->where('credit_invoices.customer_code', $request->string('code')->toString()))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($request->string('q')->toString())).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(credit_invoices.number) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(credit_invoices.series || '-' || credit_invoices.number) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(credit_invoices.customer_name) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(credit_invoices.customer_code) like ? escape '\\'", [$like]));
            });

        // Totales del conjunto filtrado (antes de aplicar estado/tramo/página).
        $open = (clone $query)->whereRaw('coalesce(m.balance, 0) > 0');
        $meta = [
            'open_count' => (clone $open)->toBase()->count(),
            'open_balance' => number_format((float) (clone $open)->toBase()->sum(DB::raw('coalesce(m.balance, 0)')), 2, '.', ''),
        ];

        $status = $request->input('status', 'open');
        if ($status === 'open') {
            $query->whereRaw('coalesce(m.balance, 0) > 0');
        } elseif ($status === 'paid') {
            $query->whereRaw('coalesce(m.balance, 0) <= 0');
        }
        if ($request->filled('bucket')) {
            $this->filterBucket($query, $request->string('bucket')->toString(), $today);
        }

        $page = $request->page();
        $rows = $query->with(['company', 'seller'])->orderBy('credit_invoices.issued_on')->orderBy('credit_invoices.id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (CreditInvoice $i) => $this->present($i, $today))->values()->all(),
            'meta' => $meta + ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'buckets' => CreditAging::buckets()],
        ]);
    }

    public function show(int $invoice): JsonResponse
    {
        $record = $this->withBalance()->with(['company', 'seller', 'branch'])->where('credit_invoices.id', $invoice)->first() ?? abort(404, 'Factura no encontrada.');
        $movements = $record->movements()->with('user')->get();
        $reversed = $movements->whereNotNull('reverses_id')->pluck('reverses_id')->all();

        return response()->json(['data' => $this->present($record, CarbonImmutable::today()) + [
            'branch' => $record->branch->name, 'subtotal' => $this->money($record->subtotal), 'discount_total' => $this->money($record->discount_total), 'tax_total' => $this->money($record->tax_total),
            'movements' => $movements->map(fn (CreditMovement $m) => [
                'id' => $m->id, 'type' => $m->type, 'amount' => $this->money($m->amount), 'occurred_on' => $m->occurred_on->toDateString(),
                'method' => $m->method, 'reference' => $m->reference, 'note' => $m->note, 'user' => $m->user?->name, 'reverses_id' => $m->reverses_id,
                'reversed' => in_array($m->id, $reversed, true),
            ])->all(),
        ]]);
    }

    /** @return Builder<CreditInvoice> */
    private function withBalance(): Builder
    {
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');

        return CreditInvoice::query()->leftJoinSub($balances, 'm', 'm.credit_invoice_id', '=', 'credit_invoices.id')
            ->select('credit_invoices.*')->selectRaw('coalesce(m.balance, 0) as balance');
    }

    /** @param  Builder<CreditInvoice>  $query */
    private function filterBucket(Builder $query, string $bucket, CarbonImmutable $today): void
    {
        $query->whereRaw('coalesce(m.balance, 0) > 0');
        if ($bucket === CreditAging::CURRENT) {
            $query->where('credit_invoices.issued_on', '>', $today->subDays(1)->toDateString());

            return;
        }
        if (str_ends_with($bucket, '+')) {
            $query->where('credit_invoices.issued_on', '<=', $today->subDays((int) $bucket)->toDateString());

            return;
        }
        [$from, $to] = array_map('intval', explode('-', $bucket));
        $query->where('credit_invoices.issued_on', '<=', $today->subDays($from)->toDateString())->where('credit_invoices.issued_on', '>=', $today->subDays($to)->toDateString());
    }

    /** @return array<string, mixed> */
    private function present(CreditInvoice $i, CarbonImmutable $today): array
    {
        $balance = (float) ($i->getAttribute('balance') ?? 0);
        $age = (int) CarbonImmutable::parse($i->issued_on)->startOfDay()->diffInDays($today, false);

        return [
            'id' => $i->id, 'company_id' => $i->company_id, 'company' => $i->company->name, 'document' => $i->documentNumber(), 'issued_on' => $i->issued_on->toDateString(),
            'customer' => ['code' => $i->customer_code, 'name' => $i->customer_name], 'seller' => $i->seller->name,
            'total' => $this->money($i->total), 'balance' => number_format($balance, 2, '.', ''), 'paid' => number_format((float) $i->total - $balance, 2, '.', ''),
            'age_days' => max(0, $age), 'bucket' => $balance > 0 ? CreditAging::bucket($age) : null, 'sales_linked' => $i->sales_document_id !== null,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
