import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Badge, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr, Stat, Th } from '../components/ui'
import type { StockList } from '../lib/inventory'
import { formatMoney } from '../lib/format'
import type { Branch, Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const STATUS = [['with', 'Con existencias'], ['zero', 'Agotados'], ['all', 'Todos']] as const

/** Existencias por sucursal en la unidad base de cada producto. Los costos solo los ve quien tiene ese permiso. */
export default function InventoryStockPage() {
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [status, setStatus] = useState<'with' | 'zero' | 'all'>('with')
  const [page, setPage] = useState(1)

  useEffect(() => {
    const t = setTimeout(() => { setTerm(text.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [text])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const query = new URLSearchParams({ status, page: String(page) })
  if (term) query.set('q', term)
  if (companyId) query.set('company_id', companyId)
  if (branchId) query.set('branch_id', branchId)
  const stock = useLoad<StockList>(`/inventory/stock?${query}`)
  const rows = stock.data?.data ?? []
  const meta = stock.data?.meta
  const costs = meta?.costs_visible === true
  const cols = costs ? 7 : 5
  const branchOptions = (branches.data?.data ?? []).filter((b) => companyId === '' || String(b.company_id) === companyId)

  return (
    <section>
      <PageHeader title="Existencias" description="Existencias por sucursal, en la unidad base de cada producto. Se calculan con los movimientos del libro de inventario." />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2" aria-busy={stock.loading}>
        <Stat label="Productos" info={{ text: 'Productos con movimientos de inventario que cumplen los filtros elegidos.' }} value={meta ? meta.totals.products.toLocaleString('en-US') : '—'} hint="Según los filtros elegidos" />
        {costs && <Stat label="Valor del inventario" info={{ text: 'Valor de todas las existencias del filtro a costo promedio ponderado.', formula: 'Valor = suma de (Existencia × Costo promedio)' }} value={meta?.totals.value ? formatMoney(meta.totals.value) : '—'} hint="A costo promedio ponderado" />}
      </div>

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SearchInput label="Buscar producto" value={text} onChange={setText} placeholder="Código o nombre" />
          <SelectField id="stock-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setBranchId(''); setPage(1) }}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="stock-branch" label="Sucursal" value={branchId} onChange={(e) => { setBranchId(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {branchOptions.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          <SelectField id="stock-status" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1) }}>
            {STATUS.map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </SelectField>
        </div>
      </Card>

      <FormError message={stock.error} />
      {(meta?.open_shortfalls ?? 0) > 0 && (
        <div className="mb-3">
          <Alert tone="warning">Hay <strong>{meta?.open_shortfalls}</strong> ventas con faltante por conciliar (no alcanzó la existencia). <Link className="underline" to="/inventario/faltantes">Ver faltantes</Link></Alert>
        </div>
      )}
      {rows.some((r) => !r.reconciled) && (
        <div className="mb-3"><Alert tone="warning">Hay sucursales sin inventario inicial cargado: sus saldos se calculan solo con movimientos parciales y no son existencias reales hasta conciliarlas.</Alert></div>
      )}

      <DataTable label="Existencias por sucursal">
        <thead>
          <tr>
            <th className={th}>Producto</th><th className={th}>Sucursal</th><Th right info={{ text: 'Unidades que hay hoy en la sucursal, en la unidad base del producto. Se calcula con los movimientos del libro de inventario: entradas menos salidas.', formula: 'Existencia = Entradas − Salidas' }}>Existencia</Th><Th info={{ text: 'Unidad en la que se lleva la existencia del producto. Las demás unidades se convierten a esta con sus equivalencias.' }}>Unidad base</Th>
            {costs && <><Th right info={{ text: 'Costo por unidad base, promediado entre las entradas con costo.', formula: 'Costo promedio = Valor del saldo ÷ Existencia' }}>Costo promedio</Th><Th right info={{ text: 'Valor de las existencias a costo promedio ponderado. Solo lo ve quien tiene el permiso de costos.', formula: 'Valor = Existencia × Costo promedio' }}>Valor</Th></>}
            <th className={th}><span className="sr-only">Kárdex</span></th>
          </tr>
        </thead>
        <tbody>
          {stock.loading && !stock.data ? <TableSkeleton cols={cols + 1} /> : rows.length === 0 ? (
            <EmptyRow cols={cols + 1}>No hay existencias con estos filtros.</EmptyRow>
          ) : rows.map((r) => (
            <tr key={`${r.branch_id}-${r.product.id}`} className={tr}>
              <td className={td}>{r.product.name}<div className="text-xs text-ink-muted">{r.product.code} · {r.company}</div></td>
              <td className={td}>{r.branch}{!r.reconciled && <div className="mt-0.5"><Badge>Sin inventario inicial</Badge></div>}</td>
              <td className={`${td} tabular text-right font-medium`}>{Number(r.quantity).toLocaleString('en-US', { maximumFractionDigits: 8 })}</td>
              <td className={td}>{r.unit ?? '—'}</td>
              {costs && <><td className={`${td} tabular text-right`}>{formatMoney(r.average_cost)}</td><td className={`${td} tabular text-right`}>{formatMoney(r.value)}</td></>}
              <td className={td}>
                <Link className="text-jge-blue hover:underline" aria-label={`Kárdex de ${r.product.name} en ${r.branch}`} to={`/inventario/kardex?${new URLSearchParams({ company_id: String(r.company_id), branch_id: String(r.branch_id), product_id: String(r.product.id) })}`}>Kárdex</Link>
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!meta?.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
    </section>
  )
}
