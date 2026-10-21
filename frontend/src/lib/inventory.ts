export interface Unit {
  id: number
  code: string
  name: string
  active: boolean
}

export interface InventoryProductRow {
  id: number
  company_id: number
  code: string
  name: string
  base_unit: { id: number; code: string; name: string } | null
  equivalences_count: number
}

export interface ProductUnits {
  id: number
  company_id: number
  code: string
  name: string
  base_unit: { id: number; code: string; name: string } | null
  equivalences: { unit_id: number; unit: string; factor: string }[]
}

export interface StockRow {
  company_id: number
  company: string
  branch_id: number
  branch: string
  product: { id: number; code: string; name: string }
  unit: string | null
  quantity: string
  reconciled: boolean
  value?: string
  average_cost?: string | null
}

export interface StockList {
  data: StockRow[]
  meta: { page: number; has_more: boolean; costs_visible: boolean; totals: { products: number; value?: string } }
}

export type InventoryMovementType = 'opening' | 'entry' | 'return_in' | 'transfer_in' | 'adjustment_in' | 'sale' | 'supplier_return' | 'transfer_out' | 'adjustment_out' | 'reversal'

export const MOVEMENT_TYPE_LABEL: Record<InventoryMovementType, string> = {
  opening: 'Inventario inicial',
  entry: 'Entrada',
  return_in: 'Devolución de cliente',
  transfer_in: 'Traslado recibido',
  adjustment_in: 'Ajuste (entrada)',
  sale: 'Venta',
  supplier_return: 'Devolución a proveedor',
  transfer_out: 'Traslado enviado',
  adjustment_out: 'Ajuste (salida)',
  reversal: 'Reversa',
}

export interface KardexRow {
  id: number
  date: string
  type: InventoryMovementType
  quantity: string
  entered: string
  entered_unit: string | null
  reference: string | null
  note: string | null
  user: string | null
  reverses_id: number | null
  reversed: boolean
  balance: string
  value?: string
  balance_value?: string
}

export interface Kardex {
  data: {
    opening: { quantity: string; value?: string }
    rows: KardexRow[]
    truncated: boolean
    closing: { quantity: string; value?: string }
    reconciled: boolean
  }
  meta: { product: { id: number; code: string; name: string; unit: string | null }; branch: string; costs_visible: boolean }
}
