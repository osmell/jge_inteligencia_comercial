import { CircleAlert, CircleCheck, CircleDot } from 'lucide-react'
import { Alert, DataTable, td, th, tr, Th } from '../ui'
import { formatDate } from '../../lib/customers'
import { formatMoney } from '../../lib/format'
import type { Conciliation } from '../../lib/reports'

type Cut = Conciliation['origin'][number]
type Row = Cut['rows'][number]

const n = (v: number) => v.toLocaleString('es')

const STATUS = {
  ok: { icon: CircleCheck, text: 'Cuadra', tone: 'text-success' },
  near: { icon: CircleDot, text: 'Cerca', tone: 'text-warning' },
  differs: { icon: CircleAlert, text: 'Diferencia', tone: 'font-medium text-danger' },
} as const

/** Estado con ícono y texto, no solo con color. */
function State({ status }: { status: string }) {
  const s = STATUS[status as keyof typeof STATUS] ?? STATUS.differs
  const Icon = s.icon

  return <span className={`inline-flex items-center gap-1.5 ${s.tone}`}><Icon className="h-4 w-4" aria-hidden="true" />{s.text}</span>
}

function Line({ row, total = false }: { row: Row; total?: boolean }) {
  return (
    <tr className={`${tr} ${total ? 'font-semibold' : ''}`}>
      <td className={td}>{row.seller}</td>
      <td className={`${td} tabular text-right`}>{formatMoney(row.erp_net)}</td>
      <td className={`${td} tabular text-right`}>{formatMoney(row.system_net)}</td>
      <td className={`${td} tabular text-right ${row.difference === 0 ? '' : 'font-medium'}`}>{formatMoney(row.difference)}</td>
      <td className={`${td} tabular text-right`}>{row.difference_pct === null ? '—' : `${row.difference_pct.toLocaleString('es')} %`}</td>
      <td className={`${td} tabular text-right text-ink-muted`}>{formatMoney(row.imported_net)}</td>
      <td className={`${td} tabular text-right`}>{n(row.erp_invoices)}</td>
      <td className={`${td} tabular text-right`}>{n(row.system_documents + row.voided_documents)}</td>
      <td className={td}><State status={row.status} /></td>
    </tr>
  )
}

/** Venta neta oficial del sistema de origen (resumen por vendedor) contra la que calcula el sistema, vendedor por vendedor. */
export default function OriginConciliationView({ cuts }: { cuts: Cut[] }) {
  return (
    <>
      <h2 className="mb-1 mt-8 text-sm font-semibold">Contra el sistema de origen</h2>
      <p className="mb-3 text-xs text-ink-muted">Venta neta oficial del resumen por vendedor del sistema de origen, del inicio del periodo al último corte cargado, contra lo que calcula el sistema.</p>
      {cuts.length === 0 && <Alert tone="info">No hay un resumen por vendedor cargado hasta esta fecha. Se carga desde Importaciones (tipo «Resumen por vendedor»).</Alert>}
      {cuts.map((c) => (
        <div key={`${c.company.id}-${c.cutoff}`} className="mb-6">
          <p className="mb-2 text-sm"><span className="font-medium">{c.company.name}</span> · del {formatDate(c.period_from)} al {formatDate(c.cutoff)}</p>
          <DataTable label={`Conciliación contra el origen, ${c.company.name}`}>
            <thead>
              <tr>
                <th className={th}>Vendedor</th>
                <Th right info={{ text: 'Venta Neta del resumen por vendedor del sistema de origen: el dato oficial.' }}>Origen (neta)</Th>
                <Th right info={{ text: 'Venta neta que calcula el sistema: ventas por equivalencia corregidas y sin facturas anuladas.', formula: 'Sistema = Σ neto corregido de sus líneas (sin facturas anuladas)' }}>Sistema (corregida)</Th>
                <Th right info={{ text: 'Cuánto falta (positivo) o sobra (negativo) en el sistema respecto del origen.', formula: 'Diferencia = Origen − Sistema' }}>Diferencia</Th>
                <Th right info={{ text: 'La diferencia como porcentaje de la venta oficial del origen.', formula: 'Diferencia % = Diferencia ÷ Origen × 100' }}>%</Th>
                <Th right info={{ text: 'Venta neta tal como venía en los archivos importados, sin corregir.' }}>Importada</Th>
                <Th right info={{ text: 'Facturas que cuenta el origen, incluidas las anuladas.' }}>Facturas (origen)</Th>
                <Th right info={{ text: 'Documentos del sistema más los anulados. Debe coincidir con las facturas del origen.' }}>Documentos (sistema)</Th>
                <Th info={{ text: 'Cuadra: diferencia de hasta 1 córdoba. Cerca: hasta 1 % de la venta oficial. Diferencia: más que eso.' }}>Estado</Th>
              </tr>
            </thead>
            <tbody>
              <Line row={{ ...c.totals, voided_documents: c.totals.voided_documents }} total />
              {c.rows.map((r) => <Line key={r.seller} row={r} />)}
            </tbody>
          </DataTable>
          {c.voided_without_sale > 0 && (
            <p className="mt-2 text-xs text-ink-muted">{n(c.voided_without_sale)} factura(s) anulada(s) no tienen venta cargada (el archivo de ventas las deja en cero): se cuentan en el total de documentos pero no se pueden asignar a un vendedor.</p>
          )}
        </div>
      ))}
    </>
  )
}
