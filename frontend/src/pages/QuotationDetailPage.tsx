import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TextAreaField, TextField, td, th, tr } from '../components/ui'
import { api, download } from '../lib/api'
import { formatBytes, formatMoney } from '../lib/format'
import { formatDateTime } from '../lib/customers'
import {
  LOSS_REASON_LABEL, MAX_ATTACHMENT_MB, STATUS_LABEL, TRANSITION_LABEL, formatDay,
  type CandidateDocument, type LossReason, type QuotationDetail, type StoredStatus,
} from '../lib/quotations'
import { useLoad } from '../lib/useLoad'
import { useSubmit } from '../lib/useSubmit'

const profileLink = (q: QuotationDetail) => `/clientes/ficha?codigo=${encodeURIComponent(q.customer.code)}&compania=${q.customer.company_id}`

export default function QuotationDetailPage() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()
  const fileInput = useRef<HTMLInputElement>(null)
  const detail = useLoad<{ data: QuotationDetail }>(`/quotations/${id}`)
  const q = detail.data?.data
  const manage = can('interactions.manage')
  const closed = q ? ['converted', 'rejected', 'cancelled'].includes(q.status) : true

  const [error, setError] = useState<string | null>(null)
  const [running, setRunning] = useState(false)
  const [edit, setEdit] = useState<Record<string, string> | null>(null)
  const editForm = useSubmit()
  const [closing, setClosing] = useState<'rejected' | 'cancelled' | null>(null)
  const [loss, setLoss] = useState<{ reason: string; note: string }>({ reason: '', note: '' })
  const closeForm = useSubmit()
  const [linking, setLinking] = useState(false)
  const [documentId, setDocumentId] = useState('')
  const working = running || editForm.saving || closeForm.saving
  const candidates = useLoad<{ data: CandidateDocument[] }>(`/quotations/${id}/documents`, { enabled: linking })

  async function run(action: () => Promise<unknown>, success: string) {
    setRunning(true)
    setError(null)
    try {
      await action()
      toast.success(success)
      detail.reload()
      return true
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo completar la acción.')
      return false
    } finally {
      setRunning(false)
    }
  }

  function changeStatus(to: StoredStatus) {
    if (to === 'rejected' || to === 'cancelled') {
      setLoss({ reason: '', note: '' })
      closeForm.setErrors({})
      setClosing(to)
      return
    }
    void run(() => api(`/quotations/${id}/status`, { method: 'PUT', body: { status: to } }), 'Estado actualizado.')
  }

  async function confirmClose() {
    if (!closing) return
    const ok = await closeForm.run(async () => { await api(`/quotations/${id}/status`, { method: 'PUT', body: { status: closing, loss_reason: loss.reason || null, loss_note: loss.note.trim() || null } }) }, 'loss_reason')
    if (ok) {
      toast.success('Estado actualizado.')
      setClosing(null)
      detail.reload()
    }
  }

  function startEdit(c: QuotationDetail) {
    editForm.setErrors({})
    setEdit({ amount: c.amount, valid_until: c.valid_until ?? '', description: c.description ?? '', external_reference: c.external_reference ?? '' })
  }

  async function saveEdit() {
    if (!edit) return
    const ok = await editForm.run(async () => {
      await api(`/quotations/${id}`, {
        method: 'PUT',
        body: { amount: edit.amount === '' ? null : edit.amount.replace(',', '.'), valid_until: edit.valid_until || null, description: edit.description.trim() || null, external_reference: edit.external_reference.trim() || null },
      })
    }, 'amount')
    if (ok) {
      toast.success('Cotización actualizada.')
      setEdit(null)
      detail.reload()
    }
  }

  async function upload(file: File | undefined) {
    if (!file) return
    if (file.size > MAX_ATTACHMENT_MB * 1024 * 1024) {
      setError(`El archivo pesa ${formatBytes(file.size)}; el límite es de ${MAX_ATTACHMENT_MB} MB.`)
      return
    }
    const body = new FormData()
    body.append('file', file)
    await run(() => api(`/quotations/${id}/attachment`, { method: 'POST', body }), 'Adjunto guardado.')
    if (fileInput.current) fileInput.current.value = ''
  }

  async function downloadAttachment(name: string) {
    setError(null)
    try {
      await download(`/quotations/${id}/attachment`, name)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo descargar el adjunto.')
    }
  }

  if (detail.error && !q) return <Alert tone="danger">{detail.error} <Link className="underline" to="/cotizaciones">Volver al listado</Link></Alert>

  return (
    <section>
      <PageHeader
        title={q ? `Cotización ${q.number}` : 'Cotización'}
        description={q ? `${STATUS_LABEL[q.effective_status]} · ${q.customer.name}` : undefined}
        action={<Link className="text-sm text-jge-blue hover:underline" to="/cotizaciones">← Volver al listado</Link>}
      />
      <FormError message={error} />

      {q && (
        <>
          {q.effective_status === 'expired' && <div className="mb-3"><Alert tone="warning">Esta cotización venció el {q.valid_until ? formatDay(q.valid_until) : ''}. Si el cliente sigue interesado, amplía la fecha de vencimiento.</Alert></div>}
          {q.status === 'accepted' && <div className="mb-3"><Alert tone="info">Aceptada no es lo mismo que vendida: pasa a «Convertida en venta» cuando vinculas la factura.</Alert></div>}
          {q.customer.do_not_contact && !closed && <div className="mb-3"><Alert tone="warning">El cliente pidió no recibir comunicaciones: la cotización no se puede marcar como enviada.</Alert></div>}

          <Card className="mb-4 p-4">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
              <div><dt className="text-ink-muted">Cliente</dt><dd className="font-medium"><Link className="text-jge-blue hover:underline" to={profileLink(q)}>{q.customer.name}</Link></dd></div>
              <div><dt className="text-ink-muted">Importe</dt><dd className="tabular font-medium">{formatMoney(q.amount)}</dd></div>
              <div><dt className="text-ink-muted">Válida hasta</dt><dd className="font-medium">{q.valid_until ? formatDay(q.valid_until) : '—'}</dd></div>
              <div><dt className="text-ink-muted">Emitida</dt><dd className="font-medium">{q.issued_at ? formatDateTime(q.issued_at) : 'Aún no'}</dd></div>
              <div><dt className="text-ink-muted">Vendedor del cliente</dt><dd className="font-medium">{q.seller ?? '—'}</dd></div>
              <div><dt className="text-ink-muted">La registró</dt><dd className="font-medium">{q.user?.name ?? '—'}</dd></div>
              <div><dt className="text-ink-muted">Referencia externa</dt><dd className="font-medium">{q.external_reference ?? '—'}</dd></div>
              <div className="sm:col-span-2"><dt className="text-ink-muted">Descripción</dt><dd className="whitespace-pre-line font-medium">{q.description ?? '—'}</dd></div>
              <div><dt className="text-ink-muted">Factura vinculada</dt><dd className="font-medium">{q.document ? `${q.document.number} (${formatDay(q.document.issued_on)})` : '—'}</dd></div>
              {q.loss_reason && (
                <div className="sm:col-span-2 lg:col-span-3"><dt className="text-ink-muted">Motivo</dt><dd className="font-medium">{LOSS_REASON_LABEL[q.loss_reason]}{q.loss_note ? ` — ${q.loss_note}` : ''}</dd></div>
              )}
            </dl>

            {manage && !closed && edit === null && (
              <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
                <Button variant="secondary" small onClick={() => startEdit(q)}>Editar</Button>
                {q.transitions.filter((t) => t !== 'rejected' && t !== 'cancelled').map((t) => (
                  <Button key={t} small disabled={working} onClick={() => changeStatus(t)}>{TRANSITION_LABEL[t]}</Button>
                ))}
                {['issued', 'sent', 'following', 'accepted'].includes(q.status) && <Button small variant="secondary" disabled={working} onClick={() => setLinking(true)}>Vincular factura</Button>}
                {q.transitions.includes('rejected') && <Button small variant="secondary" disabled={working} onClick={() => changeStatus('rejected')}>{TRANSITION_LABEL.rejected}</Button>}
                {q.transitions.includes('cancelled') && <Button small variant="danger" disabled={working} onClick={() => changeStatus('cancelled')}>{TRANSITION_LABEL.cancelled}</Button>}
              </div>
            )}
            {can('customers.manage') && q.status === 'converted' && (
              <div className="mt-4 border-t border-line pt-3">
                <Button small variant="secondary" disabled={working} onClick={() => void run(() => api(`/quotations/${id}/link`, { method: 'DELETE' }), 'Vínculo retirado: la cotización vuelve a «Aceptada».')}>Quitar vínculo con la factura</Button>
              </div>
            )}

            {edit !== null && (
              <form onSubmit={(e) => { e.preventDefault(); void saveEdit() }} className="mt-4 space-y-3 border-t border-line pt-3">
                <div className="grid gap-3 sm:grid-cols-3">
                  <TextField id="e-amount" label="Importe" inputMode="decimal" value={edit.amount} errors={editForm.errors.amount} onChange={(e) => setEdit({ ...edit, amount: e.target.value })} />
                  <TextField id="e-valid" label="Válida hasta" type="date" value={edit.valid_until} errors={editForm.errors.valid_until} onChange={(e) => setEdit({ ...edit, valid_until: e.target.value })} />
                  <TextField id="e-ref" label="Referencia externa" value={edit.external_reference} errors={editForm.errors.external_reference} maxLength={100} onChange={(e) => setEdit({ ...edit, external_reference: e.target.value })} />
                </div>
                <TextAreaField id="e-desc" label="Descripción" value={edit.description} errors={editForm.errors.description} maxLength={2000} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
                <div className="flex gap-2">
                  <Button type="submit" loading={editForm.saving}>Guardar cambios</Button>
                  <Button type="button" variant="secondary" onClick={() => setEdit(null)}>Cancelar</Button>
                </div>
              </form>
            )}

            {closing !== null && (
              <form onSubmit={(e) => { e.preventDefault(); void confirmClose() }} className="mt-4 space-y-3 border-t border-line pt-3">
                <h3 className="text-sm font-semibold">{closing === 'rejected' ? 'Registrar la cotización como perdida' : 'Cancelar la cotización'}</h3>
                <div className="grid gap-3 sm:grid-cols-2">
                  <SelectField id="l-reason" label={closing === 'rejected' ? 'Motivo (obligatorio)' : 'Motivo (opcional)'} value={loss.reason} errors={closeForm.errors.loss_reason} onChange={(e) => setLoss({ ...loss, reason: e.target.value })}>
                    <option value="">Elige un motivo</option>
                    {(Object.keys(LOSS_REASON_LABEL) as LossReason[]).map((r) => <option key={r} value={r}>{LOSS_REASON_LABEL[r]}</option>)}
                  </SelectField>
                  <TextField id="l-note" label="Detalle (opcional)" value={loss.note} errors={closeForm.errors.loss_note} maxLength={500} onChange={(e) => setLoss({ ...loss, note: e.target.value })} />
                </div>
                <FormError message={closeForm.errors.status?.[0] ?? null} />
                <div className="flex gap-2">
                  <Button type="submit" variant={closing === 'cancelled' ? 'danger' : 'primary'} loading={closeForm.saving}>{closing === 'rejected' ? 'Marcar como perdida' : 'Cancelar cotización'}</Button>
                  <Button type="button" variant="secondary" onClick={() => setClosing(null)}>Volver</Button>
                </div>
              </form>
            )}

            {linking && (
              <form
                onSubmit={(e) => { e.preventDefault(); void run(() => api(`/quotations/${id}/link`, { method: 'PUT', body: { document_id: Number(documentId) } }), 'Factura vinculada: la cotización quedó convertida en venta.').then((ok) => { if (ok) { setLinking(false); setDocumentId('') } }) }}
                className="mt-4 space-y-3 border-t border-line pt-3"
              >
                <h3 className="text-sm font-semibold">Vincular la factura que respalda la venta</h3>
                <SelectField id="link-doc" label="Factura del cliente" value={documentId} onChange={(e) => setDocumentId(e.target.value)}
                  hint="Se ofrecen facturas de este cliente emitidas desde la cotización y que no estén vinculadas a otra.">
                  <option value="">{candidates.loading ? 'Cargando…' : (candidates.data?.data ?? []).length === 0 ? 'No hay facturas disponibles' : 'Elige una factura'}</option>
                  {(candidates.data?.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.document} · {formatDay(d.issued_on)} · {formatMoney(d.net_total)}</option>)}
                </SelectField>
                <div className="flex gap-2">
                  <Button type="submit" loading={running} disabled={documentId === ''}>Vincular</Button>
                  <Button type="button" variant="secondary" onClick={() => { setLinking(false); setDocumentId('') }}>Cancelar</Button>
                </div>
              </form>
            )}
          </Card>

          <Card className="mb-4 p-4">
            <h2 className="mb-2 text-sm font-semibold text-ink">Adjunto (PDF o foto)</h2>
            {q.attachment ? (
              <p className="text-sm">
                {q.attachment.name} <span className="text-ink-muted">({formatBytes(q.attachment.size)})</span>
              </p>
            ) : (
              <p className="text-sm text-ink-muted">Sin adjunto. Se admite PDF, JPG, PNG o WebP de hasta {MAX_ATTACHMENT_MB} MB.</p>
            )}
            <div className="mt-2 flex flex-wrap gap-2">
              {q.attachment && <Button small variant="secondary" onClick={() => void downloadAttachment(q.attachment?.name ?? 'cotizacion')}>Descargar</Button>}
              {manage && !closed && (
                <>
                  <input ref={fileInput} id="quotation-file" type="file" className="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" onChange={(e) => void upload(e.target.files?.[0])} />
                  <Button small variant="secondary" disabled={working} onClick={() => fileInput.current?.click()}>{q.attachment ? 'Reemplazar adjunto' : 'Subir adjunto'}</Button>
                  {q.attachment && <Button small variant="ghost" disabled={working} onClick={() => void run(() => api(`/quotations/${id}/attachment`, { method: 'DELETE' }), 'Adjunto eliminado.')}>Quitar adjunto</Button>}
                </>
              )}
            </div>
          </Card>

          <h2 className="mb-2 text-sm font-semibold text-ink">Historial de estados</h2>
          <DataTable label="Historial de la cotización">
            <thead><tr><th className={th}>Fecha</th><th className={th}>Cambio</th><th className={th}>Usuario</th><th className={th}>Nota</th></tr></thead>
            <tbody>
              {q.events.length === 0 ? <EmptyRow cols={4}>Sin movimientos.</EmptyRow> : q.events.map((e, i) => (
                <tr key={i} className={tr}>
                  <td className={td}>{formatDateTime(e.at)}</td>
                  <td className={td}>{e.from ? `${STATUS_LABEL[e.from]} → ` : 'Creada como '}{STATUS_LABEL[e.to]}</td>
                  <td className={td}>{e.user ?? '—'}</td>
                  <td className={td}>{e.note ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </>
      )}
    </section>
  )
}
