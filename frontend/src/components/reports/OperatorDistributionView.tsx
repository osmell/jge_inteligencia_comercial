import { formatMoney } from '../../lib/format'
import type { OperatorDistribution } from '../../lib/reports'
import { formatPct } from '../../lib/sales'
import { useLoad } from '../../lib/useLoad'
import { Badge, DataTable, FormError, TableSkeleton, td, th, tr } from '../ui'

const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']

/** Barras horizontales con su cifra escrita: el valor nunca depende solo de la longitud. */
function Bars({ title, labels, values }: { title: string; labels: string[]; values: number[] }) {
  const max = Math.max(1, ...values)
  return (
    <section aria-label={title}>
      <h3 className="mb-2 text-sm font-semibold">{title}</h3>
      <ul className="space-y-1">
        {labels.map((label, i) => (
          <li key={label} className="flex items-center gap-2 text-xs">
            <span className="w-20 shrink-0 text-ink-muted">{label}</span>
            <span className="h-2.5 flex-1 rounded-full bg-surface-muted"><span className="block h-2.5 rounded-full bg-jge-blue" style={{ width: `${(values[i]! / max) * 100}%` }} /></span>
            <span className="tabular w-10 shrink-0 text-right">{values[i]}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** A quién y cuándo factura un operador: vendedores, sucursales y actividad por día de la semana y por hora. */
export default function OperatorDistributionView({ operatorId, query }: { operatorId: number; query: string }) {
  const load = useLoad<{ data: OperatorDistribution }>(`/reports/operators/${operatorId}/distribution?${query}`)
  const d = load.data?.data

  return (
    <div className="space-y-6">
      <FormError message={load.error} />
      <div className="grid gap-4 lg:grid-cols-2">
        <DataTable label="Vendedores a los que factura">
          <thead><tr><th className={th}>Vendedor</th><th className={`${th} text-right`}>Venta neta</th><th className={`${th} text-right`}>%</th></tr></thead>
          <tbody>
            {!d && load.loading && <TableSkeleton cols={3} />}
            {d?.sellers.map((s) => (
              <tr key={s.id} className={tr}>
                <td className={td}>{s.name} {s.direct && <Badge>venta directa</Badge>}</td>
                <td className={`${td} tabular text-right`}>{formatMoney(s.net_total)}</td>
                <td className={`${td} tabular text-right`}>{formatPct(s.share_pct)}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
        <DataTable label="Sucursales en las que factura">
          <thead><tr><th className={th}>Sucursal</th><th className={`${th} text-right`}>Venta neta</th><th className={`${th} text-right`}>%</th></tr></thead>
          <tbody>
            {!d && load.loading && <TableSkeleton cols={3} />}
            {d?.branches.map((b) => (
              <tr key={b.id} className={tr}>
                <td className={td}>{b.name}</td>
                <td className={`${td} tabular text-right`}>{formatMoney(b.net_total)}</td>
                <td className={`${td} tabular text-right`}>{formatPct(b.share_pct)}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>
      {d && d.sellers_count > d.sellers.length && <p className="text-xs text-ink-muted">Se muestran los {d.sellers.length} vendedores con más venta de {d.sellers_count}.</p>}
      {d && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Bars title="Documentos por día de la semana" labels={WEEKDAYS} values={d.by_weekday} />
          {d.by_hour ? (
            <div>
              <Bars title="Documentos por hora" labels={d.by_hour.map((_, h) => `${String(h).padStart(2, '0')}:00`).filter((_, h) => d.by_hour![h]! > 0)} values={d.by_hour.filter((v) => v > 0)} />
              <p className="mt-2 text-xs text-ink-muted">La hora solo viene en {d.timed_documents} de {d.documents} documentos.</p>
            </div>
          ) : (
            <p className="text-sm text-ink-muted">Los archivos de venta de este periodo no traen la hora de cada documento: no se puede mostrar la actividad por hora.</p>
          )}
        </div>
      )}
    </div>
  )
}
