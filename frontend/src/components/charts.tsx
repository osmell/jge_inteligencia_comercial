import { formatMoney } from '../lib/format'
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
