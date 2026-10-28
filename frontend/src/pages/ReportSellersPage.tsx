import { useState } from 'react'
import LevelValue from '../components/reports/LevelValue'
import ReportActions from '../components/reports/ReportActions'
import ReportFilters from '../components/reports/ReportFilters'
import BillingTable from '../components/reports/BillingTable'
import { PeriodLine, ReconciliationNote, ReportLegend, ReportNotes, ReportStat } from '../components/reports/ReportParts'
import { Alert, DataTable, EmptyRow, FormError, Modal, PageHeader, Pager, TableSkeleton, Th, td, th, tr } from '../components/ui'
import { formatMoney } from '../lib/format'
import type { SellerReport, SellerReportRow } from '../lib/reports'
import { formatPct } from '../lib/sales'
import { useLoad } from '../lib/useLoad'
import { usePaged } from '../lib/usePaged'
import { useReportFilters } from '../lib/useReportFilters'

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('en-US'))
const dec = (v: number | null) => (v === null ? '—' : v.toLocaleString('en-US', { maximumFractionDigits: 2 }))

const GROUPS: [string, number][] = [['', 2], ['Resultado', 3], ['Meta', 1], ['Disciplina de precio', 3], ['Cartera de clientes (hoy)', 3], ['Cobranza (hoy)', 2], ['Seguimiento', 3]]

