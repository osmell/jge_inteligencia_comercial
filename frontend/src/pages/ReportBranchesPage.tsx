import { useState } from 'react'
import BillingTable from '../components/reports/BillingTable'
import BranchMixTable from '../components/reports/BranchMixTable'
import LevelValue from '../components/reports/LevelValue'
import { PeriodLine, ReconciliationNote, ReportLegend, ReportNotes, ReportStat } from '../components/reports/ReportParts'
import ReportActions from '../components/reports/ReportActions'
import ReportFilters from '../components/reports/ReportFilters'
import { Alert, DataTable, EmptyRow, FormError, Modal, PageHeader, TableSkeleton, td, th, tr } from '../components/ui'
import { formatMoney } from '../lib/format'
import type { BranchMix, BranchReport, BranchReportRow } from '../lib/reports'
import { formatPct } from '../lib/sales'
import { useLoad } from '../lib/useLoad'
import { useReportFilters } from '../lib/useReportFilters'

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('es'))
const signed = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${formatPct(v)}`)

const GROUPS: [string, number][] = [['', 2], ['Resultado', 4], ['Precio', 2], ['Dependencia', 2], ['Cartera de clientes (hoy)', 2], ['Cobranza (hoy)', 2], ['Existencias (hoy)', 4]]

function Row({ r, onOpen }: { r: BranchReportRow; onOpen: () => void }) {
  const s = r.semaphores
  const inv = r.inventory
  return (
    <tr className={tr}>
      <td className={`${td} tabular text-right text-ink-muted`}>{r.rank ?? '—'}</td>
      <td className={`${td} font-medium`}>
        <button type="button" className="cursor-pointer text-left text-jge-blue hover:underline" onClick={onOpen} aria-label={`Ver la facturación de ${r.branch.name}`}>{r.branch.name}</button>
        <span className="block text-xs font-normal text-ink-muted">{r.branch.company}{r.branch.is_active ? '' : ' · inactiva'}</span>
      </td>
      <td className={`${td} text-right`}>
        <span className="tabular font-medium">{formatMoney(r.sales.net_total)}</span>
        <span className="block text-xs"><LevelValue level={s.growth_pct}>{signed(r.sales.growth_pct)}</LevelValue></span>
      </td>
      <td className={`${td} tabular text-right`}>{n(r.sales.documents)}<span className="block text-xs text-ink-muted">{signed(r.sales.documents_growth_pct)}</span></td>
      <td className={`${td} tabular text-right`}>{formatMoney(r.sales.average_ticket)}</td>
      <td className={`${td} tabular text-right`}>{n(r.sales.customers)}</td>
      <td className={`${td} border-l border-line text-right`}><LevelValue level={s.discount_pct}>{formatPct(r.sales.discount_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.below_list_pct}>{formatPct(r.sales.below_list_pct)}</LevelValue></td>
      <td className={`${td} border-l border-line text-right`}>
        <LevelValue level={s.top_seller_dependency_pct}>{formatPct(r.dependency.top_seller_pct)}</LevelValue>
        <span className="block text-xs text-ink-muted">{r.dependency.top_seller ?? 'sin vendedores'} · {r.dependency.sellers_count} vend.</span>
      </td>
      <td className={`${td} text-right`}><LevelValue level={s.top3_concentration_pct}>{formatPct(r.sales.top3_concentration_pct)}</LevelValue><span className="block text-xs text-ink-muted">top 3 clientes</span></td>
      <td className={`${td} tabular border-l border-line text-right`}>{n(r.portfolio.total)}<span className="block text-xs text-ink-muted">{r.portfolio.new} nuevos · {r.portfolio.inactive} inactivos</span></td>
      <td className={`${td} text-right`}><LevelValue level={s.lost_customers_pct}>{n(r.portfolio.lost)} · {formatPct(r.portfolio.lost_pct)}</LevelValue></td>
      <td className={`${td} tabular border-l border-line text-right`}>{formatMoney(r.credit.balance)}</td>
      <td className={`${td} text-right`}><LevelValue level={s.overdue_pct}>{formatPct(r.credit.overdue_pct)}</LevelValue></td>
      <td className={`${td} tabular border-l border-line text-right`}>{n(inv.products_in_stock)}<span className="block text-xs text-ink-muted">productos con existencia</span></td>
      <td className={`${td} text-right`}><LevelValue level={s.no_rotation_pct}>{n(inv.no_rotation)} · {formatPct(inv.no_rotation_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.stockout_demand_pct}>{n(inv.stockout_with_demand)} · {formatPct(inv.stockout_demand_pct)}</LevelValue></td>
      <td className={`${td} text-right`}><LevelValue level={s.open_shortfalls}>{n(inv.open_shortfalls)}</LevelValue></td>
    </tr>
  )
}

export default function ReportBranchesPage() {
  const filters = useReportFilters()
  const [open, setOpen] = useState<BranchReportRow | null>(null)
  const report = useLoad<{ data: BranchReport }>(`/reports/branches?${filters.query()}`, { enabled: filters.valid })
  const mix = useLoad<{ data: BranchMix }>(`/reports/branches/mix?${filters.query()}`, { enabled: filters.valid })
  const data = report.data?.data
  const rows = data?.rows ?? []
  const t = data?.totals

  return (
    <section>
      <PageHeader title="Reporte de sucursales" description="Resultado, dependencia de personas y clientes, cartera, cobranza, existencias y mezcla de categorías de cada sucursal, con semáforo por indicador." />
      <ReportFilters filters={filters} />
      <ReportActions report="branches" filters={filters} />
      <FormError message={filters.contextError ?? report.error ?? mix.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido (y, si comparas con otro periodo, sus dos fechas): la fecha inicial no puede ser posterior a la final.</Alert></div>}
      {data && <PeriodLine data={data} />}
      {data && <ReportNotes notes={data.notes} />}

      {t && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <ReportStat label="Venta neta" value={formatMoney(t.net_total)} hint={t.growth_pct === null ? 'sin periodo anterior' : `${signed(t.growth_pct)} vs. comparación`} />
          <ReportStat label="Documentos" value={n(t.documents)} hint={`ticket ${formatMoney(t.average_ticket)}`} />
          <ReportStat label="Saldo vencido" value={formatMoney(t.credit_overdue)} hint={`de ${formatMoney(t.credit_balance)} por cobrar`} />
          <ReportStat label="Existencias" value={`${n(t.no_rotation)} sin rotación`} hint={`${n(t.stockout_with_demand)} agotados con demanda · ${n(t.open_shortfalls)} faltantes abiertos`} />
          <ReportStat label="Indicadores críticos" value={n(t.red_flags)} hint="suma de rojos entre todas las sucursales" />
        </div>
      )}

      {data && <ReconciliationNote rec={data.reconciliation} who="las sucursales" />}

      <DataTable scroll label="Evaluación de sucursales">
        <thead>
          <tr>
            {GROUPS.map(([title, span], i) => <th key={i} colSpan={span} className={`${th} ${title ? 'border-l border-line text-center' : ''}`}>{title}</th>)}
          </tr>
          <tr>
            <th className={`${th} text-right`}>#</th>
            <th className={th}>Sucursal</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Documentos</th>
            <th className={`${th} text-right`}>Ticket</th>
            <th className={`${th} text-right`}>Clientes</th>
            <th className={`${th} border-l border-line text-right`}>Descuento</th>
            <th className={`${th} text-right`}>Bajo precio normal</th>
            <th className={`${th} border-l border-line text-right`}>Vendedor principal</th>
            <th className={`${th} text-right`}>Clientes principales</th>
            <th className={`${th} border-l border-line text-right`}>Clientes</th>
            <th className={`${th} text-right`}>Perdidos</th>
            <th className={`${th} border-l border-line text-right`}>Por cobrar</th>
            <th className={`${th} text-right`}>Vencido</th>
            <th className={`${th} border-l border-line text-right`}>Con existencia</th>
            <th className={`${th} text-right`}>Sin rotación</th>
            <th className={`${th} text-right`}>Agotados con demanda</th>
            <th className={`${th} text-right`}>Faltantes abiertos</th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={18} />}
          {data && rows.length === 0 && <EmptyRow cols={18}>No hay sucursales con ventas ni actividad en el periodo.</EmptyRow>}
          {rows.map((r) => <Row key={r.branch.id} r={r} onOpen={() => setOpen(r)} />)}
        </tbody>
      </DataTable>

      <ReportLegend>
        <span>«Vendedor principal»: parte de la venta de la sucursal que aporta el vendedor que más vende; no cuenta al vendedor «Tienda …» de mostrador. Haz clic en una sucursal para ver sus facturas.</span>
      </ReportLegend>

      {mix.data && mix.data.data.categories.length > 0 && <BranchMixTable mix={mix.data.data} />}

      {open && (
        <Modal title={`Facturación de ${open.branch.name}`} onClose={() => setOpen(null)} wide>
          <BillingTable path={`/reports/branches/${open.branch.id}/documents`} query={filters.query()} label={`Documentos facturados en ${open.branch.name}`} />
        </Modal>
      )}
    </section>
  )
}
