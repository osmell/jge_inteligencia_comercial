import { NAV_ITEMS, type NavItem } from '../components/navigation'

/**
 * La opción del menú a la que pertenece una dirección: la entrada más específica cuyo `to` (o una de sus direcciones `also`) la contiene.
 * Las pantallas sin entrada propia (la ficha de un cliente, el detalle de un crédito…) cuentan como parte de su opción.
 */
export function optionForPath(pathname: string): NavItem | undefined {
  let best: NavItem | undefined
  let bestLength = 0
  for (const item of NAV_ITEMS) {
    for (const prefix of [item.to, ...(item.also ?? [])]) {
      if (prefix === '/') continue
      if ((pathname === prefix || pathname.startsWith(`${prefix}/`)) && prefix.length > bestLength) {
        best = item
        bestLength = prefix.length
      }
    }
  }
  return best
}
