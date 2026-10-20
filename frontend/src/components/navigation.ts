import { BarChart3, CalendarCheck, FileText, Contact, Package, Target, Building2, FileSpreadsheet, GitBranch, Home, KeyRound, ScrollText, Users, type LucideIcon } from 'lucide-react'

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
    title: 'Inteligencia',
    items: [
      { to: '/ventas', label: 'Ventas', icon: BarChart3, permission: 'sales.view' },
      { to: '/metas', label: 'Metas', icon: Target, permission: 'sales.view' },
      { to: '/clientes', label: 'Clientes', icon: Contact, permission: 'customers.view' },
      { to: '/agenda', label: 'Agenda', icon: CalendarCheck, permission: 'interactions.manage' },
      { to: '/cotizaciones', label: 'Cotizaciones', icon: FileText, permission: 'customers.view' },
      { to: '/productos', label: 'Productos', icon: Package, permission: 'sales.view' },
    ],
  },
  {
    title: 'Datos',
    items: [{ to: '/importaciones', label: 'Importaciones', icon: FileSpreadsheet, permission: 'imports.manage' }],
  },
  {
    title: 'Catálogos',
    items: [
      { to: '/admin/companias', label: 'Compañías', icon: Building2, permission: 'companies.manage' },
      { to: '/admin/sucursales', label: 'Sucursales', icon: GitBranch, permission: 'branches.manage' },
    ],
  },
  {
    title: 'Administración',
    items: [
      { to: '/admin/usuarios', label: 'Usuarios', icon: Users, permission: 'users.manage' },
      { to: '/admin/roles', label: 'Roles y permisos', icon: KeyRound, permission: 'roles.manage' },
      { to: '/admin/auditoria', label: 'Auditoría', icon: ScrollText, permission: 'audit.view' },
    ],
  },
]

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((g) => g.items)
