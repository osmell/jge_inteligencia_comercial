export interface AuthUser {
  id: number
  username: string
  name: string
  role: { slug: string; name: string }
  permissions: string[]
  companies: { id: number; code: string; name: string }[]
  branches: { id: number; company_id: number; code: string; name: string }[]
}
