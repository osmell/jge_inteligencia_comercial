import { useState } from 'react'
import { ApiError, api } from '../lib/api'
import { UNITS, allocateOldestFirst, fromUnits, toUnits, type OpenInvoice } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'
import { FormActions, FormError, Modal, SelectField, TextAreaField, TextField, type FieldErrors } from './ui'
import { useToast } from './toast'

export type SettlementKind = 'payment' | 'credit_note'

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const METHODS = ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'depósito', 'otro']

/** Captura de un abono o de una nota de crédito de un cliente, repartida entre sus facturas con saldo. */
export default function CreditSettlementModal({ kind, companyId, code, name, firstInvoiceId, onClose, onSaved }: {
  kind: SettlementKind
  companyId: number
  code: string
  name: string
  firstInvoiceId: number
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const isNote = kind === 'credit_note'
  const open = useLoad<{ data: OpenInvoice[] }>(`/credit/open-invoices?${new URLSearchParams({ company_id: String(companyId), code })}`)
  const invoices = open.data?.data ?? []

  const [form, setForm] = useState({ occurred_on: today(), method: 'efectivo', reference: '', note: '', series: '', number: '', subtotal: '', tax_total: '', total: '', observations: '' })
  const [amounts, setAmounts] = useState<Record<number, string>>({})
  const [distribute, setDistribute] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [initialized, setInitialized] = useState(false)

  // Al abrir, la factura desde la que se llegó queda propuesta con su saldo.
  if (!initialized && open.data) {
    setInitialized(true)
    const first = invoices.find((i) => i.id === firstInvoiceId)
    if (first) setAmounts({ [first.id]: fromUnits(toUnits(first.balance)) })
  }

  const set = (key: keyof typeof form, value: string) => setForm({ ...form, [key]: value })
  const applied = Object.values(amounts).reduce((sum, v) => sum + toUnits(v), 0)
  const target = isNote ? toUnits(form.total) : 0
  const err = (key: string) => errors[key]

  async function save() {
    setSaving(true)
    setErrors({})
    const allocations = invoices.filter((i) => toUnits(amounts[i.id] ?? '') > 0).map((i) => ({ invoice_id: i.id, amount: fromUnits(toUnits(amounts[i.id])) }))
    const base = { company_id: companyId, code, occurred_on: form.occurred_on, allocations }
    try {
      if (isNote) {
        await api('/credit/credit-notes', { method: 'POST', body: { ...base, series: form.series.trim(), number: form.number.trim(), subtotal: form.subtotal, tax_total: form.tax_total, total: form.total, observations: form.observations.trim() || null } })
      } else {
        await api('/credit/payments', { method: 'POST', body: { ...base, method: form.method, reference: form.reference.trim() || null, note: form.note.trim() || null } })
      }
      toast.success(isNote ? 'Nota de crédito registrada.' : 'Abono registrado.')
      onSaved()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { allocations: [e instanceof Error ? e.message : 'No se pudo registrar.'] })
    } finally {
      setSaving(false)
    }
  }

  const allocationErrors = Object.entries(errors).filter(([k]) => k.startsWith('allocations')).flatMap(([, v]) => v)

  return (
    <Modal title={isNote ? 'Registrar nota de crédito' : 'Registrar abono'} onClose={onClose} wide>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <p className="text-sm text-ink-muted">Cliente: <span className="font-medium text-ink">{name}</span> ({code})</p>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <TextField id="set-date" label="Fecha" type="date" value={form.occurred_on} max={today()} errors={err('occurred_on')} onChange={(e) => set('occurred_on', e.target.value)} />
          {isNote ? (
            <>
              <TextField id="set-series" label="Serie" value={form.series} maxLength={50} errors={err('series')} onChange={(e) => set('series', e.target.value)} />
              <TextField id="set-number" label="Documento" value={form.number} maxLength={50} errors={err('number')} onChange={(e) => set('number', e.target.value)} />
            </>
          ) : (
            <>
              <SelectField id="set-method" label="Método" value={form.method} errors={err('method')} onChange={(e) => set('method', e.target.value)}>
                {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
              </SelectField>
              <TextField id="set-reference" label="Referencia" value={form.reference} maxLength={100} errors={err('reference')} onChange={(e) => set('reference', e.target.value)} />
            </>
          )}
        </div>

        {isNote ? (
          <>
            <div className="grid gap-3 sm:grid-cols-3">
              <TextField id="set-subtotal" label="Subtotal" inputMode="decimal" value={form.subtotal} errors={err('subtotal')} onChange={(e) => set('subtotal', e.target.value)} />
              <TextField id="set-tax" label="Impuesto" inputMode="decimal" value={form.tax_total} errors={err('tax_total')} onChange={(e) => set('tax_total', e.target.value)} />
              <TextField id="set-total" label="Total" inputMode="decimal" value={form.total} errors={err('total')} onChange={(e) => set('total', e.target.value)} />
            </div>
            <TextAreaField id="set-observations" label="Observaciones" value={form.observations} maxLength={500} errors={err('observations')} onChange={(e) => set('observations', e.target.value)} />
          </>
        ) : (
          <TextField id="set-note" label="Nota (opcional)" value={form.note} maxLength={500} errors={err('note')} onChange={(e) => set('note', e.target.value)} />
        )}

        <div className="flex flex-wrap items-end gap-2">
          <TextField id="set-distribute" label="Repartir importe" inputMode="decimal" value={distribute} hint="De la factura más antigua a la más reciente" onChange={(e) => setDistribute(e.target.value)} />
          <button type="button" className="mb-0.5 rounded-lg border border-line px-3 py-2 text-sm hover:bg-surface-muted" onClick={() => setAmounts(allocateOldestFirst(invoices, toUnits(isNote && !distribute ? form.total : distribute)))}>
            Aplicar a las más antiguas
          </button>
        </div>

        <FormError message={allocationErrors[0] ?? open.error} />

        <div className="max-h-64 overflow-auto rounded-lg border border-line">
          <table className="w-full text-sm" aria-label="Facturas con saldo del cliente">
            <thead><tr className="bg-surface-muted text-left text-xs text-ink-muted"><th className="px-3 py-2">Factura</th><th className="px-3 py-2">Fecha</th><th className="px-3 py-2 text-right">Saldo</th><th className="px-3 py-2 text-right">Aplicar</th></tr></thead>
            <tbody>
              {invoices.length === 0 && <tr><td colSpan={4} className="px-3 py-3 text-ink-muted">{open.loading ? 'Cargando…' : 'El cliente no tiene facturas con saldo.'}</td></tr>}
              {invoices.map((i, index) => (
                <tr key={i.id} className="border-t border-line">
                  <td className="px-3 py-2">{i.document}</td>
                  <td className="px-3 py-2">{formatDay(i.issued_on)}</td>
                  <td className="tabular px-3 py-2 text-right">{formatMoney(i.balance)}</td>
                  <td className="px-3 py-2 text-right">
                    <input aria-label={`Importe a aplicar a ${i.document}`} inputMode="decimal" className="tabular w-28 rounded border border-line bg-surface px-2 py-1 text-right" value={amounts[i.id] ?? ''} onChange={(e) => setAmounts({ ...amounts, [i.id]: e.target.value })} />
                    {errors[`allocations.${index}.amount`] && <div className="text-xs text-danger">{errors[`allocations.${index}.amount`][0]}</div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <p className="text-sm" aria-live="polite">
          Aplicado: <span className="tabular font-semibold">{formatMoney(applied / UNITS)}</span>
          {isNote && target > 0 && applied !== target && <span className="ml-2 text-danger">Debe ser igual al total de la nota ({formatMoney(target / UNITS)}).</span>}
        </p>
        <p className="text-xs text-ink-muted">No se acepta un importe mayor al saldo de la factura. Un registro equivocado no se edita: se anula y se vuelve a capturar.</p>
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
