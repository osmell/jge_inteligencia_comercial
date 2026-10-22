import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, SelectField, TableSkeleton, TextAreaField, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import type { SellerRow } from '../lib/customers'
import { formatDateTime } from '../lib/format'
import { ALERT_LABEL, CLOSE_REASON_LABEL, SEVERITY_LABEL, type AlertType, type CloseReason, type InsightAlertList, type InsightAlertRow } from '../lib/insights'
import type { Company } from '../lib/types'
import { useFilters } from '../lib/useFilters'
import { useLoad } from '../lib/useLoad'
import { useSubmit } from '../lib/useSubmit'

const TYPES: AlertType[] = ['inactivity_risk', 'sales_drop', 'sales_rise', 'abandoned_line', 'cross_sell', 'rfm_at_risk']

/** Texto sugerido para la acción de seguimiento, según el tipo de recomendación. */
function suggestedAction(a: InsightAlertRow): string {
  switch (a.type) {
    case 'inactivity_risk': return 'Llamar para saber si necesita reposición'
    case 'sales_drop': return 'Revisar por qué bajó su compra y ofrecer apoyo'
    case 'sales_rise': return 'Agradecer y asegurar el abastecimiento'
    case 'abandoned_line': return `Preguntar por qué dejó de comprar ${String(a.facts.category ?? 'esa categoría')}`
    case 'cross_sell': return `Ofrecer ${String(a.facts.consequent ?? 'la categoría sugerida')}`
    case 'rfm_at_risk': return 'Llamar para retomar la relación'
  }
}

