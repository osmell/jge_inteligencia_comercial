import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  type CompaniesSection, type CreditSection, type FollowupSection, type GoalsSection, type InsightsSection, type InventorySection, type QuotationsSection, type RankingsSection, type SalesSection,
  customerLink, dailyPoints, formatChange, type Profile,
} from '../lib/dashboard'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { formatCompact } from '../lib/sales'
import { goalTone } from '../lib/tone'
import { useLoad } from '../lib/useLoad'
import { BarChart, ChartData, DonutChart, InlineBar, LineChart, ProgressBar } from './charts'
import { Alert, Button, Card, DataTable, td, th, tr } from './ui'

/** Marco de una sección: título, enlace al detalle y estados de carga y error propios (una sección que falla no afecta a las demás). */
function Frame<T>({ title, detail, path, children }: { title: string; detail?: { to: string; label: string }; path: string; children: (data: T) => ReactNode }) {
  const result = useLoad<{ data: T }>(path)
  const id = `dash-${title.toLowerCase().replace(/\W+/g, '-')}`

  return (
    <section aria-labelledby={id} className="min-w-0">
      <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 id={id} className="text-sm font-semibold uppercase tracking-wide text-ink-muted">{title}</h2>
        {detail && <Link className="text-sm text-jge-blue hover:underline" to={detail.to}>{detail.label}</Link>}
      </div>
      {result.error && !result.data ? (
        <Alert tone="danger">
          No se pudo cargar esta sección: {result.error}{' '}
          <Button small variant="secondary" onClick={() => void result.reload()}>Reintentar</Button>
        </Alert>
      ) : !result.data ? (
        <Card className="p-4 text-sm text-ink-muted">Cargando…</Card>
      ) : (
        children(result.data.data)
      )}
    </section>
  )
}

