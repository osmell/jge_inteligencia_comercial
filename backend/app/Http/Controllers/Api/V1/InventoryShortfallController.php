<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Inventory\Quantity;
use App\Jobs\SyncSalesInventoryJob;
use App\Models\InventoryShortfall;
use App\Models\SalesLine;
use App\Services\SalesInventorySync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ventas que no pudieron descontar todo su inventario («faltantes por conciliar»). */
class InventoryShortfallController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly SalesInventorySync $sync) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'all'])], 'company_id' => ['nullable', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $status = $data['status'] ?? 'open';
        $page = max(1, (int) ($data['page'] ?? 1));
        $like = isset($data['q']) ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($data['q'])).'%' : null;

        $base = InventoryShortfall::query()
            ->when(! empty($data['company_id']), fn (Builder $q) => $q->where('company_id', $data['company_id']))
            ->when(! empty($data['branch_id']), fn (Builder $q) => $q->where('branch_id', $data['branch_id']))
            ->when($like !== null, fn (Builder $q) => $q->whereRaw("lower(product_code) like ? escape '\\'", [$like]));
        $open = (clone $base)->whereNull('resolved_at')->count();

        $rows = (clone $base)->with(['branch:id,name', 'product:id,name'])
            ->when($status === 'open', fn (Builder $q) => $q->whereNull('resolved_at'))->when($status === 'resolved', fn (Builder $q) => $q->whereNotNull('resolved_at'))
            ->orderBy('issued_on')->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        $lines = SalesLine::documentNumbers($rows->pluck('sales_line_id'));

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (InventoryShortfall $s) => [
                'id' => $s->id, 'company_id' => $s->company_id, 'branch_id' => $s->branch_id, 'branch' => $s->branch->name, 'issued_on' => $s->issued_on->toDateString(),
                'document' => $lines[$s->sales_line_id] ?? null,
                'product' => ['id' => $s->product_id, 'code' => $s->product_code, 'name' => $s->product?->name],
                'requested' => Quantity::display(Quantity::units((string) $s->requested)), 'applied' => Quantity::display(Quantity::units((string) $s->applied)),
                'missing' => Quantity::display(Quantity::units((string) $s->missing)), 'reason' => $s->reason, 'resolved' => $s->resolved_at !== null,
            ])->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'open_total' => $open],
        ]);
    }

    /** Aplica ahora lo que faltaba de una venta, con las existencias disponibles. */
    public function reconcile(Request $request, int $shortfall): JsonResponse
    {
        $result = $this->sync->reconcile(InventoryShortfall::query()->findOrFail($shortfall), $request->user());

        return response()->json(['data' => $result]);
    }

    /** Vuelve a revisar las ventas pendientes (por ejemplo, tras cargar el inventario inicial); se hace en segundo plano. */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1']]);
        SyncSalesInventoryJob::dispatch($data['company_id'] ?? null, $data['branch_id'] ?? null);

        return response()->json(['data' => ['queued' => true]], 202);
    }
}
