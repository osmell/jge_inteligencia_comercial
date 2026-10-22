<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditNoteRequest;
use App\Http\Requests\CreditPaymentRequest;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Services\CreditSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Captura de abonos (permiso credit.payments) y notas de crédito (permiso credit.notes, solo administrador/supervisor
 * según quién tenga el permiso) sobre las facturas abiertas de un cliente.
 */
class CreditSettlementController extends Controller
{
    public function __construct(private readonly CreditSettlement $settlement) {}

    /** Facturas con saldo de un cliente, de la más antigua a la más reciente, para repartir un abono o una nota. */
    public function openInvoices(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('credit.payments') || $user->hasPermission('credit.notes'), 403, 'No tienes permiso para realizar esta acción.');
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255']]);
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');
        $rows = CreditInvoice::query()->leftJoinSub($balances, 'm', 'm.credit_invoice_id', '=', 'credit_invoices.id')
            ->select('credit_invoices.*')->selectRaw('coalesce(m.balance, 0) as balance')
            ->where('credit_invoices.company_id', $data['company_id'])->where('credit_invoices.customer_code', $data['code'])
            ->whereRaw('coalesce(m.balance, 0) > 0')->orderBy('credit_invoices.issued_on')->orderBy('credit_invoices.id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (CreditInvoice $i) => [
            'id' => $i->id, 'document' => $i->documentNumber(), 'issued_on' => $i->issued_on->toDateString(),
            'total' => number_format((float) $i->total, 2, '.', ''), 'balance' => number_format((float) $i->getAttribute('balance'), 4, '.', ''),
        ])->all()]);
    }

    public function payment(CreditPaymentRequest $request): JsonResponse
    {
        $movements = $this->settlement->payment(
            $request->integer('company_id'), $request->string('code')->toString(), $request->allocations(), CarbonImmutable::parse($request->string('occurred_on')->toString()),
            $request->user(), ['method' => $request->input('method'), 'reference' => $request->input('reference'), 'note' => $request->input('note')],
        );

        return response()->json(['data' => ['movements' => count($movements)]], 201);
    }

    public function creditNote(CreditNoteRequest $request): JsonResponse
    {
        $note = $this->settlement->creditNote(
            $request->integer('company_id'), $request->string('code')->toString(),
            $request->only(['series', 'number', 'subtotal', 'tax_total', 'total', 'observations']),
            $request->allocations(), CarbonImmutable::parse($request->string('occurred_on')->toString()), $request->user(),
        );

        return response()->json(['data' => ['id' => $note->id, 'document' => "{$note->series}-{$note->number}"]], 201);
    }

    /** Anula un abono, o una nota de crédito completa. Cada tipo exige su permiso; los ajustes no se anulan por pantalla. */
    public function reverse(Request $request, int $movement): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $record = CreditMovement::query()->whereHas('invoice')->findOrFail($movement);
        $permission = match ($record->type) {
            CreditMovement::PAYMENT => 'credit.payments',
            CreditMovement::CREDIT_NOTE => 'credit.notes',
            default => abort(422, 'Este movimiento no se puede anular desde la pantalla.'),
        };
        abort_unless($request->user()->hasPermission($permission), 403, 'No tienes permiso para realizar esta acción.');

        $this->settlement->reverse($record, $request->user(), $data['note']);

        return response()->json(['data' => ['reversed' => true]]);
    }
}
