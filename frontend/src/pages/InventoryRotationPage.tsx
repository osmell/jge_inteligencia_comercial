import { Link } from 'react-router-dom'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr, Th } from '../components/ui'
import { download } from '../lib/api'
import { formatMoney } from '../lib/format'
import { ROTATION_LABEL, type RotationReport, type RotationStatus } from '../lib/inventory'
import type { Branch, Company } from '../lib/types'
import { useDebounced } from '../lib/useDebounced'
import { useExport } from '../lib/useExport'
import { useFilters } from '../lib/useFilters'
import { useLoad } from '../lib/useLoad'

const STATUSES: RotationStatus[] = ['out_of_stock', 'below_min', 'no_rotation', 'above_max', 'ok']
const num = (v: string | null) => (v === null ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 4 }))

/** Rotación, cobertura, mínimos y máximos por producto y sucursal, con alertas de reposición. */
export default function InventoryRotationPage() {
  const { filters: f, page, set, setPage } = useFilters({ text: '', companyId: '', branchId: '', status: 'all' as 'all' | RotationStatus })
  const { companyId, branchId, status } = f
  const term = useDebounced(f.text.trim())
  const exporter = useExport()

  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const filters = new URLSearchParams()
  if (term) filters.set('q', term)
  if (companyId) filters.set('company_id', companyId)
  if (branchId) filters.set('branch_id', branchId)
  if (status !== 'all') filters.set('status', status)
  const report = useLoad<RotationReport>(`/inventory/rotation?${new URLSearchParams({ ...Object.fromEntries(filters), page: String(page) })}`)
  const rows = report.data?.data ?? []
  const meta = report.data?.meta
  const costs = rows.some((r) => r.value !== undefined)
  const p = meta?.params
  const branchOptions = (branches.data?.data ?? []).filter((b) => companyId === '' || String(b.company_id) === companyId)

  const exportCsv = () => exporter.run('csv', () => download(`/inventory/rotation/export?${filters}`, 'rotacion-de-inventario.csv'), 'No se pudo exportar.')

  return (
    <section>
      <PageHeader
        title="Rotación y reposición"
        description="Cobertura, mínimos y máximos por producto y sucursal, calculados con las ventas. Orientan la reposición; no generan compras."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inventario">← Existencias</Link>}
      />

      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" aria-label="Resumen por estado" aria-busy={report.loading}>
        {STATUSES.map((s) => (
          <button key={s} type="button" onClick={() => set({ status: status === s ? 'all' : s })} aria-pressed={status === s}
            className={`rounded-xl border p-4 text-left transition-colors ${status === s ? 'border-jge-blue bg-surface-muted' : 'border-line bg-surface hover:bg-surface-muted'}`}>
            <p className="text-sm text-ink-muted">{ROTATION_LABEL[s]}</p>
            <p className={`tabular mt-0.5 text-2xl font-semibold ${(s === 'out_of_stock' || s === 'below_min') && (meta?.counts[s] ?? 0) > 0 ? 'text-danger' : ''}`}>{meta ? meta.counts[s].toLocaleString('en-US') : '—'}</p>
          </button>
        ))}
      </div>

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SearchInput label="Buscar producto" value={f.text} onChange={(v) => set({ text: v })} placeholder="Código o nombre" />
          <SelectField id="rot-company" label="Compañía" value={companyId} onChange={(e) => set({ companyId: e.target.value, branchId: '' })}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="rot-branch" label="Sucursal" value={branchId} onChange={(e) => set({ branchId: e.target.value })}>
            <option value="">Todas</option>
            {branchOptions.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          <div className="flex items-end"><Button variant="secondary" loading={exporter.exporting !== null} onClick={() => void exportCsv()}>Exportar CSV</Button></div>
        </div>
      </Card>

      {p && (
        <div className="mb-3">
          <Alert tone="info">
            Demanda diaria = ventas de los últimos {p.demand_days} días ÷ {p.demand_days}. Mínimo = demanda × {p.reorder_days + p.safety_days} días ({p.reorder_days} de reposición + {p.safety_days} de seguridad); máximo = mínimo + {p.cycle_days} días de ciclo de compra.
            «Sin rotación»: existencia sin ventas en {p.no_movement_days} días. Los días de reposición son un supuesto: ajústalos con el tiempo real de tus proveedores.
          </Alert>
        </div>
      )}
      <FormError message={report.error ?? exporter.error} />

      <DataTable label="Rotación y reposición">
        <thead>
          <tr>
            <th className={th}>Producto</th><th className={th}>Sucursal</th><Th right info={{ text: 'Unidades que hay hoy en la sucursal, en la unidad base del producto.' }}>Existencia</Th><Th right info={{ text: 'Unidades vendidas por día en la ventana de demanda configurada. Solo cuentan las ventas; no los traslados ni los ajustes.', formula: 'Demanda diaria = Unidades vendidas en la ventana ÷ Días de la ventana' }}>Demanda diaria</Th>
            <Th right info={{ text: 'Cuántos días alcanza la existencia al ritmo actual de ventas.', formula: 'Cobertura = Existencia ÷ Demanda diaria' }}>Cobertura (días)</Th><Th right info={{ text: 'Cuántas veces se renovó la existencia en la ventana de demanda.', formula: 'Rotación = Unidades vendidas en la ventana ÷ Existencia promedio (promedio entre la existencia al inicio y la actual)' }}>Rotación</Th><Th right info={{ text: 'Mínimo: la existencia por debajo de la cual hay que reponer. Máximo: la existencia por encima de la cual hay sobreinventario. Los días son configurables.', formula: 'Mínimo = Demanda diaria × (Días de reposición + Días de seguridad); Máximo = Mínimo + Demanda diaria × Ciclo de compra' }}>Mín. / Máx.</Th>
            {costs && <Th right info={{ text: 'Valor inmovilizado de la existencia a costo promedio. Solo lo ve quien tiene el permiso de costos.' }}>Valor</Th>}<Th info={{ text: 'Situación del producto, por prioridad: agotado (sin existencia pero con demanda), bajo el mínimo, sin rotación (existencia sin ventas en los últimos días configurados), sobre el máximo o normal.' }}>Estado</Th>
          </tr>
        </thead>
        <tbody>
          {report.loading && !report.data ? <TableSkeleton cols={costs ? 9 : 8} /> : rows.length === 0 ? (
            <EmptyRow cols={costs ? 9 : 8}>No hay productos con estos filtros.</EmptyRow>
          ) : rows.map((r) => (
            <tr key={`${r.branch_id}-${r.product.id}`} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/inventario/kardex?${new URLSearchParams({ company_id: String(r.company_id), branch_id: String(r.branch_id), product_id: String(r.product.id) })}`}>{r.product.name}</Link>
                <div className="text-xs text-ink-muted">{r.product.code}</div>
              </td>
              <td className={td}>{r.branch}</td>
              <td className={`${td} tabular text-right font-medium`}>{num(r.stock)}</td>
              <td className={`${td} tabular text-right`}>{num(r.daily_demand)}</td>
              <td className={`${td} tabular text-right`}>{r.coverage_days === null ? '—' : num(r.coverage_days)}</td>
              <td className={`${td} tabular text-right`}>{num(r.rotation)}</td>
              <td className={`${td} tabular text-right`}>{num(r.min)} / {num(r.max)}</td>
              {costs && <td className={`${td} tabular text-right`}>{formatMoney(r.value)}</td>}
              <td className={td}>
                <Badge>{ROTATION_LABEL[r.status]}</Badge>
                {r.suggested !== null && <div className="mt-0.5 text-xs text-ink-muted">Reponer ≈ {num(r.suggested)}</div>}
                {r.status === 'no_rotation' && <div className="mt-0.5 text-xs text-ink-muted">{r.last_sale ? `Última venta hace ${r.days_since_sale} días` : 'Sin ventas'}</div>}
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}{meta ? ` · ${meta.total.toLocaleString('en-US')} productos` : ''}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!meta?.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
    </section>
  )
}
