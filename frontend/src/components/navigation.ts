export interface NavItem {
  to: string
  label: string
  /** Permiso requerido para ver el elemento; sin permiso, visible para todo usuario autenticado. */
  permission?: string
}

export const NAV_ITEMS: NavItem[] = [
  { to: '/', label: 'Inicio' },
  { to: '/admin/organizacion', label: 'Compañías y sucursales', permission: 'organization.manage' },
  { to: '/admin/usuarios', label: 'Usuarios', permission: 'users.manage' },
  { to: '/admin/auditoria', label: 'Auditoría', permission: 'audit.view' },
]
