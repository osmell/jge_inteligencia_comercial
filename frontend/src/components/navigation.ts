import { Building2, Home, ScrollText, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Permiso requerido para ver el elemento; sin permiso, visible para todo usuario autenticado. */
  permission?: string
}

export interface NavGroup {
  title: string
  items: NavItem[]
}

export const NAV_GROUPS: NavGroup[] = [
  { title: 'General', items: [{ to: '/', label: 'Inicio', icon: Home }] },
  {
    title: 'Administración',
    items: [
      { to: '/admin/organizacion', label: 'Compañías y sucursales', icon: Building2, permission: 'organization.manage' },
      { to: '/admin/usuarios', label: 'Usuarios', icon: Users, permission: 'users.manage' },
      { to: '/admin/auditoria', label: 'Auditoría', icon: ScrollText, permission: 'audit.view' },
    ],
  },
]

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((g) => g.items)
