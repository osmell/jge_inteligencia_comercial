<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * Mover una sucursal a otra compañía solo es posible si no tiene datos relacionados: ventas, clientes, cartera, inventario, usuarios
 * asignados ni vendedores propios. El vendedor «Tienda <sucursal>» que se crea solo con la sucursal se acompaña si no tiene datos.
 */
class BranchRelocation
{
    /** Tabla => cómo se nombra en el mensaje (singular, plural). */
    private const TABLES = [
        'sales_documents' => ['documento de venta', 'documentos de venta'],
        'sales_lines' => ['línea de venta', 'líneas de venta'],
        'credit_invoices' => ['factura de crédito', 'facturas de crédito'],
        'customers' => ['cliente', 'clientes'],
        'inventory_movements' => ['movimiento de inventario', 'movimientos de inventario'],
        'inventory_balances' => ['existencia de inventario', 'existencias de inventario'],
        'inventory_receipts' => ['recepción de inventario', 'recepciones de inventario'],
        'inventory_shortfalls' => ['faltante de inventario', 'faltantes de inventario'],
        'branch_user' => ['usuario con acceso a la sucursal', 'usuarios con acceso a la sucursal'],
    ];

    /** @return list<array{label: string, count: int}> lo que impide mover la sucursal (vacío = se puede mover) */
    public function blockers(Branch $branch): array
    {
        $out = [];
        foreach (self::TABLES as $table => [$one, $many]) {
            $n = DB::table($table)->where('branch_id', $branch->id)->count();
            if ($n > 0) {
                $out[] = ['label' => $n === 1 ? $one : $many, 'count' => $n];
            }
        }
        $store = $this->storeSeller($branch);
        $sellers = DB::table('sellers')->where('branch_id', $branch->id)->when($store !== null, fn ($q) => $q->where('id', '!=', $store?->id))->count();
        if ($sellers > 0) {
            $out[] = ['label' => $sellers === 1 ? 'vendedor asignado a la sucursal' : 'vendedores asignados a la sucursal', 'count' => $sellers];
        }
        if ($store !== null && $this->sellerHasData($store)) {
            $out[] = ['label' => 'vendedor «'.$store->name.'» con ventas, clientes, cotizaciones o metas', 'count' => 1];
        }

        return $out;
    }

    /** Mueve la sucursal (el llamador ya comprobó que no hay bloqueos). */
    public function move(Branch $branch, int $companyId): void
    {
        DB::transaction(function () use ($branch, $companyId) {
            $store = $this->storeSeller($branch);
            $branch->update(['company_id' => $companyId]);
            if ($store !== null) {
                // El vendedor de la tienda anterior queda inactivo y sin sucursal; en la nueva compañía se crea (o reutiliza) el suyo.
                $store->forceFill(['branch_id' => null, 'is_default' => false, 'is_active' => false])->save();
                Seller::ensureStoreSeller($branch->refresh());
            }
        });
    }

    private function storeSeller(Branch $branch): ?Seller
    {
        return Seller::query()->where(['branch_id' => $branch->id, 'is_default' => true, 'company_id' => $branch->company_id])->first();
    }

    private function sellerHasData(Seller $seller): bool
    {
        foreach (['sales_documents', 'credit_invoices', 'seller_goals', 'quotations', 'customers'] as $table) {
            if (DB::table($table)->where('seller_id', $seller->id)->exists()) {
                return true;
            }
        }

        return $seller->user_id !== null || $seller->supervisor_id !== null;
    }
}
