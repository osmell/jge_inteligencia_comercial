import { CircleAlert, CircleCheck } from 'lucide-react'
import { useState } from 'react'
import BillingTable from '../components/reports/BillingTable'
import IssuesModal from '../components/reports/IssuesModal'
import ReportActions from '../components/reports/ReportActions'
import ReportFilters from '../components/reports/ReportFilters'
import { ReportStat } from '../components/reports/ReportParts'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, td, th, tr } from '../components/ui'
import { formatDate } from '../lib/customers'
import { formatMoney } from '../lib/format'
import { DIMENSION_LABEL, INTEGRITY_LABEL, type Conciliation } from '../lib/reports'
import { useLoad } from '../lib/useLoad'
import { useReportFilters } from '../lib/useReportFilters'

const n = (v: number) => v.toLocaleString('es')

/** Estado con ícono y texto, no solo con color. */
function Status({ ok, good = 'Cuadra', bad = 'Diferencia' }: { ok: boolean; good?: string; bad?: string }) {
  return ok
    ? <span className="inline-flex items-center gap-1.5 text-success"><CircleCheck className="h-4 w-4" aria-hidden="true" />{good}</span>
    : <span className="inline-flex items-center gap-1.5 font-medium text-danger"><CircleAlert className="h-4 w-4" aria-hidden="true" />{bad}</span>
}

const SUBJECTS = [['seller', 'Vendedor', 'sellers'], ['operator', 'Operador', 'operators'], ['branch', 'Sucursal', 'branches']] as const
type Subject = (typeof SUBJECTS)[number][0]

