import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { Alert, Badge, Button, Card, FormError, SelectField, TextAreaField, TextField, type FieldErrors } from './ui'
import { useToast } from './toast'
import { ApiError, api } from '../lib/api'
import {
  CHANNEL_LABEL, DIRECTION_LABEL, OUTCOME_LABEL, formatDateTime, toLocalInput,
  type ContactPreference, type CustomerInteraction, type InteractionChannel, type InteractionDirection, type InteractionOutcome,
} from '../lib/customers'
import { useLoad } from '../lib/useLoad'

interface Draft {
  channel: InteractionChannel
  direction: InteractionDirection
  outcome: InteractionOutcome
  occurred_at: string
  response: string
  next_action: string
  next_action_at: string
}

const emptyDraft = (): Draft => ({ channel: 'call', direction: 'outbound', outcome: 'spoke', occurred_at: toLocalInput(new Date()), response: '', next_action: '', next_action_at: '' })

const toDraft = (i: CustomerInteraction): Draft => ({
  channel: i.channel, direction: i.direction, outcome: i.outcome, occurred_at: toLocalInput(new Date(i.occurred_at)), response: i.response ?? '',
  next_action: i.next_action ?? '', next_action_at: i.next_action_at ? toLocalInput(new Date(i.next_action_at)) : '',
})

