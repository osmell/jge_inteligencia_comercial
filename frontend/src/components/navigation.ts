import { ListChecks, PackageSearch, Scale, Store, ScanLine, ClipboardCheck, BarChart3, CalendarCheck, ClipboardList, FileText, Wallet, Contact, ArrowLeftRight, Mail, Webhook, MessageCircle, MessagesSquare, Newspaper, Workflow, Lightbulb, RefreshCw, Boxes, Warehouse, Package, Target, Building2, FileSpreadsheet, GitBranch, Home, KeyRound, ScrollText, Users, type LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  /** Llave de la opción en el catálogo del servidor (`MenuCatalog`): la administración puede ocultarla a una persona. Sin llave, siempre visible (Inicio). */
  key?: string
  /** Otras direcciones que pertenecen a la misma opción (pantallas sin entrada propia en el menú). */
  also?: string[]
  /** Solo el rol Administrador la ve (no se puede conceder por permisos). */
  adminOnly?: boolean
  label: string
  /** Nombre completo, cuando el del menú es corto (dentro de un grupo que ya lo aclara): lo usan el encabezado y la barra de comandos. */
  title?: string
  icon: LucideIcon
  /** Permiso requerido para ver el elemento; sin permiso, visible para todo usuario autenticado. */
  permission?: string
}

export interface NavGroup {
  title: string
  items: NavItem[]
  /** Se muestra como un menú desplegable (cerrado salvo que se esté dentro de él) en vez de una lista siempre abierta. */
  collapsible?: boolean
}

export const NAV_GROUPS: NavGroup[] = [
  { title: 'General', items: [{ to: '/', label: 'Inicio', icon: Home }] },
  {
    title: 'Inteligencia',
    items: [
      { to: '/ventas', key: 'sales', label: 'Ventas', icon: BarChart3, permission: 'sales.view' },
      { to: '/metas', key: 'goals', label: 'Metas', icon: Target, permission: 'sales.view' },
      { to: '/clientes', key: 'customers', label: 'Clientes', icon: Contact, permission: 'customers.view' },
      { to: '/conversaciones', key: 'conversations', label: 'Conversaciones', icon: MessagesSquare, permission: 'conversations.view' },
      { to: '/agenda', key: 'agenda', label: 'Agenda', icon: CalendarCheck, permission: 'interactions.manage' },
      { to: '/seguimiento', key: 'follow_up', label: 'Seguimiento', icon: ClipboardList, permission: 'interactions.manage' },
      { to: '/cotizaciones', key: 'quotations', label: 'Cotizaciones', icon: FileText, permission: 'customers.view' },
      { to: '/credito', key: 'credit', also: ['/cartera'], label: 'Crédito', icon: Wallet, permission: 'credit.view' },
      { to: '/resumenes', key: 'digests', label: 'Resúmenes', icon: Newspaper, permission: 'digests.view' },
      { to: '/inteligencia', key: 'insights', label: 'Inteligencia', icon: Lightbulb, permission: 'insights.view' },
      { to: '/productos', key: 'products', label: 'Productos', icon: Package, permission: 'sales.view' },
      { to: '/inventario', key: 'stock', label: 'Existencias', icon: Warehouse, permission: 'inventory.view' },
      { to: '/inventario/rotacion', key: 'rotation', label: 'Rotación', icon: RefreshCw, permission: 'inventory.view' },
      { to: '/inventario/movimientos', key: 'movements', label: 'Movimientos', icon: ArrowLeftRight, permission: 'inventory.view' },
      { to: '/inventario/unidades', key: 'units', label: 'Unidades', icon: Boxes, permission: 'inventory.view' },
    ],
  },
  {
    title: 'Reportes',
    collapsible: true,
    items: [
      { to: '/reportes/vendedores', key: 'report_sellers', label: 'Vendedores', title: 'Reporte de vendedores', icon: ClipboardCheck, permission: 'reports.view' },
      { to: '/reportes/operadores', key: 'report_operators', label: 'Operadores', title: 'Reporte de operadores', icon: ScanLine, permission: 'reports.view' },
      { to: '/reportes/sucursales', key: 'report_branches', label: 'Sucursales', title: 'Reporte de sucursales', icon: Store, permission: 'reports.view' },
      { to: '/reportes/productos', key: 'report_products', label: 'Productos', title: 'Mezcla de productos', icon: PackageSearch, permission: 'reports.view' },
      { to: '/reportes/conciliacion', key: 'report_conciliation', label: 'Conciliación', title: 'Conciliación de ventas', icon: Scale, permission: 'reports.view' },
    ],
  },
  {
    title: 'Datos',
    items: [{ to: '/importaciones', key: 'imports', label: 'Importaciones', icon: FileSpreadsheet, permission: 'imports.manage' }],
  },
  {
    title: 'Catálogos',
    items: [
      { to: '/admin/companias', key: 'companies', label: 'Compañías', icon: Building2, permission: 'companies.manage' },
      { to: '/admin/sucursales', key: 'branches', label: 'Sucursales', icon: GitBranch, permission: 'branches.manage' },
    ],
  },
  {
    title: 'Administración',
    items: [
      { to: '/admin/usuarios', key: 'users', label: 'Usuarios', icon: Users, permission: 'users.manage' },
      { to: '/admin/menu-usuarios', label: 'Menú por usuario', icon: ListChecks, adminOnly: true },
      { to: '/admin/roles', key: 'roles', label: 'Roles y permisos', icon: KeyRound, permission: 'roles.manage' },
      { to: '/admin/automatizaciones', key: 'automations', label: 'Automatizaciones', icon: Workflow, permission: 'automations.manage' },
      { to: '/admin/correo', key: 'mail', label: 'Correo', icon: Mail, permission: 'mail.manage' },
      { to: '/admin/whatsapp', key: 'whatsapp', label: 'WhatsApp', icon: MessageCircle, permission: 'whatsapp.manage' },
      { to: '/admin/webhook', key: 'webhook', label: 'Webhook', icon: Webhook, permission: 'webhooks.manage' },
      { to: '/admin/auditoria', key: 'audit', label: 'Auditoría', icon: ScrollText, permission: 'audit.view' },
    ],
  },
]

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((g) => g.items)
