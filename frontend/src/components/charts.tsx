import type { ReactNode } from 'react'
import { formatMoney } from '../lib/format'
import type { Tone } from '../lib/tone'
import { formatBucket, formatCompact } from '../lib/sales'

export interface Point {
  key: string
  value: number
}

const W = 720
const H = 220
const PAD = { top: 12, right: 8, bottom: 26, left: 44 }

/** Barras de una serie. El detalle exacto está en la tabla alternativa que acompaña al gráfico. */
export function BarChart({ points, label }: { points: Point[]; label: string }) {
  const max = Math.max(...points.map((p) => p.value), 0)
  const top = max > 0 ? niceCeil(max) : 1
  const plotW = W - PAD.left - PAD.right
  const plotH = H - PAD.top - PAD.bottom
  const step = plotW / Math.max(points.length, 1)
  const barW = Math.max(Math.min(step * 0.72, 40), 1)
  const every = Math.max(Math.ceil(points.length / 8), 1)

  return (
    <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={label} className="h-auto w-full">
      {[0, 0.5, 1].map((f) => {
        const y = PAD.top + plotH * (1 - f)
        return (
          <g key={f}>
            <line x1={PAD.left} x2={W - PAD.right} y1={y} y2={y} className="stroke-line" strokeWidth={1} />
            <text x={PAD.left - 6} y={y + 4} textAnchor="end" className="fill-ink-subtle text-[11px]">{formatCompact(top * f)}</text>
          </g>
        )
      })}
      {points.map((p, i) => {
        const h = (p.value / top) * plotH
        const x = PAD.left + step * i + (step - barW) / 2
        return (
          <g key={p.key}>
            <rect x={x} y={PAD.top + plotH - h} width={barW} height={h} rx={2} className="fill-jge-blue">
              <title>{`${formatBucket(p.key)}: ${formatMoney(p.value)}`}</title>
            </rect>
            {i % every === 0 && (
              <text x={x + barW / 2} y={H - 8} textAnchor="middle" className="fill-ink-subtle text-[11px]">{formatBucket(p.key)}</text>
            )}
          </g>
        )
      })}
    </svg>
  )
}

/** Redondea hacia arriba a 1, 2, 2.5, 5 × 10^n para que el eje muestre cifras limpias. */
function niceCeil(value: number): number {
  const exp = Math.pow(10, Math.floor(Math.log10(value)))
  const f = value / exp
  const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10
  return nice * exp
}

/** Barra horizontal proporcional para celdas de tabla (decorativa: el valor va en texto al lado). */
export function InlineBar({ value, max }: { value: number; max: number }) {
  const pct = max > 0 ? Math.max((value / max) * 100, value > 0 ? 2 : 0) : 0
  return (
    <div className="h-2 w-full min-w-24 rounded-full bg-jge-blue-50" aria-hidden="true">
      <div className="h-2 rounded-full bg-jge-blue" style={{ width: `${pct}%` }} />
    </div>
  )
}

export interface LineSeries {
  name: string
  points: Point[]
}

/** Estilos por posición: la primera serie va llena y las demás con trazo distinto, para no depender solo del color. */
const SERIES_STYLE = [
  { stroke: 'stroke-jge-blue', dash: undefined, swatch: 'bg-jge-blue' },
  { stroke: 'stroke-ink-subtle', dash: '6 4', swatch: 'bg-ink-subtle' },
  { stroke: 'stroke-jge-red', dash: '2 4', swatch: 'bg-jge-red' },
] as const

/**
 * Líneas de hasta tres series que se comparan por posición (día 1 del periodo contra día 1 del anterior). La primera lleva
 * área. Las etiquetas del eje salen de la primera serie. El detalle exacto está en la tabla alternativa que acompaña al gráfico.
 */
