<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuotationAttachmentRequest;
use App\Http\Requests\QuotationLinkRequest;
use App\Http\Requests\QuotationRequest;
use App\Http\Requests\QuotationsListRequest;
use App\Http\Requests\QuotationStatusRequest;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Services\QuotationWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cotizaciones a clientes: listado, alta, edición, cambio de estado, vínculo con la factura y adjunto (PDF o foto, en el servidor).
 * Un vendedor solo llega a las cotizaciones de sus clientes (alcance del modelo Customer).
 */
class QuotationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly QuotationWorkflow $workflow) {}

    public function index(QuotationsListRequest $request): JsonResponse
    {
        $today = Carbon::today();
        $base = $this->visible()->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('code'), fn (Builder $q) => $q->whereHas('customer', fn (Builder $c) => $c->where('code', $request->string('code')->toString())))
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('user_id', $request->user()->id))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($request->string('q')->toString())).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(number) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(coalesce(external_reference, '')) like ? escape '\\'", [$like])
                    ->orWhereHas('customer', fn (Builder $c) => $c->whereRaw("lower(name) like ? escape '\\'", [$like])));
            });

        $counts = [];
        $day = $today->toDateString();   // generada aquí, no viene del usuario
        $grouped = (clone $base)->toBase()->reorder()
            ->selectRaw("status, (case when valid_until is not null and valid_until < '{$day}' then 1 else 0 end) as past, count(*) as total, coalesce(sum(amount), 0) as amount")
            ->groupBy('status', 'past')->get();
        foreach ($grouped as $r) {
            $key = in_array($r->status, Quotation::EXPIRABLE, true) && (int) $r->past === 1 ? Quotation::EXPIRED : $r->status;
            $counts[$key]['count'] = ($counts[$key]['count'] ?? 0) + (int) $r->total;
            $counts[$key]['amount'] = round(($counts[$key]['amount'] ?? 0) + (float) $r->amount, 2);
        }

        $status = $request->input('status');
        $query = clone $base;
        if ($status === Quotation::EXPIRED) {
            $query->whereIn('status', Quotation::EXPIRABLE)->whereDate('valid_until', '<', $today->toDateString());
        } elseif ($status !== null) {
            $query->where('status', $status);
            if (in_array($status, Quotation::EXPIRABLE, true)) {
                $query->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today->toDateString()));
            }
        }

        $page = $request->page();
        $rows = $query->with(['customer', 'user', 'seller', 'document'])->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE_SIZE)->map(fn (Quotation $q) => $this->present($q, $today))->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'counts' => $counts],
        ]);
    }

    public function show(Request $request, int $quotation): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($quotation))]);
    }

    public function store(QuotationRequest $request): JsonResponse
    {
        $customer = Customer::query()->where('company_id', $request->integer('company_id'))->where('code', $request->string('code')->toString())->first() ?? abort(404, 'Cliente no encontrado.');
        $data = $request->safe()->only(['amount', 'valid_until', 'description', 'external_reference', 'status']);

        return response()->json(['data' => $this->detail($this->workflow->create($request->user(), $customer, array_filter($data, fn ($v) => $v !== null)))], 201);
    }

    public function update(QuotationRequest $request, int $quotation): JsonResponse
    {
        $record = $this->find($quotation, 'manage');
        $data = $request->safe()->only(['amount', 'valid_until', 'description', 'external_reference']);
        if (array_key_exists('amount', $data) && $data['amount'] === null) {
            $data['amount'] = 0;
        }

        return response()->json(['data' => $this->detail($this->workflow->update($record, $request->user(), $data))]);
    }

    public function status(QuotationStatusRequest $request, int $quotation): JsonResponse
    {
        $record = $this->workflow->changeStatus(
            $this->find($quotation, 'manage'), $request->user(), $request->validated('status'),
            $request->validated('loss_reason'), $request->validated('loss_note'), $request->validated('note'),
        );

        return response()->json(['data' => $this->detail($record)]);
    }

    /** Facturas del cliente que se pueden vincular (misma compañía y cliente, desde la emisión, sin otra cotización). */
    public function documents(Request $request, int $quotation): JsonResponse
    {
        $record = $this->find($quotation, 'manage');
        $query = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->where('sales_documents.company_id', $record->company_id)
            ->where('sales_documents.customer_code', $record->customer?->code)
            ->whereNotIn('sales_documents.id', Quotation::withoutGlobalScopes()->whereNotNull('sales_document_id')->select('sales_document_id'))
            ->when($record->issued_at, fn (Builder $q) => $q->where('sales_documents.issued_on', '>=', $record->issued_at?->toDateString()))
            ->toBase()
            ->selectRaw('sales_documents.id as id, sales_documents.series as series, sales_documents.number as number, sales_documents.issued_on as issued_on, sum(sl.net_total) as net_total')
            ->groupBy('sales_documents.id', 'sales_documents.series', 'sales_documents.number', 'sales_documents.issued_on')
            ->orderByDesc('sales_documents.issued_on')->orderByDesc('sales_documents.id')->limit(50)->get();

        return response()->json(['data' => $query->map(fn ($d) => [
            'id' => (int) $d->id, 'document' => "{$d->series}-{$d->number}", 'issued_on' => Carbon::parse($d->issued_on)->toDateString(), 'net_total' => number_format((float) $d->net_total, 2, '.', ''),
        ])->all()]);
    }

    public function link(QuotationLinkRequest $request, int $quotation): JsonResponse
    {
        $record = $this->find($quotation, 'manage');
        $document = SalesDocument::query()->find($request->integer('document_id')) ?? abort(404, 'Factura no encontrada.');

        return response()->json(['data' => $this->detail($this->workflow->link($record, $request->user(), $document))]);
    }

    public function unlink(Request $request, int $quotation): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->unlink($this->find($quotation, 'unlink'), $request->user()))]);
    }

    public function attach(QuotationAttachmentRequest $request, int $quotation): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->attach($this->find($quotation, 'manage'), $request->user(), $request->file('file')))]);
    }

    public function detach(Request $request, int $quotation): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->workflow->detach($this->find($quotation, 'manage'), $request->user()))]);
    }

    public function download(int $quotation): StreamedResponse
    {
        $record = $this->find($quotation, 'download');
        abort_if($record->attachment_path === null || ! Storage::disk('quotations')->exists($record->attachment_path), 404, 'La cotización no tiene adjunto.');

        return Storage::disk('quotations')->download($record->attachment_path, $record->attachment_name, [
            'Content-Type' => $record->attachment_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<Quotation> */
    private function visible(): Builder
    {
        return Quotation::query()->whereHas('customer');
    }

    /** Busca la cotización dentro del alcance de la persona (404 si no la ve) y comprueba que pueda hacer `$ability` con ella. */
    private function find(int $id, string $ability = 'view'): Quotation
    {
        $record = $this->visible()->with('customer')->find($id) ?? abort(404, 'Cotización no encontrada.');
        $this->authorize($ability, $record);

        return $record;
    }

    /** @return array<string, mixed> */
    private function present(Quotation $q, ?Carbon $today = null): array
    {
        return [
            'id' => $q->id, 'number' => $q->number, 'external_reference' => $q->external_reference,
            'status' => $q->status, 'effective_status' => $q->effectiveStatus($today), 'amount' => number_format((float) $q->amount, 2, '.', ''),
            'valid_until' => $q->valid_until?->toDateString(), 'issued_at' => $q->issued_at?->toIso8601String(),
            'customer' => ['company_id' => $q->company_id, 'code' => $q->customer?->code, 'name' => $q->customer?->name, 'do_not_contact' => $q->customer?->do_not_contact],
            'seller' => $q->seller?->name, 'user' => $q->user ? ['id' => $q->user->id, 'name' => $q->user->name] : null,
            'has_attachment' => $q->attachment_path !== null,
            'document' => $q->document ? ['id' => $q->document->id, 'number' => "{$q->document->series}-{$q->document->number}", 'issued_on' => $q->document->issued_on->toDateString()] : null,
            'loss_reason' => $q->loss_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Quotation $q): array
    {
        $q->loadMissing(['customer', 'user', 'seller', 'document']);
        $q->load('events.user');

        return $this->present($q) + [
            'description' => $q->description, 'loss_note' => $q->loss_note, 'converted_at' => $q->converted_at?->toIso8601String(),
            'attachment' => $q->attachment_path ? ['name' => $q->attachment_name, 'mime' => $q->attachment_mime, 'size' => $q->attachment_size] : null,
            'transitions' => array_values(array_diff(Quotation::TRANSITIONS[$q->status] ?? [], ['draft'])),
            'events' => $q->events->map(fn ($e) => [
                'from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note, 'at' => $e->created_at->toIso8601String(), 'user' => $e->user?->name,
            ])->all(),
        ];
    }
}
