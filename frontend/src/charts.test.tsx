import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ChartData, DonutChart, LineChart, ProgressBar, Sparkline } from './components/charts'
import { goalTone } from './lib/tone'

describe('gráficos del dashboard', () => {
  it('la línea compara series por posición, nombra cada una en la leyenda y expone cada punto como texto', () => {
    const { container } = render(
      <LineChart
        label="Venta diaria del mes"
        series={[
          { name: 'Mes en curso', points: [{ key: '2026-10-01', value: 100 }, { key: '2026-10-02', value: 300 }] },
          { name: 'Mes anterior', points: [{ key: '2026-09-01', value: 200 }, { key: '2026-09-02', value: 150 }] },
        ]}
      />,
    )

    expect(screen.getByRole('img', { name: 'Venta diaria del mes' })).toBeInTheDocument()
    expect(screen.getByText('Mes en curso')).toBeInTheDocument()
    expect(screen.getByText('Mes anterior')).toBeInTheDocument()
    expect(container.querySelectorAll('polyline')).toHaveLength(2)
    expect(container.querySelector('polyline[stroke-dasharray]')).not.toBeNull()                    // la segunda serie se distingue por el trazo, no solo por el color
  })

  it('la línea sin datos no falla', () => {
    const { container } = render(<LineChart label="Vacía" series={[{ name: 'A', points: [] }]} />)
    expect(container.querySelector('svg')).not.toBeNull()
  })

  it('la barra de avance dice el porcentaje y el estado con texto y marca la referencia', () => {
    render(<ProgressBar label="Meta de Vera" value={750} target={1000} marker={900} tone={goalTone(75)} format={(v) => `${v} u`} />)

    expect(screen.getByRole('progressbar', { name: 'Meta de Vera' })).toHaveAttribute('aria-valuenow', '75')
    expect(screen.getByText(/750 u de 1000 u · 75 % · Cerca/)).toBeInTheDocument()
    expect(screen.getByTitle('Referencia: 900 u')).toBeInTheDocument()
  })

  it('el avance nunca pasa del 100 % en la barra aunque se supere la meta', () => {
    render(<ProgressBar label="Meta" value={1500} target={1000} tone="good" format={(v) => String(v)} />)

    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100')
    expect(screen.getByText(/150 % · Cumplida/)).toBeInTheDocument()
  })

  it('los umbrales de estado son los de Metas', () => {
    expect([goalTone(null), goalTone(40), goalTone(75), goalTone(99.9), goalTone(100)]).toEqual(['neutral', 'bad', 'warn', 'warn', 'good'])
  })

  it('el anillo reparte el total y su leyenda da valor y porcentaje de cada parte', () => {
    render(<DonutChart label="Cartera por tramos" format={(v) => `$${v}`} center="$400" slices={[{ key: 'a', label: 'Al corriente', value: 300 }, { key: 'b', label: 'Vencido', value: 100 }]} />)

    const legend = screen.getByRole('list', { name: 'Leyenda: Cartera por tramos' })
    expect(within(legend).getByText('$300 · 75 %')).toBeInTheDocument()
    expect(within(legend).getByText('$100 · 25 %')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Cartera por tramos' }).querySelectorAll('circle')).toHaveLength(3)       // fondo + dos partes
  })

  it('el anillo sin total muestra solo el fondo y 0 %', () => {
    render(<DonutChart label="Vacío" format={String} slices={[{ key: 'a', label: 'A', value: 0 }]} />)

    expect(screen.getByRole('img', { name: 'Vacío' }).querySelectorAll('circle')).toHaveLength(1)
    expect(screen.getByText('0 · 0 %')).toBeInTheDocument()
  })

  it('la mini-tendencia es una imagen con descripción', () => {
    render(<Sparkline label="Tendencia de 6 meses" values={[1, 3, 2, 5]} />)
    expect(screen.getByRole('img', { name: 'Tendencia de 6 meses' })).toBeInTheDocument()
  })

  it('la tabla alterna está plegada y se abre a pedido', async () => {
    const u = userEvent.setup()
    render(<ChartData><table aria-label="Datos"><tbody><tr><td>10</td></tr></tbody></table></ChartData>)

    const details = screen.getByText('Ver los datos en tabla').closest('details')
    expect(details).not.toHaveAttribute('open')
    await u.click(screen.getByText('Ver los datos en tabla'))
    expect(details).toHaveAttribute('open')
  })
})