function localInput(daysAhead: number): string {
  const d = new Date(Date.now() + daysAhead * 86400000)
  d.setHours(9, 0, 0, 0)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

function CloseModal({ alert, onClose, onDone }: { alert: InsightAlertRow; onClose: () => void; onDone: () => void }) {
  const toast = useToast()
  const [reason, setReason] = useState<CloseReason>('contacted')
  const [note, setNote] = useState('')
  const { errors, saving, run } = useSubmit()

  async function save() {
    const ok = await run(async () => { await api(`/insights/alerts/${alert.id}/close`, { method: 'POST', body: { reason, note: note.trim() || null } }) }, 'reason')
    if (ok) {
      toast.success('Recomendación cerrada.')
      onDone()
    }
  }

  return (
    <Modal title="Cerrar recomendación" onClose={onClose}>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <p className="text-sm text-ink-muted">{alert.name}: {ALERT_LABEL[alert.type]}. Queda registrado quién la cerró y cuándo, y no se vuelve a abrir por el mismo hecho.</p>
        <SelectField id="close-reason" label="Motivo" value={reason} errors={errors.reason} onChange={(e) => setReason(e.target.value as CloseReason)}>
          {(Object.keys(CLOSE_REASON_LABEL) as CloseReason[]).map((r) => <option key={r} value={r}>{CLOSE_REASON_LABEL[r]}</option>)}
        </SelectField>
        <TextAreaField id="close-note" label={reason === 'other' ? 'Nota (obligatoria con «Otro»)' : 'Nota (opcional)'} value={note} maxLength={500} errors={errors.note} onChange={(e) => setNote(e.target.value)} />
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

function FollowUpModal({ alert, onClose, onDone }: { alert: InsightAlertRow; onClose: () => void; onDone: () => void }) {
  const toast = useToast()
  const [action, setAction] = useState(suggestedAction(alert))
  const [at, setAt] = useState(localInput(1))
  const { errors, saving, run } = useSubmit()

  async function save() {
    const ok = await run(async () => { await api(`/insights/alerts/${alert.id}/follow-up`, { method: 'POST', body: { next_action: action.trim(), next_action_at: at } }) }, 'next_action')
    if (ok) {
      toast.success('Acción agregada a la agenda de seguimiento.')
      onDone()
    }
  }

  return (
    <Modal title="Crear acción en la agenda" onClose={onClose}>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <p className="text-sm text-ink-muted">{alert.name}. Se agrega como próxima acción a la agenda de seguimiento; no cuenta como un contacto hasta que registres uno.</p>
        <TextField id="fu-action" label="Qué hacer" value={action} maxLength={500} errors={errors.next_action} onChange={(e) => setAction(e.target.value)} />
        <TextField id="fu-at" label="Cuándo" type="datetime-local" value={at} errors={errors.next_action_at} onChange={(e) => setAt(e.target.value)} />
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

/** Panel de recomendaciones: alertas y oportunidades de los clientes, cada una con la regla y los datos que la generaron. */
export default function InsightsAlertsPage() {
  const { can } = useAuth()
  const manage = can('interactions.manage')
  const { filters: f, page, set, setPage } = useFilters({ companyId: '', status: 'open' as 'open' | 'closed', type: '' as '' | AlertType, severity: '', sellerId: '', text: '' })
  const [dialog, setDialog] = useState<{ kind: 'close' | 'plan'; alert: InsightAlertRow } | null>(null)

  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = f.companyId || String(companies.data?.data[0]?.id ?? '')
  const sellers = useLoad<{ data: SellerRow[] }>(`/customers/sellers?company_id=${selected}`, { enabled: selected !== '' && can('customers.view') })
  const query = new URLSearchParams({ company_id: selected, page: String(page), status: f.status })
  if (f.type) query.set('type', f.type)
  if (f.severity) query.set('severity', f.severity)
  if (f.sellerId) query.set('seller_id', f.sellerId)
  if (f.text.trim()) query.set('q', f.text.trim())
  const alerts = useLoad<InsightAlertList>(`/insights/alerts?${query}`, { enabled: selected !== '' })
  const rows = alerts.data?.data ?? []
  const counts = alerts.data?.meta.counts
  const cols = manage && f.status === 'open' ? 5 : 4

  return (
    <section>
      <PageHeader
        title="Recomendaciones"
        description="Clientes que conviene revisar: se enfriaron respecto a su ritmo, cambiaron mucho su compra, dejaron una categoría o tienen una oportunidad de venta. Son sugerencias, no decisiones automáticas."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />
      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <SelectField id="al-company" label="Compañía" value={selected} onChange={(e) => set({ companyId: e.target.value, sellerId: '' })}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="al-status" label="Estado" value={f.status} onChange={(e) => set({ status: e.target.value as typeof f.status })}>
            <option value="open">Abiertas</option><option value="closed">Cerradas</option>
          </SelectField>
          <SelectField id="al-severity" label="Gravedad" value={f.severity} onChange={(e) => set({ severity: e.target.value })}>
            <option value="">Todas</option><option value="high">Alta</option><option value="medium">Media</option><option value="low">Baja</option>
          </SelectField>
          {can('customers.view') && (
            <SelectField id="al-seller" label="Vendedor" value={f.sellerId} onChange={(e) => set({ sellerId: e.target.value })}>
              <option value="">Todos</option>
              {(sellers.data?.data ?? []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </SelectField>
          )}
          <SearchInput label="Buscar cliente" value={f.text} onChange={(v) => set({ text: v })} placeholder="Nombre o código" />
        </div>
      </Card>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-6" aria-label="Recomendaciones por tipo">
        {TYPES.map((t) => (
          <button key={t} type="button" aria-pressed={f.type === t} onClick={() => set({ type: f.type === t ? '' : t })}
            className={`rounded-xl border p-3 text-left transition-colors ${f.type === t ? 'border-jge-blue bg-surface-muted' : 'border-line bg-surface hover:bg-surface-muted'}`}>
            <p className="text-xs text-ink-muted">{ALERT_LABEL[t]}</p>
            <p className="tabular mt-0.5 text-xl font-semibold">{counts ? counts[t].toLocaleString('es') : '—'}</p>
          </button>
        ))}
      </div>

      <FormError message={alerts.error} />
      {alerts.data && rows.length === 0 && f.status === 'open' && !f.type && !f.severity && !f.text && !f.sellerId && <div className="mb-3"><Alert tone="info">No hay recomendaciones abiertas. Se calculan cada noche con el historial de ventas; si una compañía no tiene historial suficiente, aparece como «Falta historial» en Inteligencia.</Alert></div>}

      <DataTable label="Recomendaciones">
        <thead><tr><th className={th}>Cliente</th><th className={th}>Recomendación</th><th className={th}>Gravedad</th><th className={th}>{f.status === 'open' ? 'Detectada' : 'Cierre'}</th>{cols === 5 && <th className={th}><span className="sr-only">Acciones</span></th>}</tr></thead>
        <tbody>
          {alerts.loading && !alerts.data ? <TableSkeleton cols={cols} /> : rows.length === 0 ? <EmptyRow cols={cols}>No hay recomendaciones con estos filtros.</EmptyRow> : rows.map((a) => (
            <tr key={a.id} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/clientes/ficha?codigo=${encodeURIComponent(a.code)}&compania=${selected}`}>{a.name}</Link>
                <div className="max-w-xl text-xs text-ink-muted">{a.explanation}</div>
              </td>
              <td className={td}>{ALERT_LABEL[a.type]}</td>
              <td className={td}><Badge>{SEVERITY_LABEL[a.severity]}</Badge></td>
              <td className={td}>
                {a.closed ? (
                  <>
                    {a.closed.reason ? CLOSE_REASON_LABEL[a.closed.reason] : 'Cerrada'}
                    {a.closed.note && <div className="text-xs text-ink-muted">{a.closed.note}</div>}
                    <div className="text-xs text-ink-muted">{a.closed.by ?? '—'} · {formatDateTime(a.closed.at)}</div>
                  </>
                ) : (
                  <>{formatDateTime(a.first_detected_at)}<div className="text-xs text-ink-muted">Vigente al {formatDateTime(a.last_detected_at)}</div></>
                )}
              </td>
              {cols === 5 && (
                <td className={td}>
                  <div className="flex flex-wrap gap-2">
                    {a.follow_up ? <span className="text-xs text-ink-muted">Acción en la agenda</span> : <Button small variant="secondary" onClick={() => setDialog({ kind: 'plan', alert: a })} aria-label={`Crear acción para ${a.name}: ${ALERT_LABEL[a.type]}`}>Crear acción</Button>}
                    <Button small variant="secondary" onClick={() => setDialog({ kind: 'close', alert: a })} aria-label={`Cerrar recomendación de ${a.name}: ${ALERT_LABEL[a.type]}`}>Cerrar</Button>
                  </div>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </DataTable>
      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!alerts.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>

      {dialog?.kind === 'close' && <CloseModal alert={dialog.alert} onClose={() => setDialog(null)} onDone={() => { setDialog(null); alerts.reload() }} />}
      {dialog?.kind === 'plan' && <FollowUpModal alert={dialog.alert} onClose={() => setDialog(null)} onDone={() => { setDialog(null); alerts.reload() }} />}
    </section>
  )
}
