import { useState } from 'react'
import { Alert, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, Th, td, th, tr, Stat } from '../components/ui'
import { CHANNEL_LABEL, type FollowUpKpis, type InteractionChannel, type SellerRow } from '../lib/customers'
import { formatMoney } from '../lib/format'
import { LOSS_REASON_LABEL, type LossReason } from '../lib/quotations'
import { formatPct, presetRange } from '../lib/sales'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const PRESETS = [['30d', 'Últimos 30 días'], ['month', 'Este mes'], ['last-month', 'Mes anterior'], ['90d', 'Últimos 90 días']] as const

function Group({ title, note, children }: { title: string; note?: string; children: React.ReactNode }) {
  return (
    <section className="mb-6">
      <h2 className="mb-1 text-sm font-semibold text-ink">{title}</h2>
      {note && <p className="mb-2 text-xs text-ink-muted">{note}</p>}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{children}</div>
    </section>
  )
}

const n = (v: number) => v.toLocaleString('en-US')

export default function FollowUpKpisPage() {
  const [range, setRange] = useState(() => presetRange('30d'))
  const [companyId, setCompanyId] = useState('')
  const [sellerId, setSellerId] = useState('')

  const companies = useLoad<{ data: Company[] }>('/companies')
  const sellers = useLoad<{ data: SellerRow[] }>(`/customers/sellers?company_id=${companyId}`, { enabled: companyId !== '' })
  const valid = range.from !== '' && range.to !== '' && range.from <= range.to
  const query = new URLSearchParams({ from: range.from, to: range.to })
  if (companyId) query.set('company_id', companyId)
  if (sellerId) query.set('seller_id', sellerId)
  const kpis = useLoad<{ data: FollowUpKpis }>(`/customers/follow-up-kpis?${query}`, { enabled: valid })
  const k = valid ? kpis.data?.data : undefined
  const lossRows = k ? Object.entries(k.quotations.loss_reasons) : []

  return (
    <section>
      <PageHeader title="Indicadores de seguimiento" description="Contactos, cumplimiento de acciones, cartera y conversión de cotizaciones del periodo." />

      <Card className="mb-4 p-4">
        <div className="mb-3 flex flex-wrap gap-1" role="group" aria-label="Periodos rápidos">
          {PRESETS.map(([key, label]) => (
            <button key={key} type="button" onClick={() => setRange(presetRange(key))} className="min-h-9 cursor-pointer rounded-lg px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-jge-blue-50 hover:text-jge-blue">
              {label}
            </button>
          ))}
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <TextField id="kpi-from" label="Desde" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
          <TextField id="kpi-to" label="Hasta" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
          <SelectField id="kpi-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setSellerId('') }}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="kpi-seller" label="Vendedor" value={sellerId} disabled={companyId === ''} hint={companyId === '' ? 'Elige una compañía para filtrar por vendedor.' : undefined} onChange={(e) => setSellerId(e.target.value)}>
            <option value="">Todos</option>
            {(sellers.data?.data ?? []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </SelectField>
        </div>
      </Card>

      {!valid && <Alert tone="warning">Elige un periodo válido: la fecha final no puede ser anterior a la inicial.</Alert>}
      <FormError message={valid ? kpis.error : null} />

      <div aria-busy={kpis.loading}>
        <Group title="Contactos" note="Contactos registrados con clientes en el periodo. Efectivo = se habló con el cliente (no cuenta «sin respuesta» ni «contacto incorrecto»).">
          <Stat label="Contactos" info={{ text: 'Contactos registrados con clientes en el periodo (llamadas, mensajes, visitas). Se asignan a quien los registró.' }} value={k ? n(k.contacts.total) : '—'} hint={k ? `${n(k.contacts.outbound)} iniciados por el vendedor · ${n(k.contacts.inbound)} por el cliente` : undefined} />
          <Stat label="Clientes contactados" info={{ text: 'Clientes distintos con al menos un contacto registrado en el periodo.' }} value={k ? n(k.contacts.customers) : '—'} />
          <Stat label="Contactos efectivos" info={{ text: 'Contactos en los que se habló con el cliente: hablaron, interesado, pidió cotización, pidió que lo llamen o no le interesa. No cuentan «sin respuesta» ni «número equivocado».', formula: 'Efectivos % = Contactos efectivos ÷ Contactos × 100' }} value={k ? n(k.contacts.effective) : '—'} hint={k ? formatPct(k.contacts.effective_pct) : undefined} />
          <Card className="p-4">
            <p className="text-sm text-ink-muted">Por canal</p>
            <ul className="mt-1 space-y-0.5 text-sm" aria-label="Contactos por canal">
              {k && Object.entries(k.contacts.by_channel).map(([c, v]) => <li key={c}>{CHANNEL_LABEL[c as InteractionChannel]}: <span className="tabular font-medium">{n(v ?? 0)}</span></li>)}
              {k && Object.keys(k.contacts.by_channel).length === 0 && <li className="text-ink-muted">Sin contactos.</li>}
            </ul>
          </Card>
        </Group>

        <Group title="Cumplimiento de próximas acciones" note="Acciones programadas dentro del periodo. A tiempo = cerrada a más tardar el día programado.">
          <Stat label="Acciones vencidas" info={{ text: 'Acciones de seguimiento cuya fecha programada ya pasó dentro del periodo: las cumplidas a tiempo, las cumplidas tarde y las que siguen sin cumplir.', formula: 'Vencidas = A tiempo + Tarde + Sin cumplir' }} value={k ? n(k.actions.due) : '—'} hint="Programadas con fecha ya pasada" />
          <Stat label="Cumplidas a tiempo" info={{ text: 'Parte de las acciones vencidas que se cumplieron a más tardar el día programado.', formula: 'A tiempo % = Cumplidas a tiempo ÷ Acciones vencidas × 100' }} value={k ? formatPct(k.actions.on_time_pct) : '—'} hint={k ? `${n(k.actions.on_time)} a tiempo · ${n(k.actions.late)} tarde` : undefined} />
          <Stat label="Cumplidas (a tiempo o tarde)" info={{ text: 'Parte de las acciones vencidas que se cumplieron, sea a tiempo o con retraso.', formula: 'Cumplidas % = (A tiempo + Tarde) ÷ Acciones vencidas × 100' }} value={k ? formatPct(k.actions.completed_pct) : '—'} />
          <Stat label="Sin cumplir" info={{ text: 'Acciones con fecha ya pasada que siguen abiertas.' }} value={k ? n(k.actions.open_overdue) : '—'} hint={k ? `${n(k.actions.upcoming)} todavía no vencen` : undefined} />
        </Group>

        <Group title="Cartera de clientes" note={k ? `Describe la cartera, no el efecto del seguimiento: no se atribuye ninguna venta a un contacto. «Recuperado» = compró tras más de ${k.customers.inactive_days} días sin comprar.` : undefined}>
          <Stat label="Clientes nuevos" info={{ text: 'Clientes cuya primera compra del historial cargado ocurre dentro del periodo.' }} value={k ? n(k.customers.new) : '—'} hint="Primera compra en el periodo (dentro del historial cargado)" />
          <Stat label="Clientes recuperados" info={{ text: 'Clientes que compran en el periodo después de haber pasado más días sin comprar que el límite de inactividad, sin ser clientes nuevos.' }} value={k ? n(k.customers.recovered) : '—'} />
        </Group>

        <Group title="Conversión de cotizaciones" note="Cohorte: cotizaciones emitidas en el periodo. Solo cuenta como convertida la que tiene factura vinculada; las abiertas todavía pueden convertirse, así que la tasa puede subir.">
          <Stat label="Cotizaciones emitidas" info={{ text: 'Cotizaciones emitidas en el periodo. El importe es la suma de sus montos.' }} value={k ? n(k.quotations.issued) : '—'} hint={k ? `Importe ${formatMoney(k.quotations.issued_amount)}` : undefined} />
          <Stat label="Convertidas en venta" info={{ text: 'Cotizaciones emitidas en el periodo que ya se vincularon con una factura.', formula: 'Conversión % = Convertidas ÷ Emitidas × 100' }} value={k ? n(k.quotations.converted) : '—'} hint={k ? `${formatPct(k.quotations.conversion_pct)} de las emitidas · ${formatMoney(k.quotations.converted_amount)}` : undefined} />
          <Stat label="Conversión por importe" info={{ text: 'Qué parte del dinero cotizado se convirtió en venta. Los días son el promedio entre la emisión y la conversión.', formula: 'Conversión por importe % = Importe convertido ÷ Importe emitido × 100' }} value={k ? formatPct(k.quotations.amount_conversion_pct) : '—'} hint={k && k.quotations.avg_days_to_convert !== null ? `Tardan ${String(k.quotations.avg_days_to_convert)} días en promedio` : undefined} />
          <Stat label="Abiertas" info={{ text: 'Cotizaciones del periodo que siguen vivas: emitidas, enviadas o en seguimiento, más las aceptadas que todavía no tienen factura.' }} value={k ? n(k.quotations.open + k.quotations.accepted_without_invoice) : '—'} hint={k ? `${n(k.quotations.open)} vigentes · ${n(k.quotations.accepted_without_invoice)} aceptadas sin factura` : undefined} />
          <Stat label="Rechazadas" info={{ text: 'Cotizaciones del periodo que el cliente rechazó.' }} value={k ? n(k.quotations.rejected) : '—'} />
          <Stat label="Vencidas" info={{ text: 'Cotizaciones del periodo que pasaron su fecha de validez sin cerrarse.' }} value={k ? n(k.quotations.expired) : '—'} />
          <Stat label="Canceladas" info={{ text: 'Cotizaciones del periodo que se cancelaron.' }} value={k ? n(k.quotations.cancelled) : '—'} />
          <Card className="p-4">
            <p className="text-sm text-ink-muted">Motivos de pérdida</p>
            <ul className="mt-1 space-y-0.5 text-sm" aria-label="Motivos de pérdida">
              {lossRows.map(([r, v]) => <li key={r}>{LOSS_REASON_LABEL[r as LossReason] ?? r}: <span className="tabular font-medium">{n(v ?? 0)}</span></li>)}
              {k && lossRows.length === 0 && <li className="text-ink-muted">Sin cotizaciones rechazadas.</li>}
            </ul>
          </Card>
        </Group>

        <h2 className="mb-2 text-sm font-semibold text-ink">Por usuario</h2>
        <DataTable label="Seguimiento por usuario">
          <thead>
            <tr>
              <th className={th}>Usuario</th><Th right info={{ text: 'Contactos registrados por el usuario en el periodo.' }}>Contactos</Th><Th right info={{ text: 'Clientes distintos que contactó el usuario.' }}>Clientes</Th><Th right info={{ text: 'Contactos del usuario en los que se habló con el cliente.' }}>Efectivos</Th>
              <Th right info={{ text: 'Acciones del usuario con fecha programada ya pasada dentro del periodo.' }}>Acciones vencidas</Th><Th right info={{ text: 'Parte de las acciones vencidas del usuario que cumplió, a tiempo o tarde.', formula: 'Cumplimiento % = (A tiempo + Tarde) ÷ Acciones vencidas × 100' }}>Cumplimiento</Th>
              <Th right info={{ text: 'Cotizaciones que emitió el usuario en el periodo.' }}>Cotizaciones</Th><Th right info={{ text: 'Cotizaciones del usuario convertidas en venta. Entre paréntesis, el porcentaje sobre las que emitió.', formula: 'Conversión % = Convertidas ÷ Emitidas × 100' }}>Convertidas</Th>
            </tr>
          </thead>
          <tbody>
            {kpis.loading && !k ? <TableSkeleton cols={8} /> : !k || k.by_user.length === 0 ? (
              <EmptyRow cols={8}>No hay actividad de seguimiento en este periodo.</EmptyRow>
            ) : k.by_user.map((u) => (
              <tr key={u.user.id} className={tr}>
                <td className={td}>{u.user.name}</td>
                <td className={`${td} tabular text-right`}>{n(u.contacts)}</td>
                <td className={`${td} tabular text-right`}>{n(u.customers)}</td>
                <td className={`${td} tabular text-right`}>{n(u.effective)}</td>
                <td className={`${td} tabular text-right`}>{n(u.actions_due)}</td>
                <td className={`${td} tabular text-right`}>{formatPct(u.compliance_pct)}</td>
                <td className={`${td} tabular text-right`}>{n(u.quotations_issued)}</td>
                <td className={`${td} tabular text-right`}>{n(u.quotations_converted)}{u.conversion_pct !== null ? ` (${formatPct(u.conversion_pct)})` : ''}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
        <p className="mt-2 text-xs text-ink-muted">Los contactos, las acciones y las cotizaciones se asignan a quien los registró.</p>
      </div>
    </section>
  )
}
