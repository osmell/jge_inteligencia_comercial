export interface AuthUser {
  id: number
  username: string
  name: string
  role: { slug: string; name: string }
  permissions: string[]
  /** Opciones del menú que la administración le ocultó (llaves de `navigation.ts`). */
  hidden_menu?: string[]
  /** Clave temporal (alta nueva o restablecida): hasta cambiarla, el servidor solo permite ver el perfil, cambiar la clave y salir. */
  must_change_password?: boolean
  companies: { id: number; code: string; name: string }[]
  branches: { id: number; company_id: number; code: string; name: string }[]
}
