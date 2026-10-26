import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { InfoTip } from './components/InfoTip'
import { Stat, Th } from './components/ui'
import { GLOSSARY, resolveInfo } from './lib/glossary'

describe('InfoTip', () => {
  it('muestra la explicación y la fórmula al pasar el ratón y las oculta al salir', async () => {
    render(<InfoTip info="net_total" label="Total neto" />)
    const i = screen.getByRole('button', { name: 'Qué significa: Total neto' })
    expect(screen.queryByRole('tooltip')).toBeNull()

    await userEvent.hover(i)
    const tip = screen.getByRole('tooltip')
    expect(tip).toHaveTextContent('tal como viene en el archivo importado')
    expect(tip).toHaveTextContent('Fórmula: Total neto = Sub total − Descuento')
    expect(i).toHaveAttribute('aria-describedby', tip.id)

    await userEvent.unhover(i)
    expect(screen.queryByRole('tooltip')).toBeNull()
  })

  it('un dato que no es calculado no muestra fórmula', async () => {
    render(<InfoTip info={{ text: 'Cantidad de clientes.' }} />)
    await userEvent.hover(screen.getByRole('button', { name: 'Qué significa este dato' }))
    expect(screen.getByRole('tooltip')).not.toHaveTextContent('Fórmula')
  })

  it('se abre con el teclado y se cierra con Escape', async () => {
    render(<InfoTip info="units" />)
    await userEvent.tab()
    expect(screen.getByRole('tooltip')).toHaveTextContent('equivalencia')
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('tooltip')).toBeNull()
  })

  it('un toque lo abre y tocar fuera lo cierra', async () => {
    render(<div><InfoTip info="unit_price" /><p>fuera</p></div>)
    await userEvent.click(screen.getByRole('button'))
    expect(screen.getByRole('tooltip')).toBeInTheDocument()
    await userEvent.click(screen.getByText('fuera'))
    expect(screen.queryByRole('tooltip')).toBeNull()
  })

  it('se dibuja en el body, fuera de la tabla, para que no la recorte ni la ensanche', async () => {
    render(<table><thead><tr><Th info="net_total">Total neto</Th></tr></thead></table>)
    await userEvent.hover(screen.getByRole('button', { name: 'Qué significa: Total neto' }))
    const tip = screen.getByRole('tooltip')
    expect(screen.getByRole('table').contains(tip)).toBe(false)
    expect(tip.parentElement).toBe(document.body)
    expect(tip.className).toContain('fixed')
  })

  it('queda dentro de la ventana: nunca con una posición negativa', async () => {
    render(<InfoTip info="net_total" />)
    await userEvent.hover(screen.getByRole('button'))
    const tip = screen.getByRole('tooltip')
    expect(Number.parseFloat(tip.style.left)).toBeGreaterThanOrEqual(8)
    expect(Number.parseFloat(tip.style.top)).toBeGreaterThanOrEqual(0)
  })

  it('no imprime la «i» ni el globo', async () => {
    render(<InfoTip info="net_total" />)
    expect(screen.getByRole('button').className).toContain('print:hidden')
    await userEvent.hover(screen.getByRole('button'))
    expect(screen.getByRole('tooltip').className).toContain('print:hidden')
  })
})

describe('Th y Stat con «i»', () => {
  it('el encabezado conserva su texto y alineación', () => {
    render(<table><thead><tr><Th right info="tax_total">Impuesto</Th><Th>Cliente</Th></tr></thead></table>)
    const th = screen.getByRole('columnheader', { name: /Impuesto/ })
    expect(th.className).toContain('text-right')
    expect(screen.getByRole('columnheader', { name: 'Cliente' }).querySelector('button')).toBeNull()
  })

  it('la tarjeta agrega la «i» solo si se le da explicación', () => {
    render(<div><Stat label="Documentos" value="5" /><Stat label="Venta neta" value="10" info={{ text: 'Venta sin impuesto.', formula: 'Venta − Devoluciones' }} /></div>)
    expect(screen.getAllByRole('button')).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Qué significa: Venta neta' })).toBeInTheDocument()
  })

  it('en una tarjeta que es enlace, la «i» queda fuera del enlace y no lo activa', async () => {
    render(<MemoryRouter><Stat label="Vencidas" value="3" to="/credito" info={{ text: 'Facturas vencidas.' }} /></MemoryRouter>)
    const link = screen.getByRole('link')
    const i = screen.getByRole('button', { name: 'Qué significa: Vencidas' })
    expect(link.contains(i)).toBe(false)
    await userEvent.click(i)
    expect(screen.getByRole('tooltip')).toHaveTextContent('Facturas vencidas.')
  })
})

describe('glosario', () => {
  it('toda entrada tiene texto; las fórmulas, si existen, no están vacías', () => {
    for (const [key, entry] of Object.entries(GLOSSARY)) {
      const info = resolveInfo(key as keyof typeof GLOSSARY)
      expect(info.text.trim().length, key).toBeGreaterThan(10)
      if ('formula' in entry) expect(entry.formula.trim().length, key).toBeGreaterThan(5)
    }
  })
})
