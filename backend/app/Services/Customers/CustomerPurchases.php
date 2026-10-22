<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Product;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;

/** Facturas de un cliente y sus líneas, con el ámbito de compañía/sucursal del usuario aplicado por los global scopes. */
class CustomerPurchases
{
    public const PER_PAGE = 20;

    /**
     * Facturas del cliente, de la más reciente a la más antigua.
     *
     * @return array{rows: array<int, array<string, mixed>>, has_more: bool}
     */
    public function purchases(int $companyId, string $code, int $page): array
    {
        $rows = SalesDocument::query()
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sales_documents.id')
            ->join('branches', 'branches.id', '=', 'sales_documents.branch_id')
            ->join('sellers', 'sellers.id', '=', 'sales_documents.seller_id')
            ->join('operators', 'operators.id', '=', 'sales_documents.operator_id')
            ->where('sales_documents.company_id', $companyId)
            ->where('sales_documents.customer_code', $code)
            ->toBase()
            ->selectRaw('sales_documents.id as id, sales_documents.series as series, sales_documents.number as number, sales_documents.issued_on as issued_on')
            ->selectRaw('max(branches.name) as branch, max(sellers.name) as seller, max(operators.name) as operator, count(sl.id) as lines')
            ->selectRaw('sum(sl.net_total) as net_total, sum(sl.grand_total) as grand_total')
            ->groupBy('sales_documents.id', 'sales_documents.series', 'sales_documents.number', 'sales_documents.issued_on')
            ->orderByDesc('sales_documents.issued_on')->orderByDesc('sales_documents.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)
            ->get();

        $page_rows = $rows->take(self::PER_PAGE);

        return [
            'rows' => $page_rows->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => "{$r->series}-{$r->number}", 'issued_on' => $this->date($r->issued_on),
                'branch' => (string) $r->branch, 'seller' => (string) $r->seller, 'operator' => (string) $r->operator, 'lines' => (int) $r->lines,
                'net_total' => number_format((float) $r->net_total, 2, '.', ''), 'grand_total' => number_format((float) $r->grand_total, 2, '.', ''),
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Líneas de una factura del cliente. Null si la factura no es de ese cliente o está fuera del ámbito del usuario.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function documentLines(int $companyId, string $code, int $documentId): ?array
    {
        $document = SalesDocument::query()->where('company_id', $companyId)->where('customer_code', $code)->find($documentId);

        if ($document === null) {
            return null;
        }

        $lines = $document->lines()->toBase()->get();
        // Categoría = Nivel 3 del catálogo de productos; sin catálogo, la del archivo de ventas.
        $levels = Product::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', $lines->pluck('product_code')->all())->pluck('level3', 'code');

        return $lines->map(fn ($l) => [
            'line' => (int) $l->line_number, 'product_code' => $l->product_code, 'product_name' => $l->product_name, 'category' => ($levels[$l->product_code] ?? null) ?: $l->category_name,
            'units' => number_format((float) $l->units, 2, '.', ''), 'net_total' => number_format((float) $l->net_total, 2, '.', ''),
            'grand_total' => number_format((float) $l->grand_total, 2, '.', ''),
        ])->all();
    }

    private function date(mixed $value): string
    {
        return CarbonImmutable::parse($value)->toDateString();
    }
}
