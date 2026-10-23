import { CircleAlert, CircleCheck } from 'lucide-react'
import { useState } from 'react'
import LevelValue from '../components/reports/LevelValue'
import ReportFilters from '../components/reports/ReportFilters'
import SellerBillingModal from '../components/reports/SellerBillingModal'
import { Alert, Card, DataTable, EmptyRow, FormError, PageHeader, TableSkeleton, td, th, tr } from '../components/ui'
import { formatDate } from '../lib/customers'
import { formatMoney } from '../lib/format'
import { REPORT_NOTE, type SellerReport, type SellerReportRow } from '../lib/reports'
import { formatPct } from '../lib/sales'
import { useLoad } from '../lib/useLoad'
import { useReportFilters } from '../lib/useReportFilters'

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular mt-0.5 text-2xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('es'))

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
      <td className={`${td} tabular text-right`}>{n(r.sales.customers)}<span className="block text-xs text-ink-muted">{r.sales.categories_per_customer ?? '—'} cat./cliente</span></td>
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
  const t = data?.totals
  const rec = data?.reconciliation

  return (
    <section>
      <PageHeader title="Reporte de vendedores" description="Resultado, cumplimiento de meta, disciplina de precio, cartera, cobranza y seguimiento de cada vendedor, con semáforo por indicador." />
      <ReportFilters filters={filters} />
      <FormError message={filters.contextError ?? report.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido (y, si comparas con otro periodo, sus dos fechas): la fecha inicial no puede ser posterior a la final.</Alert></div>}
      {data && (
        <p className="mb-3 text-sm text-ink-muted">
          Periodo {formatDate(data.period.from)} – {formatDate(data.period.to)} ({data.period.days} días) · comparado con {formatDate(data.comparison.from)} – {formatDate(data.comparison.to)} ({data.comparison.days} días)
        </p>
      )}
      {data?.notes.map((note) => <div key={note} className="mb-2"><Alert tone="info">{REPORT_NOTE[note] ?? note}</Alert></div>)}

      {t && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <Stat label="Venta neta" value={formatMoney(t.net_total)} hint={t.growth_pct === null ? 'sin periodo anterior' : `${t.growth_pct > 0 ? '+' : ''}${formatPct(t.growth_pct)} vs. comparación`} />
          <Stat label="Documentos" value={n(t.documents)} hint={`ticket ${formatMoney(t.average_ticket)}`} />
          <Stat label="Cumplimiento de meta" value={formatPct(t.attainment_pct)} hint={`${t.sellers_with_goal} de ${t.sellers} vendedores con meta`} />
          <Stat label="Saldo vencido" value={formatMoney(t.credit_overdue)} hint={`de ${formatMoney(t.credit_balance)} por cobrar`} />
          <Stat label="Indicadores críticos" value={n(t.red_flags)} hint="suma de rojos entre todos los vendedores" />
        </div>
      )}

      {rec && (
        <div className="mb-4">
          {rec.matches ? (
            <p className="flex items-center gap-2 text-sm text-success"><CircleCheck className="h-4 w-4" aria-hidden="true" />Contraste: la venta consolidada ({formatMoney(rec.consolidated_net)}, {n(rec.consolidated_documents)} documentos) coincide con la suma de los vendedores.</p>
          ) : (
            <Alert tone="danger">La venta consolidada ({formatMoney(rec.consolidated_net)}) no coincide con la suma de los vendedores ({formatMoney(rec.sellers_net)}): diferencia de {formatMoney(rec.net_difference)} y {rec.documents_difference} documentos.</Alert>
          )}
        </div>
      )}

      <DataTable label="Evaluación de vendedores">
        <thead>
          <tr>
            {GROUPS.map(([title, span], i) => <th key={i} colSpan={span} className={`${th} ${title ? 'border-l border-line text-center' : ''}`}>{title}</th>)}
          </tr>
          <tr>
            <th className={`${th} text-right`}>#</th>
            <th className={th}>Vendedor</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Documentos</th>
            <th className={`${th} text-right`}>Clientes</th>
            <th className={`${th} border-l border-line text-right`}>Cumplimiento</th>
            <th className={`${th} border-l border-line text-right`}>Descuento</th>
            <th className={`${th} text-right`}>Bajo precio normal</th>
            <th className={`${th} text-right`}>Top 3 clientes</th>
            <th className={`${th} border-l border-line text-right`}>Clientes</th>
            <th className={`${th} text-right`}>Inactivos</th>
            <th className={`${th} text-right`}>Perdidos</th>
            <th className={`${th} border-l border-line text-right`}>Por cobrar</th>
            <th className={`${th} text-right`}>Vencido</th>
            <th className={`${th} border-l border-line text-right`}>Conversión cot.</th>
            <th className={`${th} text-right`}>Contactos</th>
            <th className={`${th} text-right`}>Pendientes vencidos</th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={17} />}
          {data && rows.length === 0 && <EmptyRow cols={17}>No hay vendedores con ventas ni actividad en el periodo.</EmptyRow>}
          {rows.map((r) => <Row key={r.seller.id} r={r} onOpen={() => setOpen(r)} />)}
        </tbody>
      </DataTable>

      <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted">
        <span className="inline-flex items-center gap-1"><CircleAlert className="h-3.5 w-3.5 text-danger" aria-hidden="true" />Crítico</span>
        <span>Los umbrales de cada semáforo son valores iniciales por omisión.</span>
        <span>Toda cifra de venta es neta (sin IVA). Haz clic en un vendedor para ver sus facturas.</span>
      </p>

      {open && <SellerBillingModal sellerId={open.seller.id} name={open.seller.name} query={filters.query()} onClose={() => setOpen(null)} />}
    </section>
  )
}
