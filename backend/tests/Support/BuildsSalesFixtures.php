<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;

/** Ventas con cifras exactas para probar los reportes: cada venta se declara con sus líneas y de ahí salen los totales esperados. */
trait BuildsSalesFixtures
{
    private int $documentNumber = 0;

    private ?int $batchId = null;

    protected function seller(Company $company, string $name, ?Branch $branch = null, ?int $userId = null): Seller
    {
        return Seller::create(['company_id' => $company->id, 'name' => $name, 'normalized_name' => Seller::normalize($name), 'is_active' => true, 'branch_id' => $branch?->id, 'user_id' => $userId]);
    }

    protected function operator(Company $company, string $name): Operator
    {
        return Operator::create(['company_id' => $company->id, 'name' => $name, 'normalized_name' => mb_strtolower($name)]);
    }

    /**
     * @param  list<array{net: float, qty?: float, price?: float, normal?: float|null, subtotal?: float|null, discount?: float|null, category?: string, code?: string, name?: string}>  $lines
     */
    protected function sale(Seller $seller, Operator $operator, Branch $branch, string $date, string $customer, array $lines, ?string $at = null): int
    {
        $this->batchId ??= ImportBatch::factory()->create()->id;
        $this->documentNumber++;
        $now = now();

        $id = DB::table('sales_documents')->insertGetId([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'A', 'number' => (string) $this->documentNumber, 'issued_on' => $date, 'issued_at' => $at,
            'customer_code' => $customer, 'customer_name' => 'Cliente '.$customer, 'seller_id' => $seller->id, 'operator_id' => $operator->id,
            'import_batch_id' => $this->batchId, 'lines_count' => count($lines), 'content_hash' => hash('sha256', "doc{$this->documentNumber}"),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($lines as $i => $line) {
            $qty = $line['qty'] ?? 1.0;
            $tax = round($line['net'] * 0.1, 4);
            DB::table('sales_lines')->insert([
                'sales_document_id' => $id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'issued_on' => $date, 'line_number' => $i + 1,
                'product_code' => $line['code'] ?? 'P'.($i + 1), 'product_name' => $line['name'] ?? 'Producto '.($i + 1), 'category_name' => $line['category'] ?? 'Categoría A',
                'units' => $qty, 'unit_cost' => 0, 'line_cost_total' => 0, 'unit_price' => $line['price'] ?? $line['net'] / $qty,
                'normal_price' => $line['normal'] ?? null, 'subtotal' => $line['subtotal'] ?? null, 'discount_total' => $line['discount'] ?? null,
                'net_total' => $line['net'], 'tax_total' => $tax, 'sale_plus_tax' => $line['net'] + $tax, 'grand_total' => $line['net'] + $tax,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $id;
    }
}