/** Detalle de facturación de una persona o sucursal, para contrastarlo con la cifra consolidada. */
function BillingBySubject({ filters }: { filters: ReturnType<typeof useReportFilters> }) {
  const [subject, setSubject] = useState<Subject>('seller')
  const [id, setId] = useState('')
  const context = filters.context
  const options = !context ? [] : subject === 'seller' ? context.sellers : subject === 'operator' ? context.operators : context.branches
  const visible = options.filter((o) => !filters.values.company_id || String(o.company_id) === filters.values.company_id)
  const path = { seller: 'sellers', operator: 'operators', branch: 'branches' }[subject]

  return (
    <section aria-labelledby="billing-title" className="mt-8">
      <h2 id="billing-title" className="text-sm font-semibold">Detalle de facturación</h2>
      <p className="mb-3 mt-1 text-xs text-ink-muted">Elige un vendedor, un operador o una sucursal para ver cada documento del periodo y contrastar su total con las cifras de arriba.</p>
      <div className="mb-3 flex flex-wrap items-end gap-3">
        <SelectField id="billing-subject" label="Ver por" value={subject} onChange={(e) => { setSubject(e.target.value as Subject); setId('') }}>
          {SUBJECTS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </SelectField>
        <SelectField id="billing-entity" label={SUBJECTS.find(([k]) => k === subject)![1]} value={id} onChange={(e) => setId(e.target.value)}>
          <option value="">Elige uno…</option>
          {visible.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </SelectField>
      </div>
      {id && filters.valid && <BillingTable key={`${subject}-${id}-${filters.query()}`} path={`/reports/${path}/${id}/documents`} query={filters.query()} label="Documentos facturados" />}
    </section>
  )
}

export default function ReportConciliationPage() {
  const filters = useReportFilters()
  const [issues, setIssues] = useState<string | null>(null)
  const load = useLoad<{ data: Conciliation }>(`/reports/conciliation?${filters.query()}`, { enabled: filters.valid })
  const d = load.data?.data

  return (
    <section>
      <PageHeader title="Conciliación" description="Contrasta la venta consolidada con sus partes (compañías, sucursales, vendedores y operadores), con lo que declaraba cada archivo importado y con las facturas de crédito, y señala dónde hay diferencias." />
      <ReportFilters filters={filters} comparison={false} />
      <ReportActions report="conciliation" filters={filters} />
      <FormError message={filters.contextError ?? load.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido: la fecha inicial no puede ser posterior a la final.</Alert></div>}

      {d && (
        <>
          <p className="mb-3 text-sm text-ink-muted">Periodo {formatDate(d.period.from)} – {formatDate(d.period.to)} ({d.period.days} días)</p>
          <div className="mb-4">
            {d.problems === 0
              ? <Alert tone="success">Todo cuadra: las sumas, la integridad de los documentos, las importaciones y las facturas de crédito coinciden.</Alert>
              : <Alert tone="danger">Hay {d.problems} {d.problems === 1 ? 'concepto con diferencias' : 'conceptos con diferencias'}. Revisa abajo cuáles son y abre el detalle para localizar los documentos.</Alert>}
          </div>

          <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <ReportStat label="Venta neta" value={formatMoney(d.consolidated.net_total)} />
            <ReportStat label="Impuesto" value={formatMoney(d.consolidated.tax_total)} />
            <ReportStat label="Total facturado" value={formatMoney(d.consolidated.grand_total)} />
            <ReportStat label="Documentos" value={n(d.consolidated.documents)} />
            <ReportStat label="Líneas" value={n(d.consolidated.lines)} />
          </div>

          <h2 className="mb-2 text-sm font-semibold">Sumas contra el consolidado</h2>
          <DataTable label="Sumas por dimensión contra el consolidado">
            <thead>
              <tr>
                <th className={th}>Desglose</th>
                <th className={`${th} text-right`}>Filas</th>
                <th className={`${th} text-right`}>Venta neta</th>
                <th className={`${th} text-right`}>Documentos</th>
                <th className={`${th} text-right`}>Diferencia neta</th>
                <th className={`${th} text-right`}>Diferencia documentos</th>
                <th className={th}>Estado</th>
              </tr>
            </thead>
            <tbody>
              {d.breakdowns.map((b) => (
                <tr key={b.dimension} className={tr}>
                  <td className={`${td} font-medium`}>{DIMENSION_LABEL[b.dimension] ?? b.dimension}</td>
                  <td className={`${td} tabular text-right`}>{n(b.rows)}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(b.net_total)}</td>
                  <td className={`${td} tabular text-right`}>{n(b.documents)}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(b.net_difference)}</td>
                  <td className={`${td} tabular text-right`}>{n(b.documents_difference)}</td>
                  <td className={td}><Status ok={b.matches} /></td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <h2 className="mb-2 mt-8 text-sm font-semibold">Integridad de los documentos</h2>
          <DataTable label="Integridad de los documentos de venta">
            <thead><tr><th className={th}>Comprobación</th><th className={`${th} text-right`}>Documentos</th><th className={th}>Estado</th><th className={th} /></tr></thead>
            <tbody>
              {d.integrity.map((c) => (
                <tr key={c.code} className={tr}>
                  <td className={td}>{INTEGRITY_LABEL[c.code] ?? c.code}</td>
                  <td className={`${td} tabular text-right`}>{n(c.count)}</td>
                  <td className={td}><Status ok={c.ok} good="Correcto" bad="Revisar" /></td>
                  <td className={td}>{!c.ok && <Button variant="secondary" onClick={() => setIssues(c.code)}>Ver documentos</Button>}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <h2 className="mb-2 mt-8 text-sm font-semibold">Archivos de ventas importados</h2>
          <DataTable label="Importaciones contra lo guardado">
            <thead>
              <tr>
                <th className={th}>Archivo</th>
                <th className={`${th} text-right`}>Documentos nuevos (archivo)</th>
                <th className={`${th} text-right`}>Guardados</th>
                <th className={`${th} text-right`}>Venta neta (archivo)</th>
                <th className={`${th} text-right`}>Guardada</th>
                <th className={th}>Estado</th>
              </tr>
            </thead>
            <tbody>
              {d.imports.batches.length === 0 && <EmptyRow cols={6}>No hay importaciones de ventas que toquen el periodo.</EmptyRow>}
              {d.imports.batches.map((b) => (
                <tr key={b.id} className={tr}>
                  <td className={td}>{b.name}<span className="block text-xs text-ink-muted">{b.date_from ? `${formatDate(b.date_from)} – ${b.date_to ? formatDate(b.date_to) : ''}` : ''}</span></td>
                  <td className={`${td} tabular text-right`}>{n(b.declared_documents)}</td>
                  <td className={`${td} tabular text-right`}>{n(b.registered_documents)}</td>
                  <td className={`${td} tabular text-right`}>{b.net_comparable ? formatMoney(b.declared_net) : <span className="text-ink-muted" title="El archivo traía documentos ya cargados antes: su venta no se puede comparar">no comparable</span>}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(b.registered_net)}</td>
                  <td className={td}><Status ok={b.matches} /></td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          {d.imports.out_of_scope > 0 && <p className="mt-2 text-xs text-ink-muted">{d.imports.out_of_scope} importación(es) incluyen compañías fuera de tu alcance y no se evalúan.</p>}

          <h2 className="mb-2 mt-8 text-sm font-semibold">Facturas de crédito contra ventas</h2>
          <Card className="flex flex-wrap items-center gap-x-8 gap-y-2 p-4 text-sm">
            <span><span className="tabular font-semibold">{n(d.credit.linked)}</span> enlazadas a una venta</span>
            <span><span className="tabular font-semibold">{n(d.credit.unlinked)}</span> sin enlazar <span className="text-xs text-ink-muted">(aún no hay venta que comparar)</span></span>
            <Status ok={d.credit.mismatched === 0} good="Sin diferencias" bad={`${n(d.credit.mismatched)} con diferencias`} />
            {d.credit.mismatched > 0 && <Button variant="secondary" onClick={() => setIssues('credit_invoice_mismatch')}>Ver facturas</Button>}
          </Card>

          <BillingBySubject filters={filters} />
        </>
      )}

      {issues && <IssuesModal code={issues} query={filters.query()} onClose={() => setIssues(null)} />}
    </section>
  )
}
