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
  meta: { page: number; has_more: boolean; costs_visible: boolean; open_shortfalls?: number; totals: { products: number; value?: string } }
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

export type ShortfallReason = 'insufficient' | 'no_product' | 'no_base_unit'

export const SHORTFALL_REASON: Record<ShortfallReason, string> = {
  insufficient: 'Existencias insuficientes',
  no_product: 'Producto fuera del catálogo',
  no_base_unit: 'Producto sin unidad base',
}

export interface ShortfallRow {
  id: number
  company_id: number
  branch_id: number
  branch: string
  issued_on: string
  document: string | null
  product: { id: number | null; code: string; name: string | null }
  requested: string
  applied: string
  missing: string
  reason: ShortfallReason
  resolved: boolean
}

export interface ShortfallList {
  data: ShortfallRow[]
  meta: { page: number; has_more: boolean; open_total: number }
}

export const REASON_LABEL: Record<string, string> = {
  shrinkage: 'Merma',
  damage: 'Daño',
  count_error: 'Error de conteo',
  defective: 'Producto defectuoso',
  wrong_order: 'Error en el pedido',
  other: 'Otro',
}

export const ADJUSTMENT_REASONS = ['shrinkage', 'damage', 'count_error', 'other'] as const
export const RETURN_REASONS = ['defective', 'wrong_order', 'other'] as const

export type MovementKind = 'adjustment_in' | 'adjustment_out' | 'return_in' | 'supplier_return' | 'transfer'

export const KIND_LABEL: Record<MovementKind, string> = {
  adjustment_in: 'Ajuste de entrada',
  adjustment_out: 'Ajuste de salida',
  return_in: 'Devolución de cliente (reingresa)',
  supplier_return: 'Devolución a proveedor (sale)',
  transfer: 'Traslado entre sucursales',
}

export interface MovementRow {
  id: number
  date: string
  type: InventoryMovementType
  reason: string | null
  branch: string
  product: { id: number; code: string; name: string }
  quantity: string
  entered: string
  entered_unit: string | null
  reference: string | null
  note: string | null
  user: string | null
  reversed: boolean
  reversible: boolean
  value?: string
}

export interface MovementList {
  data: MovementRow[]
  meta: { page: number; has_more: boolean; costs_visible: boolean }
}

export type RotationStatus = 'out_of_stock' | 'below_min' | 'no_rotation' | 'above_max' | 'ok'

export const ROTATION_LABEL: Record<RotationStatus, string> = {
  out_of_stock: 'Agotado',
  below_min: 'Bajo el mínimo',
  no_rotation: 'Sin rotación',
  above_max: 'Sobre el máximo',
  ok: 'Normal',
}

export interface RotationRow {
  company_id: number
  branch_id: number
  branch: string
  product: { id: number; code: string; name: string }
  stock: string
  sold: string
  daily_demand: string
  coverage_days: string | null
  rotation: string | null
  min: string
  max: string
  suggested: string | null
  last_sale: string | null
  days_since_sale: number | null
  status: RotationStatus
  value?: string
}

export interface RotationReport {
  data: RotationRow[]
  meta: {
    page: number
    has_more: boolean
    total: number
    counts: Record<RotationStatus, number>
    params: { demand_days: number; no_movement_days: number; reorder_days: number; safety_days: number; cycle_days: number }
  }
}