function Stat({ label, value, hint, tone, to }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'bad' | 'good'; to?: string }) {
  const body = (
    <Card className={`h-full p-4 ${to ? 'transition-colors hover:border-jge-blue' : ''}`}>
      <p className="text-sm text-ink-muted">{label}</p>
      <p className={`tabular mt-0.5 text-2xl font-semibold tracking-tight ${tone === 'bad' ? 'text-danger' : tone === 'good' ? 'text-success' : ''}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
  return to ? <Link to={to} className="block">{body}</Link> : body
}

export interface BlockProps {
  query: string
  profile: Profile
}

/** Títulos en segunda persona para el vendedor; los demás perfiles usan el genérico. */
const own = (profile: Profile, mine: string, generic: string) => (profile === 'seller' ? mine : generic)

const changeText = (label: string, pct: number | null) => `${label}: ${formatChange(pct)}`

export function SalesBlock({ query, profile }: BlockProps) {
  return (
    <Frame<SalesSection> title={own(profile, 'Tus ventas', 'Ventas')} path={`/dashboard/sales?${query}`} detail={{ to: '/ventas', label: 'Ver ventas' }}>
      {(s) => {
        const now = dailyPoints(s.daily, s.period.from, s.period.to)
        const before = dailyPoints(s.daily_previous, s.previous.from, s.previous.to).slice(0, now.length)
        return (
          <>
            {s.current.documents === 0 && (
              <div className="mb-3"><Alert tone="info">No hay ventas en este periodo{s.history_since ? ` (el historial cargado empieza el ${formatDay(s.history_since)})` : ': todavía no se han cargado ventas'}. Las comparaciones se muestran contra lo que haya en el tramo anterior.</Alert></div>
            )}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Stat
                label="Venta neta"
                value={formatMoney(s.current.net_total)}
                tone={s.previous.change_pct === null ? undefined : s.previous.change_pct >= 0 ? 'good' : 'bad'}
                hint={<>{changeText('Contra el tramo anterior', s.previous.change_pct)}<br />{s.year_ago ? changeText('Contra el año anterior', s.year_ago.change_pct) : 'Comparación anual: no aplica a este periodo.'}</>}
              />
              <Stat label="Facturas" value={s.current.documents.toLocaleString('es')} hint={`Anterior: ${s.previous.documents.toLocaleString('es')}`} />
              <Stat label="Clientes con compra" value={s.current.customers.toLocaleString('es')} />
              <Stat label="Ticket promedio" value={formatMoney(s.current.average_ticket)} />
            </div>
            <div className="mt-3 grid gap-3 lg:grid-cols-2">
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-medium">Venta diaria: {s.period.label.toLowerCase()} contra el tramo anterior</h3>
                <LineChart label={`Venta neta diaria, ${s.period.label.toLowerCase()} contra el tramo anterior`} series={[{ name: s.period.label, points: now }, { name: 'Tramo anterior', points: before }]} />
                <ChartData>
                  <DataTable label="Venta diaria">
                    <thead><tr><th className={th}>Día</th><th className={`${th} text-right`}>Periodo</th><th className={`${th} text-right`}>Tramo anterior</th></tr></thead>
                    <tbody>{now.map((p, i) => <tr key={p.key} className={tr}><td className={td}>{p.key}</td><td className={`${td} tabular text-right`}>{formatMoney(p.value)}</td><td className={`${td} tabular text-right`}>{formatMoney(before[i]?.value ?? 0)}</td></tr>)}</tbody>
                  </DataTable>
                </ChartData>
              </Card>
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-medium">Venta mensual de los últimos 12 meses</h3>
                <BarChart label="Venta neta mensual, últimos 12 meses" points={s.monthly.map((m) => ({ key: m.month, value: Number(m.net_total) }))} />
                <ChartData>
                  <DataTable label="Venta mensual">
                    <thead><tr><th className={th}>Mes</th><th className={`${th} text-right`}>Venta neta</th><th className={`${th} text-right`}>Facturas</th></tr></thead>
                    <tbody>{s.monthly.map((m) => <tr key={m.month} className={tr}><td className={td}>{m.month}</td><td className={`${td} tabular text-right`}>{formatMoney(m.net_total)}</td><td className={`${td} tabular text-right`}>{m.documents}</td></tr>)}</tbody>
                  </DataTable>
                </ChartData>
              </Card>
            </div>
          </>
        )
      }}
    </Frame>
  )
}

export function GoalsBlock({ query, profile }: BlockProps) {
  const mine = profile === 'seller'
  return (
    <Frame<GoalsSection> title={own(profile, 'Tu meta del mes', 'Metas del mes')} path={`/dashboard/goals?${query}`} detail={{ to: '/metas', label: 'Ver metas' }}>
      {(g) => g.totals.sellers_with_goal === 0 ? (
        <Card className="p-4 text-sm text-ink-muted">{mine ? `Todavía no tienes una meta capturada para ${g.month}.` : `No hay metas capturadas para ${g.month}.`}</Card>
      ) : (
        <Card className="space-y-4 p-4">
          <ProgressBar label={mine ? `Tu avance ${g.month}` : `Cumplimiento ${g.month}`} value={g.totals.actual} target={g.totals.goal} marker={g.state === 'current' ? g.totals.projected : null} tone={goalTone(g.totals.compliance_pct)} />
          <p className="text-xs text-ink-muted">
            Falta {formatMoney(g.totals.remaining)}.
            {g.totals.needed_per_day !== null && ` Para llegar a la meta necesitas vender ${formatMoney(g.totals.needed_per_day)} por día en los ${g.totals.days_left} días que quedan del mes.`}
            {g.totals.projected !== null && ` Al ritmo actual ${mine ? 'cerrarías' : 'cerraría'} en ${formatMoney(g.totals.projected)} (${g.totals.projected_pct?.toLocaleString('es', { maximumFractionDigits: 1 })} % de la meta; la marca en la barra).`}
            {!mine && ` Vendedores que cumplen: ${g.totals.met} de ${g.totals.sellers_with_goal}.`}
          </p>
          {!mine && (
            <div className="space-y-3">
              {g.rows.filter((r) => r.effective_goal !== null).map((r) => (
                <ProgressBar key={r.seller_id} label={r.seller} value={r.actual} target={r.effective_goal ?? 0} marker={g.state === 'current' ? r.projected : null} tone={goalTone(r.compliance_pct)} />
              ))}
            </div>
          )}
        </Card>
      )}
    </Frame>
  )
}

export function CreditBlock({ query, profile }: BlockProps) {
  return (
    <Frame<CreditSection> title={own(profile, 'Tus clientes por cobrar', 'Cartera')} path={`/dashboard/credit?${query}`} detail={{ to: '/cartera', label: 'Ver cartera' }}>
      {(c) => (
        <Card className="space-y-4 p-4">
          <div className="grid gap-3 sm:grid-cols-3">
            <Stat label="Saldo por cobrar" value={formatMoney(c.total)} hint={`${c.invoices} facturas · ${c.customers} clientes`} />
            <Stat label="Saldo vencido" value={formatMoney(c.overdue)} tone={Number(c.overdue) > 0 ? 'bad' : 'good'} hint={c.overdue_pct === null ? undefined : `${c.overdue_pct.toLocaleString('es')} % del saldo`} />
            <Stat label="Promesas de pago" value={c.promises.due_next_7_days} hint={`Próximos 7 días (${formatMoney(c.promises.due_amount)}) · incumplidas en 30 días: ${c.promises.broken_30_days}`} />
          </div>
          {c.promises.attention.length > 0 && (
            <div>
              <h3 className="mb-1 text-sm font-medium">Promesas de pago por atender</h3>
              <ul className="space-y-1 text-sm" aria-label="Promesas de pago por atender">
                {c.promises.attention.map((p) => (
                  <li key={`${p.company_id}-${p.code}-${p.promised_on}`} className="flex flex-wrap justify-between gap-x-3">
                    <Link className="text-jge-blue hover:underline" to={customerLink(p.company_id, p.code)}>{p.name}</Link>
                    <span className={p.status === 'broken' ? 'text-danger' : 'text-ink-muted'}>{formatMoney(p.amount)} · {p.status === 'broken' ? `incumplida hace ${p.days_overdue} días` : `para el ${formatDay(p.promised_on)}`}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {Number(c.total) > 0 ? (
            <>
              <DonutChart label="Cartera por antigüedad" center={<span className="tabular">{formatCompact(Number(c.total))}</span>} slices={c.buckets.map((b) => ({ key: b.bucket, label: b.bucket === 'current' ? 'Al corriente' : `${b.bucket} días`, value: Number(b.amount) }))} />
              {c.top_debtors.length > 0 && (
                <ChartData summary="Ver principales deudores">
                  <DataTable label="Principales deudores">
                    <thead><tr><th className={th}>Cliente</th><th className={`${th} text-right`}>Saldo</th><th className={`${th} text-right`}>Días de la más antigua</th></tr></thead>
                    <tbody>{c.top_debtors.map((d) => <tr key={`${d.company_id}-${d.code}`} className={tr}><td className={td}>{d.name}</td><td className={`${td} tabular text-right`}>{formatMoney(d.total)}</td><td className={`${td} tabular text-right`}>{d.oldest_days}</td></tr>)}</tbody>
                  </DataTable>
                </ChartData>
              )}
            </>
          ) : <p className="text-sm text-ink-muted">No hay saldo por cobrar.</p>}
        </Card>
      )}
    </Frame>
  )
}

export function QuotationsBlock({ query, profile }: BlockProps) {
  return (
    <Frame<QuotationsSection> title={own(profile, 'Tus cotizaciones', 'Cotizaciones')} path={`/dashboard/quotations?${query}`} detail={{ to: '/cotizaciones', label: 'Ver cotizaciones' }}>
      {(q) => (
        <div className="grid gap-3 sm:grid-cols-2">
          <Stat label="Abiertas" value={q.open.count} hint={`Monto ${formatMoney(q.open.amount)}`} />
          <Stat label="Por vencer en 3 días" value={q.expiring.count} tone={q.expiring.count > 0 ? 'bad' : undefined} hint={`Monto ${formatMoney(q.expiring.amount)}`} />
          <Stat label="Vencidas sin cerrar" value={q.expired_unclosed} tone={q.expired_unclosed > 0 ? 'bad' : 'good'} />
          <Stat label="Emitidas / convertidas en el periodo" value={`${q.issued.count} / ${q.converted.count}`} hint={`Convertidas: ${formatMoney(q.converted.amount)}`} />
        </div>
      )}
    </Frame>
  )
}

export function FollowupBlock({ query, profile }: BlockProps) {
  return (
    <Frame<FollowupSection> title={own(profile, 'Tu agenda', 'Seguimiento')} path={`/dashboard/followup?${query}`} detail={{ to: '/agenda', label: 'Ver agenda' }}>
      {(f) => (
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-3">
            <Stat label="Acciones vencidas" value={f.overdue} tone={f.overdue > 0 ? 'bad' : 'good'} to="/agenda" />
            <Stat label="Acciones para hoy" value={f.today} to="/agenda" />
            <Stat label={`Sin contacto en ${f.no_contact.days} días`} value={f.no_contact.count} hint="Clientes inactivos o perdidos que aceptan comunicaciones" tone={f.no_contact.count > 0 ? 'bad' : 'good'} to="/agenda" />
          </div>
          {f.actions.length > 0 && (
            <Card className="p-4">
              <h3 className="mb-2 text-sm font-medium">{profile === 'seller' ? 'Lo que tienes pendiente' : 'Acciones pendientes'}</h3>
              <ul className="space-y-1 text-sm" aria-label="Acciones pendientes">
                {f.actions.map((a, i) => (
                  <li key={`${a.customer.code}-${i}`} className="flex flex-wrap justify-between gap-x-3">
                    <span><Link className="text-jge-blue hover:underline" to={customerLink(a.customer.company_id, a.customer.code)}>{a.customer.name}</Link> · {a.next_action}</span>
                    <span className={a.days_overdue > 0 ? 'text-danger' : 'text-ink-muted'}>{a.days_overdue > 0 ? `Vencida hace ${a.days_overdue} días` : 'Para hoy'}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
          {f.no_contact.top.length > 0 && (
            <Card className="p-4">
              <h3 className="mb-2 text-sm font-medium">Clientes por recuperar</h3>
              <ul className="space-y-1 text-sm" aria-label="Clientes sin contacto reciente">
                {f.no_contact.top.map((c) => (
                  <li key={`${c.company_id}-${c.code}`} className="flex flex-wrap justify-between gap-x-3">
                    <Link className="text-jge-blue hover:underline" to={customerLink(c.company_id, c.code)}>{c.name}</Link>
                    <span className="text-ink-muted">{c.status === 'lost' ? 'Perdido' : 'Inactivo'} · {c.days_since_last_purchase} días sin comprar{c.seller ? ` · ${c.seller}` : ''}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
      )}
    </Frame>
  )
}

export function InventoryBlock({ query }: BlockProps) {
  return (
    <Frame<InventorySection> title="Inventario" path={`/dashboard/inventory?${query}`} detail={{ to: '/inventario/rotacion', label: 'Ver rotación' }}>
      {(i) => (
        <div className="grid gap-3 sm:grid-cols-2">
          <Stat label="Agotados con demanda" value={i.out_of_stock} tone={i.out_of_stock > 0 ? 'bad' : 'good'} />
          <Stat label="Bajo el mínimo" value={i.below_min} tone={i.below_min > 0 ? 'bad' : 'good'} />
          <Stat label="Sin rotación" value={i.no_rotation} />
          <Stat label="Sobre el máximo" value={i.above_max} />
        </div>
      )}
    </Frame>
  )
}

export function InsightsBlock({ query, profile }: BlockProps) {
  return (
    <Frame<InsightsSection> title={own(profile, 'Recomendaciones de tus clientes', 'Recomendaciones')} path={`/dashboard/insights?${query}`} detail={{ to: '/inteligencia/alertas', label: 'Ver recomendaciones' }}>
      {(i) => (
        <div className="grid gap-3 sm:grid-cols-2">
          <Stat label="Abiertas" value={i.open} hint={`Altas ${i.by_severity.high} · medias ${i.by_severity.medium} · bajas ${i.by_severity.low}`} to="/inteligencia/alertas" />
          <Stat label="Graves" value={i.by_severity.high} tone={i.by_severity.high > 0 ? 'bad' : 'good'} to="/inteligencia/alertas" />
          <Stat label="Clientes en riesgo" value={i.at_risk} />
          <Stat label="Clientes con caída de venta" value={i.sales_drop} />
        </div>
      )}
    </Frame>
  )
}

function RankList({ title, rows }: { title: string; rows: { key: string; label: string; net_total: string }[] }) {
  const max = Math.max(...rows.map((r) => Number(r.net_total)), 0)
  return (
    <Card className="p-4">
      <h3 className="mb-2 text-sm font-medium">{title}</h3>
      {rows.length === 0 ? <p className="text-sm text-ink-muted">Sin ventas en el periodo.</p> : (
        <ol className="space-y-2" aria-label={title}>
          {rows.map((r) => (
            <li key={r.key}>
              <div className="flex justify-between gap-3 text-sm"><span className="truncate">{r.label}</span><span className="tabular shrink-0 text-ink-muted">{formatMoney(r.net_total)}</span></div>
              <InlineBar value={Number(r.net_total)} max={max} />
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}

export function RankingsBlock({ query, profile }: BlockProps) {
  return (
    <Frame<RankingsSection> title={own(profile, 'Tus principales del periodo', 'Principales del periodo')} path={`/dashboard/rankings?${query}`} detail={{ to: '/ventas', label: 'Ver ventas' }}>
      {(r) => (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <RankList title="Clientes" rows={r.customers} />
          <RankList title="Productos" rows={r.products} />
          {r.sellers && <RankList title="Vendedores" rows={r.sellers} />}
        </div>
      )}
    </Frame>
  )
}

export function CompaniesBlock({ query }: BlockProps) {
  return (
    <Frame<CompaniesSection> title="Por compañía" path={`/dashboard/companies?${query}`}>
      {(c) => (
        <DataTable label="Venta por compañía">
          <thead><tr><th className={th}>Compañía</th><th className={`${th} text-right`}>Venta neta</th><th className={`${th} text-right`}>Facturas</th><th className={`${th} text-right`}>Tramo anterior</th><th className={`${th} text-right`}>Cambio</th></tr></thead>
          <tbody>
            {c.rows.map((r) => (
              <tr key={r.company_id} className={tr}>
                <td className={td}>{r.company}</td><td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td><td className={`${td} tabular text-right`}>{r.documents}</td>
                <td className={`${td} tabular text-right`}>{formatMoney(r.previous_net_total)}</td><td className={`${td} tabular text-right`}>{formatChange(r.change_pct)}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      )}
    </Frame>
  )
}
