import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, SelectField, StatusBadge, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import type { InventoryProductRow, PageMeta, ProductUnits, Unit } from '../lib/inventory'
import { useDebounced } from '../lib/useDebounced'
import { useFilters } from '../lib/useFilters'
import { useFitRows } from '../lib/useFitRows'
import { useLoad } from '../lib/useLoad'
import { useSubmit } from '../lib/useSubmit'

interface Row {
  unit_id: string
  factor: string
}

function UnitsCatalog({ units, manage, onChanged }: { units: Unit[]; manage: boolean; onChanged: () => void }) {
  const toast = useToast()
  const [form, setForm] = useState({ code: '', name: '' })
  const { errors, saving, run } = useSubmit()
  const [toggleError, setToggleError] = useState<string | null>(null)

  async function add() {
    const ok = await run(async () => { await api('/inventory/units', { method: 'POST', body: form }) }, 'code')
    if (ok) {
      toast.success('Unidad creada.')
      setForm({ code: '', name: '' })
      onChanged()
    }
  }

  async function toggle(u: Unit) {
    setToggleError(null)
    try {
      await api(`/inventory/units/${u.id}`, { method: 'PUT', body: { active: !u.active } })
      onChanged()
    } catch (e) {
      setToggleError(e instanceof Error ? e.message : 'No se pudo cambiar.')
    }
  }

  return (
    <section aria-labelledby="units-title">
      <h2 id="units-title" className="mb-2 text-sm font-semibold text-ink">Unidades de medida</h2>
      <FormError message={toggleError} />
      <DataTable label="Unidades de medida">
        <thead><tr><th className={th}>Unidad</th><th className={th}>Código</th><th className={th}>Estado</th>{manage && <th className={th}><span className="sr-only">Acciones</span></th>}</tr></thead>
        <tbody>
          {units.length === 0 ? <EmptyRow cols={manage ? 4 : 3}>Sin unidades.</EmptyRow> : units.map((u) => (
            <tr key={u.id} className={tr}>
              <td className={td}>{u.name}</td><td className={td}>{u.code}</td><td className={td}><StatusBadge active={u.active} /></td>
              {manage && <td className={td}><Button small variant="secondary" onClick={() => void toggle(u)}>{u.active ? 'Desactivar' : 'Activar'}</Button></td>}
            </tr>
          ))}
        </tbody>
      </DataTable>
      {manage && (
        <form onSubmit={(e) => { e.preventDefault(); void add() }} className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
          <TextField id="unit-code" label="Código" hint="Minúsculas, números y guion bajo" value={form.code} maxLength={30} errors={errors.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <TextField id="unit-name" label="Nombre" value={form.name} maxLength={60} errors={errors.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Button type="submit" loading={saving}>Agregar unidad</Button>
        </form>
      )}
    </section>
  )
}

function ProductEditor({ productId, units, manage, onSaved, onClose }: { productId: number; units: Unit[]; manage: boolean; onSaved: () => void; onClose: () => void }) {
  const detail = useLoad<{ data: ProductUnits }>(`/inventory/products/${productId}/units`)
  const d = detail.data?.data

  if (!d) return <FormError message={detail.error} />

  return <ProductForm key={JSON.stringify(d)} d={d} units={units} manage={manage} onSaved={onSaved} onClose={onClose} />
}

function ProductForm({ d, units, manage, onSaved, onClose }: { d: ProductUnits; units: Unit[]; manage: boolean; onSaved: () => void; onClose: () => void }) {
  const toast = useToast()
  const [base, setBase] = useState(d.base_unit ? String(d.base_unit.id) : '')
  const [rows, setRows] = useState<Row[]>(d.equivalences.map((e) => ({ unit_id: String(e.unit_id), factor: e.factor })))
  const { errors, saving, run } = useSubmit()

  async function save() {
    const ok = await run(async () => { await api(`/inventory/products/${d.id}/units`, { method: 'PUT', body: { base_unit_id: Number(base), equivalences: rows.map((r) => ({ unit_id: Number(r.unit_id), factor: r.factor.trim() })) } }) }, 'base_unit_id')
    if (ok) {
      toast.success('Unidades del producto guardadas.')
      onSaved()
    }
  }

  const baseName = units.find((u) => String(u.id) === base)?.name ?? 'la unidad base'
  const set = (i: number, patch: Partial<Row>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <>
      <p className="mb-3 text-sm text-ink-muted">{d.name} <span>({d.code})</span></p>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        <SelectField id="base-unit" label="Unidad base (en la que se lleva el inventario)" value={base} disabled={!manage} errors={errors.base_unit_id} onChange={(e) => setBase(e.target.value)}>
          <option value="">Elige una unidad…</option>
          {units.filter((u) => u.active || String(u.id) === base).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
        </SelectField>

        <fieldset>
          <legend className="mb-1 text-sm font-medium">Equivalencias</legend>
          <p className="mb-2 text-xs text-ink-muted">Cuántas unidades base vale 1 de cada unidad. Ejemplo: 1 cubeta = 5 galones; 1/16 = 0,0625 galones. Cada producto tiene las suyas.</p>
          {rows.length === 0 && <p className="mb-2 text-sm text-ink-muted">Sin equivalencias: solo se mueve en su unidad base.</p>}
          {rows.map((r, i) => (
            <div key={i} className="mb-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
              <SelectField id={`eq-unit-${i}`} label="Unidad" value={r.unit_id} disabled={!manage} errors={errors[`equivalences.${i}.unit_id`]} onChange={(e) => set(i, { unit_id: e.target.value })}>
                <option value="">Elige…</option>
                {units.filter((u) => u.active || String(u.id) === r.unit_id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </SelectField>
              <TextField id={`eq-factor-${i}`} label={`Vale (${baseName})`} inputMode="decimal" value={r.factor} disabled={!manage} errors={errors[`equivalences.${i}.factor`]} onChange={(e) => set(i, { factor: e.target.value })} />
              {manage && <Button type="button" small variant="secondary" onClick={() => setRows(rows.filter((_, j) => j !== i))} aria-label={`Quitar equivalencia ${i + 1}`}>Quitar</Button>}
            </div>
          ))}
          {manage && <Button type="button" small variant="secondary" onClick={() => setRows([...rows, { unit_id: '', factor: '' }])}>Agregar equivalencia</Button>}
          {errors.equivalences && <FormError message={errors.equivalences[0]} />}
        </fieldset>
        {manage && <FormActions saving={saving} onCancel={onClose} />}
      </form>
    </>
  )
}

/** Filas que caben en la altura de la ventana sin hacer scroll (descontando encabezado, filtros, encabezado de la tabla y paginación). */
function rowsThatFit(height: number): number {
  return Math.max(5, Math.min(50, Math.floor((height - 460) / 58)))
}

export default function InventoryUnitsPage() {
  const { can } = useAuth()
  const manage = can('inventory.manage')
  const { filters: f, page, set, setPage } = useFilters({ text: '', onlyMissing: false })
  const [selected, setSelected] = useState<InventoryProductRow | null>(null)
  const term = useDebounced(f.text.trim())
  // La cantidad de filas sigue a la altura de la ventana; al cambiarla se vuelve a la primera página.
  const perPage = useFitRows(rowsThatFit, () => setPage(1))

  const units = useLoad<{ data: Unit[] }>('/inventory/units')
  const query = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  if (term) query.set('q', term)
  if (f.onlyMissing) query.set('missing_base', '1')
  const products = useLoad<{ data: InventoryProductRow[]; meta?: PageMeta }>(`/inventory/products?${query}`)
  const list = units.data?.data ?? []
  const meta = products.data?.meta
  const lastPage = meta?.last_page ?? 1

  return (
    <section>
      <PageHeader title="Unidades y equivalencias" description="La unidad base de cada producto y sus equivalencias (cubeta, barril, litro, fracciones del galón…). El inventario se lleva en la unidad base." />
      <div className="mb-4"><Alert tone="info">Un producto sin unidad base no admite movimientos de inventario. Las equivalencias son de cada producto: no se asume un contenido universal de cajas, cubetas o barriles.</Alert></div>
      <FormError message={units.error ?? products.error} />

      <div className="grid gap-6 lg:grid-cols-2">
        <UnitsCatalog units={list} manage={manage} onChanged={units.reload} />

        <section aria-labelledby="products-title">
          <h2 id="products-title" className="mb-2 text-sm font-semibold text-ink">Productos</h2>
          <div className="mb-2 grid gap-3 sm:grid-cols-2 sm:items-end">
            <SearchInput label="Buscar producto" value={f.text} onChange={(v) => set({ text: v })} placeholder="Código o nombre" />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.onlyMissing} onChange={(e) => set({ onlyMissing: e.target.checked })} /> Solo sin unidad base</label>
          </div>
          <DataTable label="Productos y unidad base">
            <thead><tr><th className={th}>Producto</th><th className={th}>Unidad base</th><th className={th}>Equivalencias</th></tr></thead>
            <tbody>
              {(products.data?.data ?? []).length === 0 ? <EmptyRow cols={3}>No hay productos con estos filtros.</EmptyRow> : (products.data?.data ?? []).map((p) => (
                <tr key={p.id} className={`${tr} cursor-pointer`} onClick={() => setSelected(p)}>
                  <td className={td}><button type="button" className="cursor-pointer text-left font-medium text-jge-blue hover:underline" onClick={(e) => { e.stopPropagation(); setSelected(p) }}>{p.name}</button><div className="text-xs text-ink-muted">{p.code}</div></td>
                  <td className={td}>{p.base_unit ? p.base_unit.name : <span className="text-danger">Sin definir</span>}</td>
                  <td className={`${td} tabular`}>{p.equivalences_count}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm text-ink-muted" aria-live="polite">
            <span>{meta ? `${meta.total} ${meta.total === 1 ? 'producto' : 'productos'} · Página ${meta.page} de ${lastPage}` : ''}</span>
            {lastPage > 1 && (
              <span className="flex gap-2">
                <Button small variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
                <Button small variant="secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Siguiente</Button>
              </span>
            )}
          </div>
        </section>
      </div>

      {selected !== null && (
        <Modal title={manage ? `Unidades de ${selected.name}` : `Unidades de ${selected.name} (solo consulta)`} onClose={() => setSelected(null)} wide>
          <ProductEditor productId={selected.id} units={list} manage={manage} onClose={() => setSelected(null)} onSaved={() => { setSelected(null); void products.reload() }} />
        </Modal>
      )}
    </section>
  )
}
