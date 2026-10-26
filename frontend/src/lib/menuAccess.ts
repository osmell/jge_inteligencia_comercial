import type { paths } from './api-types'
import { NAV_GROUPS, type NavItem } from '../components/navigation'

type Body<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer B } } } } } ? B : never

export type MenuAccess = Body<'/admin/menu-access'>['data']
export type MenuAccessUser = MenuAccess['users'][number]

export interface MenuGroup {
  title: string
  items: (NavItem & { key: string })[]
}

/** Las opciones que se pueden ocultar, agrupadas como se ven en el menú (las que no tienen llave —Inicio, «Menú por usuario»— no se ocultan). */
export const MENU_GROUPS: MenuGroup[] = NAV_GROUPS
  .map((g) => ({ title: g.title, items: g.items.filter((i): i is NavItem & { key: string } => !!i.key) }))
  .filter((g) => g.items.length > 0)

/** Los grupos que la persona dejaría de ver por lo ocultado: tenían al menos una opción disponible y ya no queda ninguna. */
export function vanishingGroups(available: string[], hidden: string[]): string[] {
  return MENU_GROUPS
    .filter((g) => {
      const own = g.items.filter((i) => available.includes(i.key))
      return own.length > 0 && own.every((i) => hidden.includes(i.key))
    })
    .map((g) => g.title)
}

/** Lo ocultado, en el orden del menú y sin repetir (lo mismo que guarda el servidor). */
export function normalizeHidden(hidden: string[]): string[] {
  const order = MENU_GROUPS.flatMap((g) => g.items.map((i) => i.key))
  return order.filter((k) => hidden.includes(k))
}
