import { useState } from 'react'
import { InfoTip } from './InfoTip'
import { Card } from './ui'
import { InlineBar } from './charts'
import { formatMoney } from '../lib/format'
import type { Info } from '../lib/glossary'
import { formatPct, type BreakdownRow, type Dimension, type RankingKey, type Rankings } from '../lib/sales'
import { useLoad } from '../lib/useLoad'

type RankedDimension = 'product' | 'category'

const DIMENSION_LABEL: Record<RankedDimension, string> = { product: 'Productos', category: 'Categorías' }

interface List {
  key: RankingKey
  title: string
  value: (r: BreakdownRow) => number
  show: (r: BreakdownRow) => string
  note?: string
}

const LIST_INFO: Partial<Record<RankingKey, Info>> = {
  by_net_total: 'sales_net', by_units: 'sales_units', by_documents: 'sales_documents', by_margin: 'sales_margin', by_discount: 'sales_discount',
}

const LISTS: List[] = [
  { key: 'by_net_total', title: 'Por importe (venta neta)', value: (r) => Number(r.net_total), show: (r) => formatMoney(r.net_total) },
  { key: 'by_units', title: 'Por unidades vendidas', value: (r) => Number(r.units), show: (r) => formatMoney(r.units) },
  {
    key: 'by_documents',
    title: 'Por frecuencia',
    value: (r) => r.documents,
    show: (r) => `${r.documents.toLocaleString('es')} ${r.documents === 1 ? 'factura' : 'facturas'}`,
    note: 'Facturas distintas que incluyen el artículo.',
  },
  {
    key: 'by_margin',
    title: 'Por margen estimado',
    value: (r) => Number(r.estimated_margin),
    show: (r) => `${formatMoney(r.estimated_margin)} (${formatPct(r.estimated_margin_pct)})`,
    note: 'Costo actual, no histórico. Solo artículos con costo válido.',
  },
  {
    key: 'by_discount',
    title: 'Por descuento',
    value: (r) => Number(r.discount_total),
    show: (r) => `${formatMoney(r.discount_total)} (${formatPct(r.discount_pct)})`,
    note: 'Descuento ya restado de la venta neta; el porcentaje es sobre el Sub Total. Solo artículos con descuento.',
  },
]

/** Rankings de productos o categorías con los filtros del tablero. Elegir un nombre filtra el tablero. */
export default function RankingsPanel({
  filterQuery,
  enabled,
  onPick,
}: {
  filterQuery: string
  enabled: boolean
  onPick: (dimension: Dimension, row: BreakdownRow) => void
}) {
  const [dimension, setDimension] = useState<RankedDimension>('product')
  const { data, error, loading } = useLoad<Rankings>(`/sales/rankings?${filterQuery}&dimension=${dimension}&limit=10`, { enabled })
  const rankings = enabled ? data?.data : undefined

  return (
    <section aria-labelledby="rankings-title" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="rankings-title" className="text-lg font-semibold tracking-tight">Rankings (los 10 primeros)</h2>
          <p className="text-sm text-ink-muted">Mismo periodo y filtros del tablero. Un artículo puede estar en más de un ranking.</p>
        </div>
        <div role="group" aria-label="Qué clasificar" className="flex gap-1">
          {(Object.keys(DIMENSION_LABEL) as RankedDimension[]).map((d) => (
            <button
              key={d}
              type="button"
              aria-pressed={dimension === d}
              onClick={() => setDimension(d)}
              className={`min-h-9 cursor-pointer rounded-lg px-3 text-sm font-medium transition-colors ${dimension === d ? 'bg-jge-blue text-white' : 'text-ink-muted hover:bg-jge-blue-50 hover:text-jge-blue'}`}
            >
              {DIMENSION_LABEL[d]}
            </button>
          ))}
        </div>
      </div>

      {enabled && error && <p role="alert" className="text-sm text-danger">{error}</p>}

      <div className="grid gap-4 lg:grid-cols-2" aria-busy={loading}>
        {LISTS.map((list) => {
          const rows = rankings?.[list.key] ?? []
          const max = Math.max(...rows.map(list.value), 0)
          return (
            <Card key={list.key} className="p-4">
              <h3 className="flex items-center gap-1 font-semibold">{list.title}{LIST_INFO[list.key] && <InfoTip info={LIST_INFO[list.key]!} label={list.title} />}</h3>
              {list.note && <p className="text-xs text-ink-muted">{list.note}</p>}
              {loading ? (
                <div className="skeleton mt-3 h-32 w-full" aria-hidden="true" />
              ) : rows.length === 0 ? (
                <p className="py-6 text-center text-sm text-ink-muted">Sin datos para estos filtros.</p>
              ) : (
                <ol className="mt-3 space-y-2" aria-label={list.title}>
                  {rows.map((r, i) => (
                    <li key={r.key} className="grid grid-cols-[1.5rem_1fr_auto] items-center gap-x-2 gap-y-1 text-sm">
                      <span className="tabular text-right text-ink-subtle">{i + 1}</span>
                      <button type="button" onClick={() => onPick(dimension, r)} title={`Filtrar por ${r.label}`} className="cursor-pointer truncate text-left font-medium text-jge-blue hover:underline">
                        {r.label}
                      </button>
                      <span className="tabular text-right">{list.show(r)}</span>
                      <span aria-hidden="true" />
                      <span className="col-span-2"><InlineBar value={list.value(r)} max={max} /></span>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          )
        })}
      </div>
    </section>
  )
}