export function LineChart({ series, label, format = formatMoney, labelOf = formatBucket }: { series: LineSeries[]; label: string; format?: (v: number) => string; labelOf?: (key: string) => string }) {
  const lead = series[0]?.points ?? []
  const max = Math.max(...series.flatMap((s) => s.points.map((p) => p.value)), 0)
  const top = max > 0 ? niceCeil(max) : 1
  const plotW = W - PAD.left - PAD.right
  const plotH = H - PAD.top - PAD.bottom
  const count = Math.max(...series.map((s) => s.points.length), 1)
  const x = (i: number) => PAD.left + (count === 1 ? plotW / 2 : (plotW * i) / (count - 1))
  const y = (v: number) => PAD.top + plotH - (v / top) * plotH
  const every = Math.max(Math.ceil(lead.length / 8), 1)

  return (
    <figure>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={label} className="h-auto w-full">
        {[0, 0.5, 1].map((f) => (
          <g key={f}>
            <line x1={PAD.left} x2={W - PAD.right} y1={PAD.top + plotH * (1 - f)} y2={PAD.top + plotH * (1 - f)} className="stroke-line" strokeWidth={1} />
            <text x={PAD.left - 6} y={PAD.top + plotH * (1 - f) + 4} textAnchor="end" className="fill-ink-subtle text-[11px]">{formatCompact(top * f)}</text>
          </g>
        ))}
        {lead.length > 1 && <polygon className="fill-jge-blue-50" points={`${x(0)},${y(0)} ${lead.map((p, i) => `${x(i)},${y(p.value)}`).join(' ')} ${x(lead.length - 1)},${y(0)}`} />}
        {series.slice(0, 3).map((s, n) => (
          <polyline key={s.name} fill="none" strokeWidth={n === 0 ? 2.5 : 2} strokeLinejoin="round" strokeLinecap="round" strokeDasharray={SERIES_STYLE[n].dash} className={SERIES_STYLE[n].stroke} points={s.points.map((p, i) => `${x(i)},${y(p.value)}`).join(' ')} />
        ))}
        {lead.map((p, i) => (
          <g key={p.key}>
            <circle cx={x(i)} cy={y(p.value)} r={lead.length > 40 ? 0 : 3} className="fill-jge-blue"><title>{`${labelOf(p.key)}: ${format(p.value)}`}</title></circle>
            {i % every === 0 && <text x={x(i)} y={H - 8} textAnchor="middle" className="fill-ink-subtle text-[11px]">{labelOf(p.key)}</text>}
          </g>
        ))}
      </svg>
      {series.length > 1 && (
        <figcaption className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
          {series.slice(0, 3).map((s, n) => (
            <span key={s.name} className="inline-flex items-center gap-1.5"><span className={`inline-block h-0.5 w-5 ${SERIES_STYLE[n].swatch}`} aria-hidden="true" />{s.name}</span>
          ))}
        </figcaption>
      )}
    </figure>
  )
}

/** Mini-tendencia sin ejes para una tarjeta de indicador; el valor y el cambio van en texto al lado. */
export function Sparkline({ values, label }: { values: number[]; label: string }) {
  const w = 120
  const h = 32
  const max = Math.max(...values, 0)
  const min = Math.min(...values, max)
  const span = max - min || 1
  const pts = values.map((v, i) => `${values.length === 1 ? w / 2 : (w * i) / (values.length - 1)},${h - 3 - ((v - min) / span) * (h - 6)}`)

  return (
    <svg viewBox={`0 0 ${w} ${h}`} role="img" aria-label={label} className="h-8 w-28">
      {values.length > 1 && <polyline points={pts.join(' ')} fill="none" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" className="stroke-jge-blue" />}
      {values.length > 0 && <circle cx={pts[pts.length - 1].split(',')[0]} cy={pts[pts.length - 1].split(',')[1]} r={2.5} className="fill-jge-blue" />}
    </svg>
  )
}

const TONE_TEXT: Record<Tone, string> = { good: 'Cumplida', warn: 'Cerca', bad: 'Lejos', neutral: '' }
const TONE_BAR: Record<Tone, string> = { good: 'bg-success', warn: 'bg-warning', bad: 'bg-danger', neutral: 'bg-jge-blue' }

/**
 * Barra de avance. `value` y `target` en la misma unidad; `marker` (opcional) señala lo esperado a la fecha o la proyección.
 * El estado se dice con texto además del color.
 */
export function ProgressBar({ label, value, target, marker, tone = 'neutral', format = formatMoney }: { label: string; value: number; target: number; marker?: number | null; tone?: Tone; format?: (v: number) => string }) {
  const pct = target > 0 ? (value / target) * 100 : 0
  const markerPct = marker != null && target > 0 ? Math.min((marker / target) * 100, 100) : null

  return (
    <div>
      <div className="mb-1 flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
        <span className="font-medium">{label}</span>
        <span className="tabular text-ink-muted">{format(value)} de {format(target)} · {pct.toLocaleString('es', { maximumFractionDigits: 1 })} %{TONE_TEXT[tone] && ` · ${TONE_TEXT[tone]}`}</span>
      </div>
      <div className="relative h-2.5 w-full rounded-full bg-jge-blue-50" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.min(Math.round(pct), 100)}>
        <div className={`h-2.5 rounded-full ${TONE_BAR[tone]}`} style={{ width: `${Math.min(pct, 100)}%` }} />
        {markerPct !== null && <div className="absolute top-[-3px] h-4 w-0.5 bg-ink" style={{ left: `${markerPct}%` }} title={`Referencia: ${format(marker as number)}`} />}
      </div>
    </div>
  )
}

