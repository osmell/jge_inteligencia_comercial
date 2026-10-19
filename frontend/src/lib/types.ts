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
