import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormActions, FormError, PageHeader, SearchInput, SelectField, StatusBadge, TextField, td, th, tr, type FieldErrors } from '../components/ui'
import { ApiError, api } from '../lib/api'
import type { InventoryProductRow, ProductUnits, Unit } from '../lib/inventory'
import { useLoad } from '../lib/useLoad'

interface Row {
  unit_id: string
  factor: string
}

function UnitsCatalog({ units, manage, onChanged }: { units: Unit[]; manage: boolean; onChanged: () => void }) {
  const toast = useToast()
  const [form, setForm] = useState({ code: '', name: '' })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [toggleError, setToggleError] = useState<string | null>(null)

  async function add() {
    setSaving(true)
    setErrors({})
    try {
      await api('/inventory/units', { method: 'POST', body: form })
      toast.success('Unidad creada.')
      setForm({ code: '', name: '' })
      onChanged()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { code: [e instanceof Error ? e.message : 'No se pudo crear.'] })
    } finally {
      setSaving(false)
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

function ProductEditor({ productId, units, manage, onSaved }: { productId: number; units: Unit[]; manage: boolean; onSaved: () => void }) {
  const detail = useLoad<{ data: ProductUnits }>(`/inventory/products/${productId}/units`)
  const d = detail.data?.data

  if (!d) return <FormError message={detail.error} />

  return <ProductForm key={JSON.stringify(d)} d={d} units={units} manage={manage} onReload={detail.reload} onSaved={onSaved} />
}

function ProductForm({ d, units, manage, onReload, onSaved }: { d: ProductUnits; units: Unit[]; manage: boolean; onReload: () => void; onSaved: () => void }) {
  const toast = useToast()
  const [base, setBase] = useState(d.base_unit ? String(d.base_unit.id) : '')
  const [rows, setRows] = useState<Row[]>(d.equivalences.map((e) => ({ unit_id: String(e.unit_id), factor: e.factor })))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await api(`/inventory/products/${d.id}/units`, { method: 'PUT', body: { base_unit_id: Number(base), equivalences: rows.map((r) => ({ unit_id: Number(r.unit_id), factor: r.factor.trim() })) } })
      toast.success('Unidades del producto guardadas.')
      onReload()
      onSaved()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { base_unit_id: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
    } finally {
      setSaving(false)
    }
  }

  const baseName = units.find((u) => String(u.id) === base)?.name ?? 'la unidad base'
  const set = (i: number, patch: Partial<Row>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <Card className="p-4">
      <h3 className="text-sm font-semibold">{d.name} <span className="font-normal text-ink-muted">({d.code})</span></h3>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="mt-3 space-y-3">
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
        {manage && <FormActions saving={saving} onCancel={onReload} />}
      </form>
    </Card>
  )
}

export default function InventoryUnitsPage() {
  const { can } = useAuth()
  const manage = can('inventory.manage')
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [onlyMissing, setOnlyMissing] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)

  useEffect(() => {
    const t = setTimeout(() => setTerm(text.trim()), 300)
    return () => clearTimeout(t)
  }, [text])

  const units = useLoad<{ data: Unit[] }>('/inventory/units')
  const query = new URLSearchParams()
  if (term) query.set('q', term)
  if (onlyMissing) query.set('missing_base', '1')
  const products = useLoad<{ data: InventoryProductRow[] }>(`/inventory/products?${query}`)
  const list = units.data?.data ?? []

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
            <SearchInput label="Buscar producto" value={text} onChange={setText} placeholder="Código o nombre" />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={onlyMissing} onChange={(e) => setOnlyMissing(e.target.checked)} /> Solo sin unidad base</label>
          </div>
          <DataTable label="Productos y unidad base">
            <thead><tr><th className={th}>Producto</th><th className={th}>Unidad base</th><th className={th}>Equivalencias</th></tr></thead>
            <tbody>
              {(products.data?.data ?? []).length === 0 ? <EmptyRow cols={3}>No hay productos con estos filtros.</EmptyRow> : (products.data?.data ?? []).map((p) => (
                <tr key={p.id} className={tr}>
                  <td className={td}><button type="button" className="text-left font-medium text-jge-blue hover:underline" onClick={() => setSelected(p.id)}>{p.name}</button><div className="text-xs text-ink-muted">{p.code}</div></td>
                  <td className={td}>{p.base_unit ? p.base_unit.name : <span className="text-danger">Sin definir</span>}</td>
                  <td className={`${td} tabular`}>{p.equivalences_count}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <p className="mt-1 text-xs text-ink-muted">Se muestran los primeros 25; usa la búsqueda para encontrar otros.</p>
        </section>
      </div>

      {selected !== null && <div className="mt-6"><ProductEditor key={selected} productId={selected} units={list} manage={manage} onSaved={products.reload} /></div>}
    </section>
  )
}
