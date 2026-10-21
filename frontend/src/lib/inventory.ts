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
