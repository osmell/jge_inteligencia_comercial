import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import OriginConciliationView from './components/reports/OriginConciliationView'
import type { Conciliation } from './lib/reports'

type Cut = Conciliation['origin'][number]

const row = (seller: string, erp: number, system: number, status: string, over: Partial<Cut['rows'][number]> = {}): Cut['rows'][number] => ({
  seller, erp_net: erp, system_net: system, imported_net: system - 100, difference: erp - system, difference_pct: erp ? Math.round(((erp - system) / erp) * 10000) / 100 : null,
  erp_invoices: 3, system_documents: 2, voided_documents: 1, status, ...over,
})

const cut: Cut = {
  company: { id: 1, name: 'Alfa' }, period_from: '2026-08-01', cutoff: '2026-08-31', voided_without_sale: 2,
  totals: row('Total', 1500, 1400, 'differs'),
  rows: [row('Ana', 1000, 1000, 'ok'), row('Beto', 500, 400, 'differs')],
}

describe('Conciliación contra el sistema de origen', () => {
  it('sin resumen cargado explica cómo cargarlo', () => {
    render(<OriginConciliationView cuts={[]} />)

    expect(screen.getByText(/No hay un resumen por vendedor cargado/)).toBeInTheDocument()
  })

  it('muestra el total, cada vendedor con su estado en texto y la nota de anuladas sin venta', () => {
    render(<OriginConciliationView cuts={[cut]} />)

    const table = screen.getByRole('table', { name: 'Conciliación contra el origen, Alfa' })
    const rows = within(table).getAllByRole('row')
    expect(rows).toHaveLength(4) // encabezado, total y dos vendedores
    expect(within(rows[1]).getByText('Total')).toBeInTheDocument()
    expect(within(rows[2]).getByText('Ana')).toBeInTheDocument()
    expect(within(rows[2]).getByText('Cuadra')).toBeInTheDocument()
    expect(within(rows[3]).getByText('Diferencia')).toBeInTheDocument()
    expect(screen.getByText(/2 factura\(s\) anulada\(s\) no tienen venta cargada/)).toBeInTheDocument()
  })
})
