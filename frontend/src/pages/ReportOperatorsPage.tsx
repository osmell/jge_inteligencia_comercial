import { useState } from 'react'
import BillingTable from '../components/reports/BillingTable'
import LevelValue from '../components/reports/LevelValue'
import OperatorDistributionView from '../components/reports/OperatorDistributionView'
import { PeriodLine, ReconciliationNote, ReportLegend, ReportNotes, ReportStat } from '../components/reports/ReportParts'
import ReportFilters from '../components/reports/ReportFilters'
import { Alert, DataTable, EmptyRow, FormError, Modal, PageHeader, TableSkeleton, td, th, tr } from '../components/ui'
import { formatMoney } from '../lib/format'
import type { OperatorReport, OperatorReportRow } from '../lib/reports'
import { formatPct } from '../lib/sales'
import { useLoad } from '../lib/useLoad'
import { useReportFilters } from '../lib/useReportFilters'

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('es'))
const dec = (v: number | null) => (v === null ? '—' : v.toLocaleString('es', { maximumFractionDigits: 2 }))
const signed = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${formatPct(v)}`)

const GROUPS: [string, number][] = [['', 2], ['Producción', 5], ['Precio', 2], ['Control de captura', 4], ['', 1]]

function Row({ r, onOpen }: { r: OperatorReportRow; onOpen: () => void }) {
  const s = r.semaphores
  const c = r.control
  return (
    <tr className={tr}>
      <td className={`${td} tabular text-right text-ink-muted`}>{r.rank ?? '—'}</td>
      <td className={`${td} font-medium`}>
        <button type="button" className="cursor-pointer text-left text-jge-blue hover:underline" onClick={onOpen} aria-label={`Ver el detalle de ${r.operator.name}`}>{r.operator.name}</button>
        <span className="block text-xs font-normal text-ink-muted">{r.operator.company}{r.operator.is_active ? '' : ' · inactivo'}</span>
      </td>
      <td className={`${td} text-right`}>
        <span className="tabular font-medium">{formatMoney(r.sales.net_total)}</span>
        <span className="block text-xs"><LevelValue level={s.growth_pct}>{signed(r.sales.growth_pct)}</LevelValue></span>
      </td>
      <td className={`${td} tabular text-right`}>{n(r.sales.documents)}<span className="block text-xs text-ink-muted">{signed(r.sales.documents_growth_pct)}</span></td>
      <td className={`${td} tabular text-right`}>{n(r.production.lines)}<span className="block text-xs text-ink-muted">{dec(r.production.lines_per_document)} por documento</span></td>
      <td className={`${td} tabular text-right`}>{n(r.production.active_days)}<span className="block text-xs text-ink-muted">{dec(r.production.documents_per_active_day)} doc./día</span></td>
      <td className={`${td} tabular text-right`}>{formatMoney(r.sales.average_ticket)}</td>
      <td className={`${td} border-l border-line text-right`}><LevelValue level={s.discount_pct}>{formatPct(r.sales.discount_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.below_list_pct}>{formatPct(r.sales.below_list_pct)}</LevelValue></td>
      <td className={`${td} border-l border-line text-right`}><LevelValue level={s.high_discount_lines_pct}>{n(c.high_discount_lines)} · {formatPct(c.high_discount_lines_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.zero_price_lines_pct}>{n(c.zero_price_lines)} · {formatPct(c.zero_price_lines_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.atypical_documents_pct}>{n(c.atypical_documents)} · {formatPct(c.atypical_documents_pct)}</LevelValue></td>
      <td className={`${td} tabular text-right`}>{n(c.negative_documents)}</td>
      <td className={`${td} border-l border-line tabular text-right`}>{formatPct(c.direct_sales_pct)}</td>
    </tr>
  )
}

export default function ReportOperatorsPage() {
  const filters = useReportFilters()
  const [open, setOpen] = useState<OperatorReportRow | null>(null)
  const [tab, setTab] = useState<'documents' | 'distribution'>('documents')
  const report = useLoad<{ data: OperatorReport }>(`/reports/operators?${filters.query()}`, { enabled: filters.valid })
  const data = report.data?.data
  const rows = data?.rows ?? []
  const t = data?.totals

  function openOperator(r: OperatorReportRow) {
    setTab('documents')
    setOpen(r)
  }

  return (
    <section>
      <PageHeader title="Reporte de operadores" description="Producción y control de captura de quienes facturan: documentos, descuentos, líneas sin precio y documentos atípicos, con semáforo por indicador." />
      <ReportFilters filters={filters} />
      <FormError message={filters.contextError ?? report.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido (y, si comparas con otro periodo, sus dos fechas): la fecha inicial no puede ser posterior a la final.</Alert></div>}
      {data && <PeriodLine data={data} />}
      {data && <ReportNotes notes={data.notes} />}

      {t && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <ReportStat label="Venta neta facturada" value={formatMoney(t.net_total)} hint={t.growth_pct === null ? 'sin periodo anterior' : `${signed(t.growth_pct)} vs. comparación`} />
          <ReportStat label="Documentos" value={n(t.documents)} hint={`${n(t.lines)} líneas · ticket ${formatMoney(t.average_ticket)}`} />
          <ReportStat label="Descuentos altos" value={n(t.high_discount_lines)} hint="líneas con descuento alto" />
          <ReportStat label="Sin precio / atípicos" value={`${n(t.zero_price_lines)} / ${n(t.atypical_documents)}`} hint={`${n(t.negative_documents)} documentos negativos`} />
          <ReportStat label="Indicadores críticos" value={n(t.red_flags)} hint="suma de rojos entre todos los operadores" />
        </div>
      )}

      {data && <ReconciliationNote rec={data.reconciliation} who="los operadores" />}

      <DataTable label="Evaluación de operadores">
        <thead>
          <tr>
            {GROUPS.map(([title, span], i) => <th key={i} colSpan={span} className={`${th} ${title ? 'border-l border-line text-center' : ''}`}>{title}</th>)}
          </tr>
          <tr>
            <th className={`${th} text-right`}>#</th>
            <th className={th}>Operador</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Documentos</th>
            <th className={`${th} text-right`}>Líneas</th>
            <th className={`${th} text-right`}>Días activos</th>
            <th className={`${th} text-right`}>Ticket</th>
            <th className={`${th} border-l border-line text-right`}>Descuento</th>
            <th className={`${th} text-right`}>Bajo precio normal</th>
            <th className={`${th} border-l border-line text-right`}>Descuento alto</th>
            <th className={`${th} text-right`}>Sin precio</th>
            <th className={`${th} text-right`}>Atípicos</th>
            <th className={`${th} text-right`}>Negativos</th>
            <th className={`${th} border-l border-line text-right`}>Venta directa</th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={14} />}
          {data && rows.length === 0 && <EmptyRow cols={14}>No hay operadores con facturación en el periodo.</EmptyRow>}
          {rows.map((r) => <Row key={r.operator.id} r={r} onOpen={() => openOperator(r)} />)}
        </tbody>
      </DataTable>

      <ReportLegend>
        <span>Descuento alto, sin precio y atípicos: ver cómo se definen en el documento de reportes. «Venta directa»: el vendedor y el operador son la misma persona.</span>
      </ReportLegend>

      {open && (
        <Modal title={`Detalle de ${open.operator.name}`} onClose={() => setOpen(null)} wide>
          <div role="tablist" aria-label="Detalle del operador" className="mb-4 flex gap-2 border-b border-line">
            {([['documents', 'Facturas'], ['distribution', 'A quién y cuándo factura']] as const).map(([key, label]) => (
              <button
                key={key}
                role="tab"
                type="button"
                aria-selected={tab === key}
                className={`-mb-px cursor-pointer border-b-2 px-3 py-2 text-sm font-medium ${tab === key ? 'border-jge-blue text-jge-blue' : 'border-transparent text-ink-muted hover:text-ink'}`}
                onClick={() => setTab(key)}
              >
                {label}
              </button>
            ))}
          </div>
          {tab === 'documents'
            ? <BillingTable path={`/reports/operators/${open.operator.id}/documents`} query={filters.query()} label={`Documentos facturados por ${open.operator.name}`} />
            : <OperatorDistributionView operatorId={open.operator.id} query={filters.query()} />}
        </Modal>
      )}
    </section>
  )
}
