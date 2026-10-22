export interface Company {
  id: number
  code: string
  name: string
  is_active: boolean
}

export interface Branch {
  id: number
  company_id: number
  code: string
  name: string
  is_active: boolean
}

export interface Role {
  id: number
  slug: string
  name: string
}

export interface AdminUser {
  id: number
  username: string
  name: string
  email: string | null
  is_active: boolean
  /** Tiene una clave temporal y aún no la ha cambiado. */
  must_change_password?: boolean
  last_login_at: string | null
  role: Role
  company_ids: number[]
  branch_ids: number[]
}

export interface AuditLog {
  id: number
  username: string | null
  event: string
  auditable_type: string | null
  auditable_id: number | null
  company_id: number | null
  ip_address: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  created_at: string
}

export interface Page<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

export interface PermissionInfo {
  slug: string
  name: string
  admin_only: boolean
}

export interface RoleMatrixRow {
  id: number
  slug: string
  name: string
  locked: boolean
  permissions: string[]
}

export interface RoleMatrix {
  permissions: PermissionInfo[]
  roles: RoleMatrixRow[]
}

export type ImportStatus = 'pending' | 'validating' | 'validated' | 'rejected' | 'importing' | 'imported' | 'discarded' | 'failed'

export interface ImportBatch {
  id: number
  type: string
  type_label: string
  status: ImportStatus
  status_label: string
  original_name: string
  file_size: number
  rows_total: number
  documents_total: number
  documents_new: number
  documents_duplicate: number
  errors_total: number
  warnings_total: number
  failure_message: string | null
  user?: { id: number; username: string; name: string }
  created_at: string
  validated_at: string | null
  finished_at: string | null
  can_confirm: boolean
  can_discard: boolean
  summary?: ImportSummary | null
}

export interface ImportSummary {
  rows_invalid?: number
  preview_columns?: string[]
  preview?: { row: number; values: Record<string, string | null> }[]
  date_from?: string | null
  date_to?: string | null
  totals?: { net_total: string; tax_total: string; grand_total: string; cost_total: string }
  companies?: { id: number; name: string; documents: number; lines: number; net_total: string }[]
  customers?: { new: number; updated: number; unchanged: number }
  products?: { new: number; updated: number; unchanged: number }
  credit?: {
    new: number; unchanged: number; linked_to_sales: number; without_sales: number; mismatches: number
    totals: { subtotal: string; discount_total: string; tax_total: string; total: string }
    companies: { id: number; name: string; documents: number }[]
    new_sellers: string[]; new_operators: string[]
  }
  inventory?: { new: number; unchanged: number; conflicts: number; branches: { id: number; name: string; products: number; cutoff: string | null }[] }
  entries?: { new: number; unchanged: number; lines: number; skipped_before_cutoff: number; branches_without_opening: string[] }
  units?: { bases_set: number; new: number; updated: number; unchanged: number }
  new_sellers?: string[]
  new_operators?: string[]
}

export interface ImportErrorRow {
  id: number
  row_number: number | null
  column: string | null
  code: string
  severity: 'error' | 'warning'
  message: string
  value: string | null
}

export interface ImportType {
  key: string
  label: string
}
