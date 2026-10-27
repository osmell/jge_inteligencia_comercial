const KEY = 'jge.sales-basis'

export type SalesBasis = 'corrected' | 'imported'

/**
 * Base de las cifras de venta que se pide al servidor: «corrected» (por omisión: equivalencias corregidas y sin facturas anuladas) o «imported»
 * (tal como se importó). Es una preferencia por persona, guardada en este navegador.
 */
export function salesBasis(): SalesBasis {
  try {
    return localStorage.getItem(KEY) === 'imported' ? 'imported' : 'corrected'
  } catch {
    return 'corrected'
  }
}

export function setSalesBasis(basis: SalesBasis): void {
  try {
    localStorage.setItem(KEY, basis)
  } catch {
    /* sin almacenamiento: se queda con la base por omisión */
  }
}

/** Agrega `basis=imported` a la ruta cuando la persona eligió ver las cifras tal como se importaron; si no, la deja igual. */
export function withBasis(path: string): string {
  if (salesBasis() !== 'imported') return path

  return `${path}${path.includes('?') ? '&' : '?'}basis=imported`
}
