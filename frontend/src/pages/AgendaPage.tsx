import { InfoTip } from '../components/InfoTip'
import type { Info } from '../lib/glossary'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, Card, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, td, th, tr, Th } from '../components/ui'
import { api } from '../lib/api'
import { PROMISE_LABEL, type PaymentPromiseRow } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { ACTIVITY_LABEL, CHANNEL_LABEL, formatDate, formatDateTime, sinceLabel, type Agenda, type AgendaAction, type ReactivateRow, type SellerRow } from '../lib/customers'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const HORIZONS = [[0, 'Solo lo vencido y lo de hoy'], [7, 'Próximos 7 días'], [15, 'Próximos 15 días'], [30, 'Próximos 30 días']] as const
const NO_CONTACT = [15, 30, 60, 90] as const

const profileLink = (companyId: number, code: string) => `/clientes/ficha?codigo=${encodeURIComponent(code)}&compania=${companyId}`

function Stat({ label, value, hint, tone, info }: { label: string; value: number; hint?: string; tone?: 'danger'; info?: Info }) {
  return (
    <Card className="p-4">
      <p className="flex items-center gap-1 text-sm text-ink-muted">{label}{info && <InfoTip info={info} label={label} />}</p>
      <p className={`tabular mt-0.5 text-2xl font-semibold tracking-tight ${tone === 'danger' && value > 0 ? 'text-danger' : ''}`}>{value.toLocaleString('en-US')}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

function ActionsTable({ title, rows, total, loading, onDone, overdue }: { title: string; rows: AgendaAction[]; total: number; loading: boolean; onDone: (id: number) => void; overdue?: boolean }) {
  return (
    <section>
      <h2 className="mb-2 text-sm font-semibold text-ink">{title}{total > rows.length ? ` (se muestran ${rows.length} de ${total})` : ''}</h2>
      <DataTable label={title}>
        <thead>
          <tr>
            <th className={th}>Cliente</th><Th info={{ text: 'Acción de seguimiento programada con el cliente.' }}>Próxima acción</Th><th className={th}>Fecha</th>
            <th className={th}>Vendedor</th><Th info={{ text: 'Usuario que registró el contacto o la acción.' }}>Lo registró</Th><th className={th}><span className="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          {loading ? <TableSkeleton cols={6} /> : rows.length === 0 ? (
            <EmptyRow cols={6}>{overdue ? 'No hay acciones vencidas.' : 'No hay acciones programadas en este plazo.'}</EmptyRow>
          ) : rows.map((a) => (
            <tr key={a.id} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={profileLink(a.customer.company_id, a.customer.code)}>{a.customer.name}</Link>
                <div className="text-xs text-ink-muted">{[a.customer.phone, a.customer.whatsapp && a.customer.whatsapp !== a.customer.phone ? `WhatsApp ${a.customer.whatsapp}` : null].filter(Boolean).join(' · ') || 'Sin teléfono'}</div>
              </td>
              <td className={td}>
                {a.next_action}
                <div className="text-xs text-ink-muted">Tras {CHANNEL_LABEL[a.origin.channel].toLowerCase()} del {formatDate(a.origin.occurred_at.slice(0, 10))}</div>
              </td>
              <td className={td}>
                {formatDateTime(a.next_action_at)}
                {a.days_overdue > 0 && <div className="text-xs font-medium text-danger">Vencida {sinceLabel(a.days_overdue)}</div>}
              </td>
              <td className={td}>{a.customer.seller ?? '—'}</td>
              <td className={td}>{a.user?.name ?? '—'}</td>
              <td className={td}><Button small variant="secondary" onClick={() => onDone(a.id)} aria-label={`Marcar cumplida: ${a.next_action} (${a.customer.name})`}>Marcar cumplida</Button></td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}

function PromisesTable({ rows, loading }: { rows: PaymentPromiseRow[]; loading: boolean }) {
  return (
    <section>
      <h2 className="mb-1 text-sm font-semibold text-ink">Promesas de pago</h2>
      <p className="mb-2 text-xs text-ink-muted">Pendientes dentro del plazo elegido e incumplidas en los últimos 30 días. Una promesa se cumple con los abonos registrados hasta su fecha.</p>
      <DataTable label="Promesas de pago">
        <thead>
          <tr><th className={th}>Cliente</th><Th right info={{ text: 'Monto que el cliente se comprometió a pagar.' }}>Prometido</Th><Th right info={{ text: 'Parte de lo prometido que ya se abonó.' }}>Abonado</Th><Th info={{ text: 'Fecha en que el cliente prometió pagar.' }}>Fecha prometida</Th><Th info={{ text: 'Situación de la promesa: abierta, cumplida o incumplida (venció sin pago completo).' }}>Estado</Th><th className={th}>Lo registró</th></tr>
        </thead>
        <tbody>
          {loading ? <TableSkeleton cols={6} /> : rows.length === 0 ? (
            <EmptyRow cols={6}>No hay promesas de pago por atender.</EmptyRow>
          ) : rows.map((p) => (
            <tr key={p.id} className={tr}>
              <td className={td}><Link className="font-medium text-jge-blue hover:underline" to={profileLink(p.company_id, p.customer.code)}>{p.customer.name}</Link></td>
              <td className={`${td} tabular text-right`}>{formatMoney(p.amount)}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(p.paid)}</td>
              <td className={td}>{formatDate(p.promised_on)}</td>
              <td className={td}>{PROMISE_LABEL[p.status]}{p.days_overdue > 0 && <div className="text-xs font-medium text-danger">Venció {sinceLabel(p.days_overdue)}</div>}</td>
              <td className={td}>{p.user ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}

function ReactivateTable({ rows, total, loading, days }: { rows: ReactivateRow[]; total: number; loading: boolean; days: number }) {
  return (
    <section>
      <h2 className="mb-1 text-sm font-semibold text-ink">Clientes por reactivar{total > rows.length ? ` (se muestran ${rows.length} de ${total})` : ''}</h2>
      <p className="mb-2 text-xs text-ink-muted">Inactivos o perdidos, sin contacto registrado en los últimos {days} días y que aceptan comunicaciones. Primero los inactivos (más fáciles de recuperar).</p>
      <DataTable label="Clientes por reactivar">
        <thead>
          <tr>
            <th className={th}>Cliente</th><Th info="customer_status">Estado</Th><Th info={{ text: 'Fecha de la última compra del cliente.' }}>Última compra</Th>
            <th className={th}>Vendedor</th><Th info={{ text: 'Fecha del último contacto registrado con el cliente.' }}>Último contacto</Th>
          </tr>
        </thead>
        <tbody>
          {loading ? <TableSkeleton cols={5} /> : rows.length === 0 ? (
            <EmptyRow cols={5}>No hay clientes por reactivar con estos filtros.</EmptyRow>
          ) : rows.map((r) => (
            <tr key={`${r.company_id}-${r.code}`} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={profileLink(r.company_id, r.code)}>{r.name}</Link>
                <div className="text-xs text-ink-muted">{[r.phone, r.whatsapp && r.whatsapp !== r.phone ? `WhatsApp ${r.whatsapp}` : null].filter(Boolean).join(' · ') || 'Sin teléfono'}</div>
              </td>
              <td className={td}>{ACTIVITY_LABEL[r.status]}</td>
              <td className={td}>{formatDate(r.last_purchase)}<div className="text-xs text-ink-muted">{sinceLabel(r.days_since_last_purchase)}</div></td>
              <td className={td}>{r.seller ?? '—'}</td>
              <td className={td}>{r.last_contact_at ? formatDateTime(r.last_contact_at) : 'Nunca'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}

export default function AgendaPage() {
  const toast = useToast()
  const { can } = useAuth()
  const [companyId, setCompanyId] = useState('')
  const [sellerId, setSellerId] = useState('')
  const [mine, setMine] = useState(false)
  const [horizon, setHorizon] = useState(7)
  const [noContact, setNoContact] = useState(30)
  const [error, setError] = useState<string | null>(null)

  const companies = useLoad<{ data: Company[] }>('/companies')
  const sellers = useLoad<{ data: SellerRow[] }>(`/customers/sellers?company_id=${companyId}`, { enabled: companyId !== '' })
  const query = new URLSearchParams({ horizon_days: String(horizon), no_contact_days: String(noContact) })
  if (companyId) query.set('company_id', companyId)
  if (sellerId) query.set('seller_id', sellerId)
  if (mine) query.set('mine', '1')
  const agenda = useLoad<Agenda>(`/customers/agenda?${query}`)
  const a = agenda.data
  const promiseQuery = new URLSearchParams({ status: 'pending', horizon_days: String(horizon) })
  if (companyId) promiseQuery.set('company_id', companyId)
  if (mine) promiseQuery.set('mine', '1')
  const promises = useLoad<{ data: PaymentPromiseRow[] }>(`/credit/promises?${promiseQuery}`, { enabled: can('credit.view') })

  async function done(id: number) {
    setError(null)
    try {
      await api(`/customers/interactions/${id}/complete`, { method: 'PUT', body: { done: true } })
      toast.success('Acción marcada como cumplida.')
      agenda.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo marcar la acción.')
    }
  }

  return (
    <section>
      <PageHeader title="Agenda de seguimiento" description="Tus próximas acciones con clientes y los clientes que conviene volver a contactar." />

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="agenda-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setSellerId('') }}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="agenda-seller" label="Vendedor" value={sellerId} disabled={companyId === ''} hint={companyId === '' ? 'Elige una compañía para filtrar por vendedor.' : undefined} onChange={(e) => setSellerId(e.target.value)}>
            <option value="">Todos</option>
            {(sellers.data?.data ?? []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </SelectField>
          <SelectField id="agenda-horizon" label="Plazo de las acciones" value={horizon} onChange={(e) => setHorizon(Number(e.target.value))}>
            {HORIZONS.map(([d, label]) => <option key={d} value={d}>{label}</option>)}
          </SelectField>
          <SelectField id="agenda-nocontact" label="Sin contacto desde hace" value={noContact} onChange={(e) => setNoContact(Number(e.target.value))}>
            {NO_CONTACT.map((d) => <option key={d} value={d}>{d} días</option>)}
          </SelectField>
        </div>
        <div className="mt-2"><CheckField label="Solo lo que yo registré" checked={mine} onChange={(e) => setMine(e.target.checked)} /></div>
      </Card>

      <FormError message={agenda.error ?? error} />
      {a?.meta.truncated && <div className="mb-3"><Alert tone="info">Hay más resultados de los que se muestran; usa los filtros para acotar la agenda.</Alert></div>}

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3" aria-busy={agenda.loading}>
        <Stat label="Acciones vencidas" info={{ text: 'Acciones de seguimiento programadas con fecha ya pasada y sin cumplir.' }} value={a?.meta.overdue_total ?? 0} tone="danger" hint="Pendientes con fecha pasada" />
        <Stat label="Acciones próximas" info={{ text: 'Acciones de seguimiento programadas dentro del horizonte elegido.' }} value={a?.meta.upcoming_total ?? 0} hint={horizon === 0 ? 'Hasta el final de hoy' : `En los próximos ${horizon} días`} />
        <Stat label="Clientes por reactivar" info={{ text: 'Clientes inactivos o perdidos que aceptan comunicaciones y llevan más días sin contacto que el límite elegido.' }} value={a?.meta.reactivate_total ?? 0} hint={`Sin contacto en ${noContact} días`} />
      </div>

      <div className="space-y-6">
        <ActionsTable title="Acciones vencidas" rows={a?.data.overdue ?? []} total={a?.meta.overdue_total ?? 0} loading={agenda.loading && !a} onDone={(id) => void done(id)} overdue />
        <ActionsTable title="Acciones próximas" rows={a?.data.upcoming ?? []} total={a?.meta.upcoming_total ?? 0} loading={agenda.loading && !a} onDone={(id) => void done(id)} />
        {can('credit.view') && <PromisesTable rows={promises.data?.data ?? []} loading={promises.loading && !promises.data} />}
        <ReactivateTable rows={a?.data.reactivate ?? []} total={a?.meta.reactivate_total ?? 0} loading={agenda.loading && !a} days={noContact} />
      </div>
    </section>
  )
}
