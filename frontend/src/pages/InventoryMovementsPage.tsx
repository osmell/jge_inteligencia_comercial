import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import InventoryMovementModal from '../components/InventoryMovementModal'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SelectField, TableSkeleton, TextAreaField, td, th, tr, Th } from '../components/ui'
import { ApiError, api } from '../lib/api'
import { formatMoney } from '../lib/format'
import { MOVEMENT_TYPE_LABEL, REASON_LABEL, type InventoryMovementType, type MovementList, type MovementRow } from '../lib/inventory'
import { formatDay } from '../lib/quotations'
import type { Branch } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const num = (v: string) => Number(v).toLocaleString('es', { maximumFractionDigits: 8 })
const TYPES: InventoryMovementType[] = ['entry', 'adjustment_in', 'adjustment_out', 'return_in', 'supplier_return', 'transfer_in', 'transfer_out', 'reversal']

/** Ajustes, devoluciones, traslados y entradas: consulta, captura y reversas. */
export default function InventoryMovementsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const manage = can('inventory.manage')
  const [branchId, setBranchId] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [reversing, setReversing] = useState<MovementRow | null>(null)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const branches = useLoad<{ data: Branch[] }>('/branches')
  const query = new URLSearchParams({ page: String(page) })
  if (branchId) query.set('branch_id', branchId)
  if (type) query.set('type', type)
  const list = useLoad<MovementList>(`/inventory/movements?${query}`)
  const rows = list.data?.data ?? []
  const costs = list.data?.meta.costs_visible === true
  const cols = costs ? 9 : 8

  async function reverse() {
    if (!reversing) return
    setSaving(true)
    setError(null)
    try {
      await api(`/inventory/movements/${reversing.id}/reverse`, { method: 'POST', body: { note: note.trim() } })
      toast.success('Movimiento revertido.')
      setReversing(null)
      setNote('')
      list.reload()
    } catch (e) {
      setError(e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : e instanceof Error ? e.message : 'No se pudo revertir.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Movimientos de inventario"
        description="Ajustes, devoluciones, traslados y entradas, con su motivo y quién los registró."
        action={(
          <div className="flex flex-wrap items-center gap-2">
            {manage && <Button onClick={() => setCreating(true)}>Nuevo movimiento</Button>}
            <Link className="text-sm text-jge-blue hover:underline" to="/inventario">← Existencias</Link>
          </div>
        )}
      />
      <div className="mb-4"><Alert tone="info">Los movimientos no se editan ni se borran: un error se corrige con una reversa. Un traslado se revierte con su pareja y un documento de entrada se revierte completo.</Alert></div>

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="mv-f-branch" label="Sucursal" value={branchId} onChange={(e) => { setBranchId(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {(branches.data?.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          <SelectField id="mv-f-type" label="Tipo" value={type} onChange={(e) => { setType(e.target.value); setPage(1) }}>
            <option value="">Todos</option>
            {TYPES.map((t) => <option key={t} value={t}>{MOVEMENT_TYPE_LABEL[t]}</option>)}
          </SelectField>
        </div>
      </Card>

      <FormError message={list.error} />
      <DataTable label="Movimientos de inventario">
        <thead>
          <tr>
            <th className={th}>Fecha</th><Th info={{ text: 'Tipo de movimiento. Entradas: apertura, compra, devolución de cliente, traslado recibido y ajuste a favor. Salidas: venta, devolución a proveedor, traslado enviado y ajuste en contra.' }}>Movimiento</Th><th className={th}>Producto</th><th className={th}>Sucursal</th>
            <Th right info={{ text: 'Unidades del movimiento, en la unidad base del producto. Las entradas suman y las salidas restan a la existencia.' }}>Cantidad</Th>{costs && <Th right info={{ text: 'Valor del movimiento. Las entradas llevan su costo y las salidas se valoran a costo promedio ponderado. Solo lo ve quien tiene el permiso de costos.' }}>Valor</Th>}
            <th className={th}>Motivo y nota</th><th className={th}>Registró</th><th className={th}><span className="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          {list.loading && !list.data ? <TableSkeleton cols={cols + 1} /> : rows.length === 0 ? (
            <EmptyRow cols={cols + 1}>No hay movimientos con estos filtros.</EmptyRow>
          ) : rows.map((m) => (
            <tr key={m.id} className={tr}>
              <td className={td}>{formatDay(m.date)}</td>
              <td className={td}>{MOVEMENT_TYPE_LABEL[m.type]}{m.reversed && <div className="text-xs font-medium text-danger">Revertido</div>}</td>
              <td className={td}>{m.product.name}<div className="text-xs text-ink-muted">{m.product.code}</div></td>
              <td className={td}>{m.branch}</td>
              <td className={`${td} tabular text-right`}>{num(m.quantity)}{m.entered_unit && <div className="text-xs text-ink-muted">{num(m.entered)} {m.entered_unit}</div>}</td>
              {costs && <td className={`${td} tabular text-right`}>{formatMoney(m.value)}</td>}
              <td className={td}>{[m.reason ? REASON_LABEL[m.reason] ?? m.reason : null, m.reference, m.note].filter(Boolean).join(' · ') || '—'}</td>
              <td className={td}>{m.user ?? '—'}</td>
              <td className={td}>{manage && m.reversible && <Button small variant="secondary" onClick={() => { setError(null); setNote(''); setReversing(m) }} aria-label={`Revertir ${MOVEMENT_TYPE_LABEL[m.type]} de ${m.product.name}`}>Revertir</Button>}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>

      {creating && <InventoryMovementModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); setPage(1); list.reload() }} />}
      {reversing && (
        <Modal title="Revertir movimiento" onClose={() => setReversing(null)}>
          <form onSubmit={(e) => { e.preventDefault(); void reverse() }} className="space-y-3">
            <p className="text-sm text-ink-muted">
              {reversing.type === 'entry' ? 'Se revertirá el documento de entrada completo, con todas sus líneas.' : reversing.type === 'transfer_in' || reversing.type === 'transfer_out' ? 'Se revertirá el traslado completo: la salida y la entrada.' : 'Se revertirá este movimiento.'} El original se conserva y queda marcado como revertido.
            </p>
            <TextAreaField id="mv-reverse-note" label="Motivo de la reversa" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
            <FormError message={error} />
            <FormActions saving={saving} onCancel={() => setReversing(null)} />
          </form>
        </Modal>
      )}
    </section>
  )
}
