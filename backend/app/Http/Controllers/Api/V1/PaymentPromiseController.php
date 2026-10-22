<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentPromise;
use App\Services\PaymentPromises;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Promesas de pago: consulta (credit.view) y registro/cancelación (credit.promises). */
class PaymentPromiseController extends Controller
{
    public function __construct(private readonly PaymentPromises $promises) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'code' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['pending', 'open', 'kept', 'broken', 'cancelled', 'all'])],
            'horizon_days' => ['nullable', 'integer', 'min:0', 'max:90'], 'mine' => ['nullable', 'boolean'],
        ]);
        $result = $this->promises->list($request->user(), [...$data, 'mine' => $request->boolean('mine')]);

        return response()->json(['data' => $result['rows'], 'meta' => ['counts' => $result['counts']]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255'],
            'promised_on' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $promise = $this->promises->create($request->user(), $data['company_id'], $data['code'], CarbonImmutable::parse($data['promised_on']), $data['amount'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $promise->id]], 201);
    }

    public function cancel(Request $request, int $promise): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->promises->cancel(PaymentPromise::query()->findOrFail($promise), $request->user(), $data['reason']);

        return response()->json(['data' => ['cancelled' => true]]);
    }
}
