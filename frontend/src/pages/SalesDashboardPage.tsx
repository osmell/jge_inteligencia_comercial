import { ArrowDown, ArrowUp, Download, Minus, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { BarChart, InlineBar } from '../components/charts'
import RankingsPanel from '../components/RankingsPanel'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { download } from '../lib/api'
import { formatMoney } from '../lib/format'
import {
  COMPARE_LABEL,
  DIMENSION_FILTER,
  DIMENSION_LABEL,
  PRESET_LABEL,
  daysBetween,
  formatPct,
  presetRange,
  yearAgoRange,
  type Breakdown,
  type BreakdownRow,
  type Compare,
  type Dimension,
  type Preset,
  type SalesSummary,
} from '../lib/sales'
import type { Branch, Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const MAX_DAYS = 1096
const DAILY_UP_TO_DAYS = 92
const DIMENSIONS = Object.keys(DIMENSION_LABEL) as Dimension[]

interface Chip {
  param: string
  value: string
  label: string
}

function Delta({ pct, versus = 'vs. periodo anterior' }: { pct: number | null | undefined; versus?: string }) {
  if (pct === null || pct === undefined) return <p className="mt-1 text-xs text-ink-subtle">Sin base de comparación</p>
  const Icon = pct > 0 ? ArrowUp : pct < 0 ? ArrowDown : Minus
  const tone = pct > 0 ? 'text-success' : pct < 0 ? 'text-danger' : 'text-ink-muted'
  return (
    <p className={`mt-1 flex items-center gap-1 text-xs font-medium ${tone}`}>
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {pct > 0 ? '+' : ''}{formatPct(pct)}
      <span className="font-normal text-ink-muted">{versus}</span>
    </p>
  )
}

/** Variación de la venta neta de una fila contra el periodo comparado; «Nuevo» si antes no vendió. */
function RowChange({ row }: { row: BreakdownRow }) {
  if (row.previous_net_total === undefined) return <>—</>
  if (row.net_change_pct === null || row.net_change_pct === undefined) return <>{Number(row.previous_net_total) === 0 ? 'Sin ventas antes' : '—'}</>
  const up = row.net_change_pct > 0
  const Icon = up ? ArrowUp : row.net_change_pct < 0 ? ArrowDown : Minus
  return (
    <span className={`inline-flex items-center gap-1 ${up ? 'text-success' : row.net_change_pct < 0 ? 'text-danger' : 'text-ink-muted'}`}>
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {up ? '+' : ''}{formatPct(row.net_change_pct)}
    </span>
  )
}

function Kpi({ label, value, change, hint, versus }: { label: string; value: string; change?: number | null; hint?: string; versus?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular mt-0.5 text-2xl font-semibold tracking-tight">{value}</p>
      {change !== undefined ? <Delta pct={change} versus={versus} /> : null}
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

export default function SalesDashboardPage() {
  const [preset, setPreset] = useState<Preset>('month')
  const [custom, setCustom] = useState(() => presetRange('month'))
  const [compare, setCompare] = useState<Compare>('previous')
  const [compareCustom, setCompareCustom] = useState({ from: '', to: '' })
  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [chips, setChips] = useState<Chip[]>([])
  const [dimension, setDimension] = useState<Dimension>('seller')
  const [exporting, setExporting] = useState<'xlsx' | 'csv' | 'pdf' | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)

  const range = preset === 'custom' ? custom : presetRange(preset)
  const days = daysBetween(range.from, range.to)
  const validate = (r: { from: string; to: string }, what: string) => {
    const n = daysBetween(r.from, r.to)
    return !r.from || !r.to ? `Indica la fecha inicial y la final${what}.` : n < 1 ? `La fecha final no puede ser anterior a la inicial${what}.` : n > MAX_DAYS ? `El rango máximo es de ${MAX_DAYS} días${what}.` : null
  }
  const rangeError = validate(range, '')
  const compareRange = compare === 'year' ? yearAgoRange(range) : compare === 'custom' ? compareCustom : null
  const compareFrom = compareRange?.from ?? ''
  const compareTo = compareRange?.to ?? ''
  const compareError = !rangeError && compare === 'custom' ? validate(compareCustom, ' de comparación') : null
  const inputError = rangeError ?? compareError

  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const branchOptions = (branches.data?.data ?? []).filter((b) => !companyId || b.company_id === Number(companyId))

  const filterQuery = useMemo(() => {
    const p = new URLSearchParams({ from: range.from, to: range.to })
    if (compareFrom && compareTo) {
      p.set('compare_from', compareFrom)
      p.set('compare_to', compareTo)
    }
    if (companyId) p.set('company_id', companyId)
    if (branchId) p.set('branch_id', branchId)
    chips.forEach((c) => p.set(c.param, c.value))
    return p.toString()
  }, [range.from, range.to, compareFrom, compareTo, companyId, branchId, chips])

  // Con un rango inválido no se consulta (el backend lo rechazaría) y se ocultan los resultados anteriores.
  const enabled = !inputError
  const summary = useLoad<{ data: SalesSummary }>(`/sales/summary?${filterQuery}`, { enabled })
  const trendGranularity = days <= DAILY_UP_TO_DAYS ? 'day' : 'month'
  const trend = useLoad<Breakdown>(`/sales/breakdown?${filterQuery}&dimension=${trendGranularity}`, { enabled })
  const table = useLoad<Breakdown>(`/sales/breakdown?${filterQuery}&dimension=${dimension}&limit=15`, { enabled })

  const versus = compare === 'previous' ? 'vs. periodo anterior' : 'vs. periodo comparado'
  const s = enabled ? summary.data?.data : undefined
  const rows = (enabled ? table.data?.data : undefined) ?? []
  const maxNet = Math.max(...rows.map((r) => Number(r.net_total)), 0)
  const points = ((enabled ? trend.data?.data : undefined) ?? []).map((r) => ({ key: r.key, value: Number(r.net_total) }))
  const error = enabled ? (summary.error ?? trend.error ?? table.error) : null

  function applyRow(row: BreakdownRow, picked: Dimension = dimension) {
    const param = DIMENSION_FILTER[picked]
    if (param === 'company_id') return selectCompany(row.key)
    if (param === 'branch_id') return setBranchId(row.key)
    setChips((prev) => [...prev.filter((c) => c.param !== param), { param, value: row.key, label: `${DIMENSION_LABEL[picked]}: ${row.label}` }])
  }

  async function exportReport(format: 'xlsx' | 'csv' | 'pdf') {
    setExporting(format)
    setExportError(null)
    try {
      const extra = format === 'csv' ? `&dimension=${dimension}` : ''
      await download(`/sales/export?${filterQuery}&format=${format}${extra}`, `ventas.${format}`)
    } catch (e) {
      setExportError(e instanceof Error ? e.message : 'No se pudo exportar el reporte.')
    } finally {
      setExporting(null)
    }
  }

  function selectCompany(id: string) {
    setCompanyId(id)
    setBranchId('')
  }

  const hasFilters = !!companyId || !!branchId || chips.length > 0

  return (
    <section className="space-y-6">
      <PageHeader
        title="Ventas"
        description="Venta neta, impuestos y total por periodo, comparados con el periodo equivalente anterior."
        action={
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'xlsx'} disabled={!enabled || exporting !== null} onClick={() => void exportReport('xlsx')}>
              Exportar a Excel
            </Button>
            <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'pdf'} disabled={!enabled || exporting !== null} onClick={() => void exportReport('pdf')}>
              Exportar a PDF
            </Button>
            <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'csv'} disabled={!enabled || exporting !== null} onClick={() => void exportReport('csv')}>
              {`CSV por ${DIMENSION_LABEL[dimension].toLowerCase()}`}
            </Button>
          </div>
        }
      />

      <Card className="space-y-4 p-4">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="sales-preset" label="Periodo" value={preset} onChange={(e) => setPreset(e.target.value as Preset)}>
            {(Object.keys(PRESET_LABEL) as Preset[]).map((p) => <option key={p} value={p}>{PRESET_LABEL[p]}</option>)}
          </SelectField>
          <SelectField id="sales-company" label="Compañía" value={companyId} onChange={(e) => selectCompany(e.target.value)}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="sales-branch" label="Sucursal" value={branchId} onChange={(e) => setBranchId(e.target.value)}>
            <option value="">Todas</option>
            {branchOptions.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          <SelectField id="sales-compare" label="Comparar con" value={compare} onChange={(e) => setCompare(e.target.value as Compare)}>
            {(Object.keys(COMPARE_LABEL) as Compare[]).map((c) => <option key={c} value={c}>{COMPARE_LABEL[c]}</option>)}
          </SelectField>
          {preset === 'custom' && (
            <div className="grid grid-cols-2 gap-2 sm:col-span-2 lg:col-span-1">
              <TextField id="sales-from" label="Desde" type="date" value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} />
              <TextField id="sales-to" label="Hasta" type="date" value={custom.to} onChange={(e) => setCustom({ ...custom, to: e.target.value })} />
            </div>
          )}
          {compare === 'custom' && (
            <div className="grid grid-cols-2 gap-2 sm:col-span-2 lg:col-span-2">
              <TextField id="sales-compare-from" label="Comparar desde" type="date" value={compareCustom.from} onChange={(e) => setCompareCustom({ ...compareCustom, from: e.target.value })} />
              <TextField id="sales-compare-to" label="Comparar hasta" type="date" value={compareCustom.to} onChange={(e) => setCompareCustom({ ...compareCustom, to: e.target.value })} />
            </div>
          )}
        </div>

        {hasFilters && (
          <ul className="flex flex-wrap items-center gap-2" aria-label="Filtros aplicados">
            {chips.map((c) => (
              <li key={c.param}>
                <button
                  type="button"
                  onClick={() => setChips((prev) => prev.filter((x) => x.param !== c.param))}
                  className="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-jge-blue/30 bg-jge-blue-50 px-3 text-sm text-jge-blue hover:bg-jge-blue-100"
                  aria-label={`Quitar filtro ${c.label}`}
                >
                  {c.label}
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              </li>
            ))}
            <li>
              <button
                type="button"
                className="min-h-9 cursor-pointer rounded-lg px-2 text-sm font-medium text-jge-blue hover:bg-jge-blue-50"
                onClick={() => { setCompanyId(''); setBranchId(''); setChips([]) }}
              >
                Limpiar filtros
              </button>
            </li>
          </ul>
        )}
        <FormError message={inputError} />
      </Card>

      {(error ?? exportError) && <Alert tone="danger">{error ?? exportError}</Alert>}
      {s?.warnings.includes('previous_period_incomplete') && (
        <Alert tone="warning">
          El historial cargado empieza el {s.first_sale_date}, después del inicio del periodo anterior ({s.previous_period.from}). La comparación es parcial.
        </Alert>
      )}

      {s?.warnings.includes('different_length') && (
        <Alert tone="warning">
          Los periodos comparados tienen distinta duración ({s.period.days} y {s.previous_period.days} días): las variaciones de importes, documentos y unidades no son equivalentes.
        </Alert>
      )}
      {s?.warnings.includes('overlapping_periods') && <Alert tone="warning">Los periodos comparados se traslapan, así que parte de la venta se cuenta en ambos.</Alert>}
      {s && <p className="text-sm text-ink-muted">Periodo: {s.period.from} a {s.period.to} · Comparado con: {s.previous_period.from} a {s.previous_period.to}</p>}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy={summary.loading}>
        <Kpi versus={versus} label="Venta neta" value={s ? formatMoney(s.current.net_total) : '—'} change={s?.change_pct.net_total} />
        <Kpi versus={versus} label="Impuesto" value={s ? formatMoney(s.current.tax_total) : '—'} change={s?.change_pct.tax_total} />
        <Kpi versus={versus} label="Total facturado" value={s ? formatMoney(s.current.grand_total) : '—'} change={s?.change_pct.grand_total} />
        <Kpi
          versus={versus}
          label="Descuento"
          value={s ? formatMoney(s.current.discount_total) : '—'}
          change={s?.change_pct.discount_total}
          hint={s ? (s.current.discount_pct === null ? 'Sin líneas con Sub Total en el periodo.' : `${formatPct(s.current.discount_pct)} sobre el Sub Total. Ya está restado de la venta neta.`) : undefined}
        />
        <Kpi versus={versus} label="Documentos" value={s ? s.current.documents.toLocaleString('es') : '—'} change={s?.change_pct.documents} />
        <Kpi versus={versus} label="Clientes compradores" value={s ? s.current.customers.toLocaleString('es') : '—'} change={s?.change_pct.customers} />
        <Kpi versus={versus} label="Ticket promedio" value={s ? formatMoney(s.current.average_ticket) : '—'} change={s?.change_pct.average_ticket} />
        <Kpi versus={versus} label="Unidades" value={s ? formatMoney(s.current.units) : '—'} change={s?.change_pct.units} />
        <Kpi
          label="Margen estimado"
          value={s ? formatMoney(s.current.estimated_margin) : '—'}
          change={s?.change_pct.estimated_margin}
          hint={s ? `${formatPct(s.current.estimated_margin_pct)} sobre venta con costo (${formatPct(s.current.cost_coverage_pct)} de la venta). Usa el costo actual, no el histórico.` : undefined}
        />
      </div>

      <Card className="p-4">
        <h2 className="mb-2 text-lg font-semibold tracking-tight">{trendGranularity === 'day' ? 'Venta neta por día' : 'Venta neta por mes'}</h2>
        {trend.loading ? <div className="skeleton h-48 w-full" aria-hidden="true" /> : points.length === 0 ? (
          <p className="py-10 text-center text-sm text-ink-muted">No hay ventas en este periodo.</p>
        ) : (
          <>
            <BarChart points={points} label={`Venta neta ${trendGranularity === 'day' ? 'diaria' : 'mensual'}, ${points.length} puntos`} />
            <details className="mt-2 text-sm">
              <summary className="cursor-pointer text-jge-blue">Ver datos en tabla</summary>
              <div className="mt-2 max-h-64 overflow-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">Venta neta por {trendGranularity === 'day' ? 'día' : 'mes'}</caption>
                  <thead><tr><th className={th}>{trendGranularity === 'day' ? 'Día' : 'Mes'}</th><th className={`${th} text-right`}>Venta neta</th></tr></thead>
                  <tbody>
                    {points.map((p) => <tr key={p.key} className={tr}><td className={td}>{p.key}</td><td className={`${td} tabular text-right`}>{formatMoney(p.value)}</td></tr>)}
                  </tbody>
                </table>
              </div>
            </details>
          </>
        )}
      </Card>

      <div className="space-y-3">
        <div role="tablist" aria-label="Desglose de ventas" className="flex flex-wrap gap-1">
          {DIMENSIONS.map((d) => (
            <button
              key={d}
              role="tab"
              id={`tab-${d}`}
              aria-selected={dimension === d}
              aria-controls="breakdown-panel"
              type="button"
              onClick={() => setDimension(d)}
              className={`min-h-9 cursor-pointer rounded-lg px-3 text-sm font-medium transition-colors ${dimension === d ? 'bg-jge-blue text-white' : 'text-ink-muted hover:bg-jge-blue-50 hover:text-jge-blue'}`}
            >
              {DIMENSION_LABEL[d]}
            </button>
          ))}
        </div>

        <div id="breakdown-panel" role="tabpanel" aria-labelledby={`tab-${dimension}`}>
          <DataTable label={`Ventas por ${DIMENSION_LABEL[dimension].toLowerCase()}`}>
            <thead>
              <tr>
                <th className={th}>{DIMENSION_LABEL[dimension]}</th>
                <th className={`${th} text-right`}>Venta neta</th>
                <th className={th}><span className="sr-only">Proporción</span></th>
                <th className={`${th} text-right`}>vs. comparado</th>
                <th className={`${th} text-right`}>Docs.</th>
                <th className={`${th} text-right`}>Clientes</th>
                <th className={`${th} text-right`}>Descuento</th>
                <th className={`${th} text-right`}>Ticket prom.</th>
                <th className={`${th} text-right`}>Margen est.</th>
              </tr>
            </thead>
            <tbody>
              {table.loading ? <TableSkeleton cols={9} /> : rows.length === 0 ? (
                <EmptyRow cols={9}>No hay ventas para estos filtros.</EmptyRow>
              ) : rows.map((r) => (
                <tr key={r.key} className={tr}>
                  <td className={td}>
                    <button type="button" onClick={() => applyRow(r)} className="cursor-pointer text-left font-medium text-jge-blue hover:underline" title={`Filtrar por ${r.label}`}>
                      {r.label}
                    </button>
                  </td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
                  <td className={`${td} w-40`}><InlineBar value={Number(r.net_total)} max={maxNet} /></td>
                  <td className={`${td} tabular text-right`}><RowChange row={r} /></td>
                  <td className={`${td} tabular text-right`}>{r.documents.toLocaleString('es')}</td>
                  <td className={`${td} tabular text-right`}>{r.customers.toLocaleString('es')}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.discount_total)}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.average_ticket)}</td>
                  <td className={`${td} tabular text-right`}>{r.estimated_margin_pct === null ? '—' : formatPct(r.estimated_margin_pct)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <p className="mt-2 text-xs text-ink-muted">
            {table.data?.meta.truncated ? 'Se muestran las 15 filas con más venta neta. ' : ''}
            {['group', 'line', 'category', 'product'].includes(dimension) ? 'Una factura con productos de varios grupos, líneas o categorías cuenta en cada uno; no sumes los documentos entre filas. ' : ''}
            {['group', 'line', 'category'].includes(dimension) ? 'Grupo, línea y categoría salen del catálogo de productos (Niveles 1, 2 y 3); lo que no está en el catálogo aparece como «Sin clasificar» (la categoría conserva la del archivo de ventas). ' : ''}
            Selecciona un nombre para filtrar todo el tablero.
          </p>
        </div>
      </div>

      <RankingsPanel filterQuery={filterQuery} enabled={enabled} onPick={(d, row) => applyRow(row, d)} />
    </section>
  )
}
