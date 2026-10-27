import { afterEach, describe, expect, it } from 'vitest'
import { salesBasis, setSalesBasis, withBasis } from './lib/salesBasis'

describe('base de las cifras de venta', () => {
  afterEach(() => localStorage.clear())

  it('por omisión son las corregidas y la ruta no cambia', () => {
    expect(salesBasis()).toBe('corrected')
    expect(withBasis('/sales/summary?from=2026-10-01')).toBe('/sales/summary?from=2026-10-01')
  })

  it('al elegir lo importado se agrega basis=imported a la ruta, con o sin parámetros', () => {
    setSalesBasis('imported')

    expect(salesBasis()).toBe('imported')
    expect(withBasis('/sales/summary?from=2026-10-01')).toBe('/sales/summary?from=2026-10-01&basis=imported')
    expect(withBasis('/customers')).toBe('/customers?basis=imported')
  })

  it('un valor desconocido guardado se trata como las corregidas', () => {
    localStorage.setItem('jge.sales-basis', 'otra')

    expect(salesBasis()).toBe('corrected')
  })
})