/** Seguimiento comercial de un cliente: historial de contactos, alta/corrección y preferencia «no contactar». */
export default function CustomerInteractions({ companyId, code }: { companyId: string; code: string }) {
  const { can } = useAuth()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [editing, setEditing] = useState<number | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [prefReason, setPrefReason] = useState<string | null>(null)
  const [prefError, setPrefError] = useState<FieldErrors>({})
  const [actionError, setActionError] = useState<string | null>(null)

  const base = new URLSearchParams({ company_id: companyId, code })
  const list = useLoad<{ data: CustomerInteraction[]; meta: { has_more: boolean; contact_preference: ContactPreference } }>(`/customers/interactions?${base}&page=${page}`)
  const pref = list.data?.meta.contact_preference
  const rows = list.data?.data ?? []
  const manage = can('interactions.manage')
  const blocked = pref?.do_not_contact === true

  function open(next: Draft, id: number | null) {
    setErrors({})
    setEditing(id)
    setDraft(next)
  }

  async function save() {
    if (!draft) return
    setSaving(true)
    setErrors({})
    const body = { ...draft, response: draft.response.trim() || null, next_action: draft.next_action.trim() || null, next_action_at: draft.next_action_at || null }
    try {
      if (editing === null) await api('/customers/interactions', { method: 'POST', body: { company_id: Number(companyId), code, ...body } })
      else await api(`/customers/interactions/${editing}`, { method: 'PUT', body })
      toast.success(editing === null ? 'Contacto registrado.' : 'Contacto corregido.')
      setDraft(null)
      setPage(1)
      list.reload()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { channel: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
    } finally {
      setSaving(false)
    }
  }

  async function complete(id: number) {
    setActionError(null)
    try {
      await api(`/customers/interactions/${id}/complete`, { method: 'PUT', body: { done: true } })
      toast.success('Acción marcada como cumplida.')
      list.reload()
    } catch (e) {
      setActionError(e instanceof Error ? e.message : 'No se pudo marcar la acción.')
    }
  }

  async function savePreference(on: boolean) {
    setPrefError({})
    try {
      await api('/customers/contact-preference', { method: 'PUT', body: { company_id: Number(companyId), code, do_not_contact: on, reason: on ? (prefReason ?? '').trim() : null } })
      toast.success(on ? 'Se registró que el cliente no desea ser contactado.' : 'Se retiró la restricción de contacto.')
      setPrefReason(null)
      list.reload()
    } catch (e) {
      setPrefError(e instanceof ApiError ? e.errors : { reason: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
    }
  }

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => draft && setDraft({ ...draft, [key]: value })

  return (
    <section aria-labelledby="interactions-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="interactions-title" className="text-sm font-semibold text-ink">Seguimiento comercial</h2>
        {manage && draft === null && !blocked && <Button small onClick={() => open(emptyDraft(), null)}>Registrar contacto</Button>}
        {manage && draft === null && blocked && <Button small variant="secondary" onClick={() => open({ ...emptyDraft(), direction: 'inbound' }, null)}>Registrar contacto del cliente</Button>}
      </div>

      <FormError message={list.error ?? actionError} />

      {blocked && pref && (
        <div className="mb-3">
          <Alert tone="warning">
            El cliente pidió no recibir comunicaciones{pref.reason ? `: ${pref.reason}` : '.'} Solo se registran los contactos que él inicia y no se programan próximas acciones.
          </Alert>
        </div>
      )}

      {manage && draft !== null && (
        <Card className="mb-3 p-4">
          <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
            <h3 className="text-sm font-semibold">{editing === null ? 'Nuevo contacto' : 'Corregir contacto'}</h3>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <SelectField id="int-channel" label="Canal" value={draft.channel} errors={errors.channel} onChange={(e) => set('channel', e.target.value as InteractionChannel)}>
                {Object.entries(CHANNEL_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </SelectField>
              <SelectField id="int-direction" label="Quién inició" value={draft.direction} errors={errors.direction} onChange={(e) => set('direction', e.target.value as InteractionDirection)}>
                {Object.entries(DIRECTION_LABEL).map(([k, v]) => <option key={k} value={k} disabled={blocked && k === 'outbound'}>{v}</option>)}
              </SelectField>
              <SelectField id="int-outcome" label="Resultado" value={draft.outcome} errors={errors.outcome} onChange={(e) => set('outcome', e.target.value as InteractionOutcome)}>
                {Object.entries(OUTCOME_LABEL).filter(([k]) => k !== 'planned').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </SelectField>
              <TextField id="int-occurred" label="Fecha y hora" type="datetime-local" value={draft.occurred_at} errors={errors.occurred_at} onChange={(e) => set('occurred_at', e.target.value)} />
            </div>
            <TextAreaField id="int-response" label="Respuesta del cliente / notas" value={draft.response} errors={errors.response} maxLength={2000} onChange={(e) => set('response', e.target.value)} />
            {!blocked && (
              <div className="grid gap-3 sm:grid-cols-2">
                <TextField id="int-next" label="Próxima acción" value={draft.next_action} errors={errors.next_action} maxLength={500} onChange={(e) => set('next_action', e.target.value)} />
                <TextField id="int-next-at" label="Fecha de la próxima acción" type="datetime-local" value={draft.next_action_at} errors={errors.next_action_at} onChange={(e) => set('next_action_at', e.target.value)} />
              </div>
            )}
            <p className="text-xs text-ink-muted">Registrar un contacto no genera ni atribuye ninguna venta: una venta solo existe cuando hay factura.</p>
            <div className="flex gap-2">
              <Button type="submit" loading={saving}>Guardar</Button>
              <Button type="button" variant="secondary" onClick={() => setDraft(null)}>Cancelar</Button>
            </div>
          </form>
        </Card>
      )}

      {list.loading && rows.length === 0 ? (
        <div className="skeleton h-20 w-full" aria-hidden="true" />
      ) : rows.length === 0 ? (
        <p className="rounded-xl border border-line bg-surface p-4 text-sm text-ink-muted">Aún no hay contactos registrados con este cliente.</p>
      ) : (
        <ul className="space-y-2" aria-label="Contactos con el cliente" aria-busy={list.loading}>
          {rows.map((i) => (
            <li key={i.id} className="rounded-xl border border-line bg-surface p-3 text-sm">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="font-medium">{CHANNEL_LABEL[i.channel]}</span>
                <Badge>{OUTCOME_LABEL[i.outcome]}</Badge>
                <span className="text-xs text-ink-muted">{i.direction === 'inbound' ? 'Lo inició el cliente' : 'Lo inició el vendedor'}</span>
                <span className="ml-auto text-xs text-ink-muted">{formatDateTime(i.occurred_at)} · {i.user?.name ?? 'Usuario eliminado'}</span>
              </div>
              {i.response && <p className="mt-1 whitespace-pre-line">{i.response}</p>}
              {i.next_action && (
                <p className="mt-1 text-ink-muted">
                  Próxima acción: <span className="text-ink">{i.next_action}</span>{i.next_action_at ? ` — ${formatDateTime(i.next_action_at)}` : ''}
                  {i.next_action_done_at && <span className="ml-2 font-medium text-success">Cumplida el {formatDateTime(i.next_action_done_at)}</span>}
                </p>
              )}
              {manage && i.next_action && i.next_action_at && !i.next_action_done_at && (
                <div className="mt-1"><Button small variant="secondary" onClick={() => void complete(i.id)} aria-label={`Marcar cumplida: ${i.next_action}`}>Marcar cumplida</Button></div>
              )}
              {manage && i.can_edit && draft === null && (
                <div className="mt-1"><Button small variant="ghost" onClick={() => open(toDraft(i), i.id)} aria-label={`Corregir contacto del ${formatDateTime(i.occurred_at)}`}>Corregir</Button></div>
              )}
            </li>
          ))}
        </ul>
      )}

      {(page > 1 || list.data?.meta.has_more) && (
        <div className="mt-2 flex items-center gap-2">
          <Button small variant="secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</Button>
          <span className="text-sm text-ink-muted">Página {page}</span>
          <Button small variant="secondary" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</Button>
        </div>
      )}

      {manage && pref && (
        <div className="mt-3 border-t border-line pt-3">
          {prefReason === null ? (
            blocked ? (
              <Button small variant="secondary" onClick={() => void savePreference(false)}>El cliente volvió a aceptar comunicaciones</Button>
            ) : (
              <Button small variant="secondary" onClick={() => setPrefReason('')}>El cliente pidió no ser contactado</Button>
            )
          ) : (
            <form onSubmit={(e) => { e.preventDefault(); void savePreference(true) }} className="max-w-xl space-y-2">
              <TextField id="pref-reason" label="Motivo o cómo lo pidió el cliente" value={prefReason} errors={prefError.reason} maxLength={500} onChange={(e) => setPrefReason(e.target.value)} />
              <div className="flex gap-2">
                <Button type="submit" disabled={prefReason.trim() === ''}>Registrar</Button>
                <Button type="button" variant="secondary" onClick={() => setPrefReason(null)}>Cancelar</Button>
              </div>
            </form>
          )}
        </div>
      )}
    </section>
  )
}
