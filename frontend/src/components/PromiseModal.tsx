import { useState } from 'react'
import { ApiError, api } from '../lib/api'
import { FormActions, Modal, TextField, type FieldErrors } from './ui'
import { useToast } from './toast'

function localDay(offset = 0): string {
  const d = new Date()
  d.setDate(d.getDate() + offset)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Alta de una promesa de pago: monto y fecha que el cliente se comprometió a pagar. */
export default function PromiseModal({ companyId, code, name, balance, onClose, onSaved }: {
  companyId: number
  code: string
  name: string
  balance: string
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [form, setForm] = useState({ promised_on: localDay(7), amount: '', note: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await api('/credit/promises', { method: 'POST', body: { company_id: companyId, code, promised_on: form.promised_on, amount: form.amount.trim(), note: form.note.trim() || null } })
      toast.success('Promesa de pago registrada.')
      onSaved()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { amount: [e instanceof Error ? e.message : 'No se pudo registrar.'] })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Registrar promesa de pago" onClose={onClose}>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <p className="text-sm text-ink-muted">Cliente: <span className="font-medium text-ink">{name}</span> ({code}). Saldo por cobrar: <span className="tabular">{balance}</span></p>
        <TextField id="promise-date" label="Fecha prometida" type="date" min={localDay()} max={localDay(90)} value={form.promised_on} errors={errors.promised_on} onChange={(e) => setForm({ ...form, promised_on: e.target.value })} />
        <TextField id="promise-amount" label="Monto prometido" inputMode="decimal" value={form.amount} errors={errors.amount ?? errors.code} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
        <TextField id="promise-note" label="Nota (opcional)" value={form.note} maxLength={500} errors={errors.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
        <p className="text-xs text-ink-muted">Se cumple cuando los abonos registrados desde hoy hasta la fecha prometida suman el monto. Si pasa la fecha sin cumplirse queda como incumplida; no bloquea nada.</p>
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
