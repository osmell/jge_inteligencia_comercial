import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api'
import { ADJUSTMENT_REASONS, KIND_LABEL, REASON_LABEL, RETURN_REASONS, type InventoryProductRow, type MovementKind, type ProductUnits } from '../lib/inventory'
import type { Branch, Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'
import { useToast } from './toast'
import { FormActions, FormError, Modal, SelectField, TextAreaField, TextField, type FieldErrors } from './ui'

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Captura de un ajuste, una devolución o un traslado. Las existencias nunca quedan en negativo (el servidor lo valida). */
export default function InventoryMovementModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const toast = useToast()
  const [kind, setKind] = useState<MovementKind>('adjustment_out')
  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [toBranchId, setToBranchId] = useState('')
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [productId, setProductId] = useState('')
  const [quantity, setQuantity] = useState('')
  const [unitId, setUnitId] = useState('')
  const [reason, setReason] = useState('shrinkage')
  const [unitCost, setUnitCost] = useState('')
  const [note, setNote] = useState('')
  const [date, setDate] = useState(today())
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const t = setTimeout(() => setTerm(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const productQuery = new URLSearchParams({ q: term })
  if (companyId) productQuery.set('company_id', companyId)
  const products = useLoad<{ data: InventoryProductRow[] }>(`/inventory/products?${productQuery}`, { enabled: companyId !== '' && term.length >= 2 })
  const detail = useLoad<{ data: ProductUnits }>(`/inventory/products/${productId}/units`, { enabled: productId !== '' })

  const isTransfer = kind === 'transfer'
  const reasons = kind === 'adjustment_in' || kind === 'adjustment_out' ? ADJUSTMENT_REASONS : RETURN_REASONS
  const inflow = kind === 'adjustment_in' || kind === 'return_in'
  const branchOptions = (branches.data?.data ?? []).filter((b) => b.company_id === Number(companyId))
  const d = productId !== '' ? detail.data?.data : undefined
  const unitOptions = d ? [...(d.base_unit ? [{ id: d.base_unit.id, name: `${d.base_unit.name} (unidad base)` }] : []), ...d.equivalences.map((e) => ({ id: e.unit_id, name: e.unit }))] : []

  function changeKind(next: MovementKind) {
    setKind(next)
    setReason(next === 'adjustment_in' || next === 'adjustment_out' ? 'shrinkage' : 'defective')
    setErrors({})
  }

  async function save() {
    setSaving(true)
    setErrors({})
    const common = { company_id: Number(companyId), product_id: Number(productId), quantity: quantity.trim(), unit_id: unitId ? Number(unitId) : null, occurred_on: date, note: note.trim() || null }
    try {
      if (isTransfer) await api('/inventory/transfers', { method: 'POST', body: { ...common, from_branch_id: Number(branchId), to_branch_id: Number(toBranchId) } })
      else await api('/inventory/movements', { method: 'POST', body: { ...common, type: kind, branch_id: Number(branchId), reason, unit_cost: inflow && unitCost.trim() ? unitCost.trim() : null } })
      toast.success(isTransfer ? 'Traslado registrado.' : 'Movimiento registrado.')
      onSaved()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { quantity: [e instanceof Error ? e.message : 'No se pudo registrar.'] })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title="Nuevo movimiento de inventario" onClose={onClose} wide>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <SelectField id="mv-kind" label="Tipo de movimiento" value={kind} errors={errors.type} onChange={(e) => changeKind(e.target.value as MovementKind)}>
          {(Object.keys(KIND_LABEL) as MovementKind[]).map((k) => <option key={k} value={k}>{KIND_LABEL[k]}</option>)}
        </SelectField>

        <div className="grid gap-3 sm:grid-cols-3">
          <SelectField id="mv-company" label="Compañía" value={companyId} errors={errors.company_id} onChange={(e) => { setCompanyId(e.target.value); setBranchId(''); setToBranchId(''); setProductId(''); setUnitId('') }}>
            <option value="">Elige…</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="mv-branch" label={isTransfer ? 'Sucursal de origen' : 'Sucursal'} value={branchId} errors={errors.branch_id ?? errors.from_branch_id} onChange={(e) => setBranchId(e.target.value)}>
            <option value="">Elige…</option>
            {branchOptions.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          {isTransfer && (
            <SelectField id="mv-to-branch" label="Sucursal de destino" value={toBranchId} errors={errors.to_branch_id} onChange={(e) => setToBranchId(e.target.value)}>
              <option value="">Elige…</option>
              {branchOptions.filter((b) => String(b.id) !== branchId).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </SelectField>
          )}
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <TextField id="mv-search" label="Buscar producto" placeholder="Código o nombre (mínimo 2 letras)" value={search} disabled={companyId === ''} hint={companyId === '' ? 'Elige primero la compañía.' : undefined} onChange={(e) => setSearch(e.target.value)} />
          <SelectField id="mv-product" label="Producto" value={productId} errors={errors.product_id} onChange={(e) => { setProductId(e.target.value); setUnitId('') }}>
            <option value="">{term.length < 2 ? 'Busca un producto…' : 'Elige…'}</option>
            {(products.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}{p.base_unit ? '' : ' (sin unidad base)'}</option>)}
          </SelectField>
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          <TextField id="mv-quantity" label="Cantidad" inputMode="decimal" value={quantity} errors={errors.quantity} onChange={(e) => setQuantity(e.target.value)} />
          <SelectField id="mv-unit" label="Unidad" value={unitId} disabled={unitOptions.length === 0} errors={errors.unit} onChange={(e) => setUnitId(e.target.value)}>
            <option value="">{unitOptions.length === 0 ? 'Elige un producto' : 'Unidad base'}</option>
            {unitOptions.slice(1).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </SelectField>
          <TextField id="mv-date" label="Fecha" type="date" value={date} max={today()} errors={errors.occurred_on} onChange={(e) => setDate(e.target.value)} />
        </div>

        {!isTransfer && (
          <div className="grid gap-3 sm:grid-cols-2">
            <SelectField id="mv-reason" label="Motivo" value={reason} errors={errors.reason} onChange={(e) => setReason(e.target.value)}>
              {reasons.map((r) => <option key={r} value={r}>{REASON_LABEL[r]}</option>)}
            </SelectField>
            {inflow && <TextField id="mv-cost" label="Costo unitario (opcional)" inputMode="decimal" hint="Sin costo se valora al costo promedio" value={unitCost} errors={errors.unit_cost} onChange={(e) => setUnitCost(e.target.value)} />}
          </div>
        )}
        <TextAreaField id="mv-note" label={reason === 'other' && !isTransfer ? 'Nota (obligatoria con «Otro»)' : 'Nota (opcional)'} value={note} maxLength={500} errors={errors.note} onChange={(e) => setNote(e.target.value)} />
        <FormError message={errors.movement?.[0] ?? null} />
        <p className="text-xs text-ink-muted">Las existencias no pueden quedar en negativo. El movimiento no se edita ni se borra: un error se corrige con una reversa. Queda registrado quién lo hizo y cuándo.</p>
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}
