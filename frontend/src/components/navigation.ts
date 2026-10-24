import { ScanLine, ClipboardCheck, BarChart3, CalendarCheck, ClipboardList, FileText, Wallet, Contact, ArrowLeftRight, Mail, Webhook, MessageCircle, MessagesSquare, Newspaper, Workflow, Lightbulb, RefreshCw, Boxes, Warehouse, Package, Target, Building2, FileSpreadsheet, GitBranch, Home, KeyRound, ScrollText, Users, type LucideIcon } from 'lucide-react'

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
      { to: '/conversaciones', label: 'Conversaciones', icon: MessagesSquare, permission: 'conversations.view' },
      { to: '/agenda', label: 'Agenda', icon: CalendarCheck, permission: 'interactions.manage' },
      { to: '/seguimiento', label: 'Seguimiento', icon: ClipboardList, permission: 'interactions.manage' },
      { to: '/cotizaciones', label: 'Cotizaciones', icon: FileText, permission: 'customers.view' },
      { to: '/credito', label: 'Crédito', icon: Wallet, permission: 'credit.view' },
      { to: '/resumenes', label: 'Resúmenes', icon: Newspaper, permission: 'digests.view' },
      { to: '/inteligencia', label: 'Inteligencia', icon: Lightbulb, permission: 'insights.view' },
      { to: '/productos', label: 'Productos', icon: Package, permission: 'sales.view' },
      { to: '/inventario', label: 'Existencias', icon: Warehouse, permission: 'inventory.view' },
      { to: '/inventario/rotacion', label: 'Rotación', icon: RefreshCw, permission: 'inventory.view' },
      { to: '/inventario/movimientos', label: 'Movimientos', icon: ArrowLeftRight, permission: 'inventory.view' },
      { to: '/inventario/unidades', label: 'Unidades', icon: Boxes, permission: 'inventory.view' },
    ],
  },
  {
    title: 'Reportes',
    items: [
      { to: '/reportes/vendedores', label: 'Reporte de vendedores', icon: ClipboardCheck, permission: 'reports.view' },
      { to: '/reportes/operadores', label: 'Reporte de operadores', icon: ScanLine, permission: 'reports.view' },
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
      { to: '/admin/automatizaciones', label: 'Automatizaciones', icon: Workflow, permission: 'automations.manage' },
      { to: '/admin/correo', label: 'Correo', icon: Mail, permission: 'mail.manage' },
      { to: '/admin/whatsapp', label: 'WhatsApp', icon: MessageCircle, permission: 'whatsapp.manage' },
      { to: '/admin/webhook', label: 'Webhook', icon: Webhook, permission: 'webhooks.manage' },
      { to: '/admin/auditoria', label: 'Auditoría', icon: ScrollText, permission: 'audit.view' },
    ],
  },
]

export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((g) => g.items)