function Row({ r, onOpen }: { r: SellerReportRow; onOpen: () => void }) {
  const s = r.semaphores
  const f = r.follow_up
  return (
    <tr className={tr}>
      <td className={`${td} tabular text-right text-ink-muted`}>{r.rank ?? '—'}</td>
      <td className={`${td} font-medium`}>
        <button type="button" className="cursor-pointer text-left text-jge-blue hover:underline" onClick={onOpen} aria-label={`Ver la facturación de ${r.seller.name}`}>{r.seller.name}</button>
        <span className="block text-xs font-normal text-ink-muted">{r.seller.company}{r.seller.branch ? ` · ${r.seller.branch}` : ''}{r.seller.is_active ? '' : ' · inactivo'}</span>
      </td>
      <td className={`${td} text-right`}>
        <span className="tabular font-medium">{formatMoney(r.sales.net_total)}</span>
        <span className="block text-xs"><LevelValue level={s.growth_pct}>{r.sales.growth_pct === null ? '—' : `${r.sales.growth_pct > 0 ? '+' : ''}${formatPct(r.sales.growth_pct)}`}</LevelValue></span>
      </td>
      <td className={`${td} tabular text-right`}>{n(r.sales.documents)}<span className="block text-xs text-ink-muted">ticket {formatMoney(r.sales.average_ticket)}</span></td>
      <td className={`${td} tabular text-right`}>{n(r.sales.customers)}<span className="block text-xs text-ink-muted">{dec(r.sales.categories_per_customer)} cat./cliente</span></td>
      <td className={`${td} text-right`}>
        <LevelValue level={s.goal_attainment}>{formatPct(r.goal.attainment_pct)}</LevelValue>
        {r.goal.goal !== null && <span className="block text-xs text-ink-muted">meta {formatMoney(r.goal.goal)}{r.goal.projected_pct !== null ? ` · proyecta ${formatPct(r.goal.projected_pct)}` : ''}</span>}
      </td>
      <td className={`${td} text-right`}><LevelValue level={s.discount_pct}>{formatPct(r.sales.discount_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.below_list_pct}>{formatPct(r.sales.below_list_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.top3_concentration_pct}>{formatPct(r.sales.top3_concentration_pct)}</LevelValue></td>
      <td className={`${td} tabular text-right`}>{n(r.portfolio.total)}<span className="block text-xs text-ink-muted">{r.portfolio.new} nuevos · {r.portfolio.recovered} recup.</span></td>
      <td className={`${td} tabular text-right`}>{n(r.portfolio.inactive)}</td>
      <td className={`${td} text-right`}><LevelValue level={s.lost_customers_pct}>{n(r.portfolio.lost)} · {formatPct(r.portfolio.lost_pct)}</LevelValue></td>
      <td className={`${td} tabular text-right`}>{formatMoney(r.credit.balance)}</td>
      <td className={`${td} text-right`}><LevelValue level={s.overdue_pct}>{formatPct(r.credit.overdue_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.quote_conversion_pct}>{formatPct(f.quote_conversion_pct)}</LevelValue><span className="block text-xs text-ink-muted">{f.quotes_converted} de {f.quotes_issued}</span></td>
      <td className={`${td} tabular text-right`}>{f.has_user ? n(f.contacts) : <span title="El vendedor no está vinculado a un usuario del sistema" className="text-ink-muted">sin usuario</span>}</td>
      <td className={`${td} text-right`}><LevelValue level={s.overdue_follow_ups}>{n(f.overdue_follow_ups)}</LevelValue></td>
    </tr>
  )
}

export default function ReportSellersPage() {
  const filters = useReportFilters()
  const [open, setOpen] = useState<SellerReportRow | null>(null)
  const report = useLoad<{ data: SellerReport }>(`/reports/sellers?${filters.query()}`, { enabled: filters.valid })
  const data = report.data?.data
  const rows = data?.rows ?? []
  const paged = usePaged(rows)
  const t = data?.totals
  const rec = data?.reconciliation

  return (
    <section>
      <PageHeader title="Reporte de vendedores" description="Resultado, cumplimiento de meta, disciplina de precio, cartera, cobranza y seguimiento de cada vendedor, con semáforo por indicador." />
      <ReportFilters filters={filters} />
      <ReportActions report="sellers" filters={filters} />
      <FormError message={filters.contextError ?? report.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido (y, si comparas con otro periodo, sus dos fechas): la fecha inicial no puede ser posterior a la final.</Alert></div>}
      {data && <PeriodLine data={data} />}
      {data && <ReportNotes notes={data.notes} />}

      {t && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <ReportStat label="Venta neta" value={formatMoney(t.net_total)} hint={t.growth_pct === null ? 'sin periodo anterior' : `${t.growth_pct > 0 ? '+' : ''}${formatPct(t.growth_pct)} vs. comparación`} />
          <ReportStat label="Documentos" value={n(t.documents)} hint={`ticket ${formatMoney(t.average_ticket)}`} />
          <ReportStat label="Cumplimiento de meta" value={formatPct(t.attainment_pct)} hint={`${t.sellers_with_goal} de ${t.sellers} vendedores con meta`} />
          <ReportStat label="Saldo vencido" value={formatMoney(t.credit_overdue)} hint={`de ${formatMoney(t.credit_balance)} por cobrar`} />
          <ReportStat label="Indicadores críticos" value={n(t.red_flags)} hint="suma de rojos entre todos los vendedores" />
        </div>
      )}

      {rec && <ReconciliationNote rec={rec} who="los vendedores" />}

      <DataTable scroll label="Evaluación de vendedores">
        <thead>
          <tr>
            {GROUPS.map(([title, span], i) => <th key={i} colSpan={span} className={`${th} ${title ? 'border-l border-line text-center' : ''}`}>{title}</th>)}
          </tr>
          <tr>
            <Th right info="rep_rank">#</Th>
            <th className={th}>Vendedor</th>
            <Th right info="sales_net">Venta neta</Th>
            <Th right info="sales_documents">Documentos</Th>
            <Th right info="sales_customers">Clientes</Th>
            <Th right className="border-l border-line" info="rep_goal">Cumplimiento</Th>
            <Th right className="border-l border-line" info="rep_discount">Descuento</Th>
            <Th right info="rep_below_list">Bajo precio normal</Th>
            <Th right info="rep_top3">Top 3 clientes</Th>
            <Th right className="border-l border-line" info="rep_portfolio">Clientes</Th>
            <Th right info="rep_inactive">Inactivos</Th>
            <Th right info="rep_lost">Perdidos</Th>
            <Th right className="border-l border-line" info="rep_receivable">Por cobrar</Th>
            <Th right info="rep_overdue">Vencido</Th>
            <Th right className="border-l border-line" info="rep_quote_conversion">Conversión cot.</Th>
            <Th right info="rep_contacts">Contactos</Th>
            <Th right info="rep_pending">Pendientes vencidos</Th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={17} />}
          {data && rows.length === 0 && <EmptyRow cols={17}>No hay vendedores con ventas ni actividad en el periodo.</EmptyRow>}
          {paged.rows.map((r) => <Row key={r.seller.id} r={r} onOpen={() => setOpen(r)} />)}
        </tbody>
      </DataTable>
      <Pager paged={paged} />

      <ReportLegend><span>Haz clic en un vendedor para ver sus facturas.</span></ReportLegend>

      {open && (
        <Modal title={`Facturación de ${open.seller.name}`} onClose={() => setOpen(null)} extraWide>
          <BillingTable path={`/reports/sellers/${open.seller.id}/documents`} query={filters.query()} label={`Documentos facturados por ${open.seller.name}`} />
        </Modal>
      )}
    </section>
  )
}