export interface Slice {
  key: string
  label: string
  value: number
}

const SLICE_FILL = ['stroke-jge-blue', 'stroke-jge-navy-soft', 'stroke-warning', 'stroke-jge-red', 'stroke-danger', 'stroke-ink-subtle', 'stroke-success', 'stroke-line-strong']
const SLICE_SWATCH = ['bg-jge-blue', 'bg-jge-navy-soft', 'bg-warning', 'bg-jge-red', 'bg-danger', 'bg-ink-subtle', 'bg-success', 'bg-line-strong']

/** Anillo con la distribución de un total (p. ej. cartera por tramos). La leyenda trae valor y porcentaje de cada parte. */
export function DonutChart({ slices, label, center, format = formatMoney }: { slices: Slice[]; label: string; center?: ReactNode; format?: (v: number) => string }) {
  const total = slices.reduce((sum, s) => sum + s.value, 0)
  const r = 42
  const c = 2 * Math.PI * r
  let offset = 0

  return (
    <div className="flex flex-wrap items-center gap-4">
      <div className="relative h-36 w-36 shrink-0">
        <svg viewBox="0 0 100 100" role="img" aria-label={label} className="h-full w-full -rotate-90">
          <circle cx={50} cy={50} r={r} fill="none" strokeWidth={14} className="stroke-jge-blue-50" />
          {total > 0 && slices.map((s, i) => {
            const len = (s.value / total) * c
            const el = <circle key={s.key} cx={50} cy={50} r={r} fill="none" strokeWidth={14} strokeDasharray={`${len} ${c - len}`} strokeDashoffset={-offset} className={SLICE_FILL[i % SLICE_FILL.length]}><title>{`${s.label}: ${format(s.value)}`}</title></circle>
            offset += len
            return el
          })}
        </svg>
        {center && <div className="absolute inset-0 flex items-center justify-center text-center text-sm font-semibold">{center}</div>}
      </div>
      <ul className="min-w-40 flex-1 space-y-1 text-sm" aria-label={`Leyenda: ${label}`}>
        {slices.map((s, i) => (
          <li key={s.key} className="flex items-center justify-between gap-3">
            <span className="inline-flex items-center gap-2"><span className={`inline-block h-2.5 w-2.5 rounded-sm ${SLICE_SWATCH[i % SLICE_SWATCH.length]}`} aria-hidden="true" />{s.label}</span>
            <span className="tabular text-ink-muted">{format(s.value)} · {total > 0 ? ((s.value / total) * 100).toLocaleString('es', { maximumFractionDigits: 1 }) : 0} %</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Tabla alternativa de un gráfico, plegada por omisión: da las cifras exactas a quien no ve o no puede leer el gráfico. */
export function ChartData({ children, summary = 'Ver los datos en tabla' }: { children: ReactNode; summary?: string }) {
  return (
    <details className="mt-2 text-sm">
      <summary className="cursor-pointer text-jge-blue hover:underline">{summary}</summary>
      <div className="mt-2 overflow-x-auto">{children}</div>
    </details>
  )
}
