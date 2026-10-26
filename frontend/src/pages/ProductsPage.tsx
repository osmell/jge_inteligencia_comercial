import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Alert, Button, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr, Stat, Th } from '../components/ui'
import type { MissingProductRow, ProductCoverage, ProductLevels, ProductRow } from '../lib/customers'
import { formatMoney } from '../lib/format'
import type { Company } from '../lib/types'
import { useDebounced } from '../lib/useDebounced'
import { useFilters } from '../lib/useFilters'
import { useLoad } from '../lib/useLoad'

type Tab = 'catalog' | 'missing'

/** Valor que el servidor entiende como «sin ese nivel». */
const NONE = '__none__'

const SORTS = { name: 'Nombre', code: 'Código', net_total: 'Venta 12 meses' } as const
type Sort = keyof typeof SORTS

function Pager({ page, setPage, hasMore, loading }: { page: number; setPage: (page: number) => void; hasMore: boolean; loading: boolean }) {
  return (
    <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
      <span>Página {page}</span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={page === 1 || loading} onClick={() => setPage(page - 1)}>Anterior</Button>
        <Button variant="secondary" disabled={!hasMore || loading} onClick={() => setPage(page + 1)}>Siguiente</Button>
      </div>
    </div>
  )
}

export default function ProductsPage() {
  const { can } = useAuth()
  const { filters: f, page, set, setPage } = useFilters({ tab: 'catalog' as Tab, text: '', companyId: '', level1: '', level2: '', level3: '', sort: 'name' as Sort })
  const { tab, companyId, level1, level2, level3, sort } = f
  const term = useDebounced(f.text.trim())

  const companies = useLoad<{ data: Company[] }>('/companies')
  const coverage = useLoad<{ data: ProductCoverage }>('/sales/products/coverage')
  const levelsQuery = new URLSearchParams({ ...(companyId ? { company_id: companyId } : {}), ...(level1 && level1 !== NONE ? { level1 } : {}), ...(level2 && level2 !== NONE ? { level2 } : {}) })
  const levels = useLoad<{ data: ProductLevels }>(`/sales/products/levels?${levelsQuery}`)
  const query = new URLSearchParams({
    sort, page: String(page),
    ...(term ? { q: term } : {}), ...(companyId ? { company_id: companyId } : {}),
    ...(level1 ? { level1 } : {}), ...(level2 ? { level2 } : {}), ...(level3 ? { level3 } : {}),
  })
  const list = useLoad<{ data: ProductRow[]; meta: { has_more: boolean } }>(`/sales/products?${query}`, { enabled: tab === 'catalog' })
  const missing = useLoad<{ data: MissingProductRow[]; meta: { has_more: boolean } }>(`/sales/products/missing?page=${page}`, { enabled: tab === 'missing' })

  const c = coverage.data?.data
  const rows = list.data?.data ?? []
  const missingRows = missing.data?.data ?? []
  const lv = levels.data?.data
  const options = (values: string[] | undefined) => (
    <>
      <option value="">Todos</option>
      <option value={NONE}>Sin nivel</option>
      {(values ?? []).map((v) => <option key={v} value={v}>{v}</option>)}
    </>
  )

  return (
    <section>
      <PageHeader
        title="Productos"
        description="Catálogo de productos con sus tres niveles (grupo, línea y categoría) y su cobertura sobre las ventas de los últimos 12 meses."
        action={can('imports.manage') ? <Link className="text-sm text-jge-blue hover:underline" to="/importaciones">Cargar catálogo</Link> : undefined}
      />

      <FormError message={coverage.error} />
      {c && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label="Productos en el catálogo" value={String(c.catalog_products)} info={{ text: 'Productos registrados en el catálogo de la aplicación (cargados desde el archivo de existencias o creados a mano).' }} />
          <Stat label="Productos vendidos (12 meses)" value={String(c.sold_products)} info={{ text: 'Productos distintos que aparecen en las líneas de venta de los últimos 12 meses, estén o no en el catálogo.' }} />
          <Stat label="Vendidos sin catálogo" value={String(c.missing_products)} info={{ text: 'Productos vendidos en los últimos 12 meses que todavía no están en el catálogo. La venta neta que se muestra es la de esos productos.' }}  hint={c.missing_products > 0 ? `${formatMoney(c.missing_net_total)} de venta neta` : undefined} />
          <Stat label="Venta con catálogo" info={{ text: 'Qué parte de la venta neta de los últimos 12 meses corresponde a productos que sí están en el catálogo.', formula: '(Venta neta − Venta neta de productos sin catálogo) ÷ Venta neta × 100' }} value={c.covered_pct === null ? '—' : `${String(c.covered_pct).replace('.', ',')} %`} hint="de la venta neta de 12 meses" />
        </div>
      )}
      {c && c.missing_products > 0 && (
        <div className="mb-4">
          <Alert tone="warning">
            {c.missing_products} productos vendidos no están en el catálogo y aparecen como «Sin clasificar» en grupo y línea. Revisa la pestaña «Vendidos sin catálogo» y cárgalos desde Importaciones.
          </Alert>
        </div>
      )}

      <div role="tablist" aria-label="Vista" className="mb-4 flex gap-1 rounded-xl bg-surface-muted p-1 sm:w-fit">
        {([['catalog', 'Catálogo'], ['missing', 'Vendidos sin catálogo']] as const).map(([key, label]) => (
          <button
            key={key} type="button" role="tab" aria-selected={tab === key} id={`tab-${key}`}
            onClick={() => set({ tab: key })}
            className={`min-h-9 cursor-pointer rounded-lg px-3 text-sm font-medium transition-colors ${tab === key ? 'bg-jge-blue text-white' : 'text-ink-muted hover:bg-jge-blue-50 hover:text-jge-blue'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'catalog' && (
        <>
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <div className="w-full sm:w-64"><TextField id="product-search" label="Buscar por código o nombre" type="search" value={f.text} onChange={(e) => set({ text: e.target.value })} /></div>
            <SelectField id="product-company" label="Compañía" value={companyId} onChange={(e) => set({ companyId: e.target.value, level1: '', level2: '', level3: '' })}>
              <option value="">Todas</option>
              {(companies.data?.data ?? []).map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
            </SelectField>
            <SelectField id="product-level1" label="Grupo (Nivel 1)" value={level1} onChange={(e) => set({ level1: e.target.value, level2: '', level3: '' })}>{options(lv?.level1)}</SelectField>
            <SelectField id="product-level2" label="Línea (Nivel 2)" value={level2} onChange={(e) => set({ level2: e.target.value, level3: '' })}>{options(lv?.level2)}</SelectField>
            <SelectField id="product-level3" label="Categoría (Nivel 3)" value={level3} onChange={(e) => set({ level3: e.target.value })}>{options(lv?.level3)}</SelectField>
            <SelectField id="product-sort" label="Ordenar por" value={sort} onChange={(e) => set({ sort: e.target.value as Sort })}>
              {(Object.keys(SORTS) as Sort[]).map((k) => <option key={k} value={k}>{SORTS[k]}</option>)}
            </SelectField>
          </div>

          <FormError message={list.error} />
          <DataTable label="Catálogo de productos">
            <thead>
              <tr>
                <th className={th}>Compañía</th><th className={th}>Código</th><th className={th}>Producto</th>
                <Th info={{ text: 'Nivel 1 del catálogo de productos.' }}>Grupo</Th><Th info={{ text: 'Nivel 2 del catálogo de productos.' }}>Línea</Th><Th info={{ text: 'Nivel 3 del catálogo de productos.' }}>Categoría</Th>
                <Th right info={{ text: 'Venta neta del producto en los últimos 12 meses.' }}>Venta 12 meses</Th>
              </tr>
            </thead>
            <tbody>
              {list.loading && !list.data && <TableSkeleton cols={7} />}
              {!list.loading && rows.length === 0 && <EmptyRow cols={7}>{term || level1 || level2 || level3 ? 'Ningún producto coincide con los filtros.' : 'Aún no hay productos. Cárgalos desde Importaciones (tipo Productos).'}</EmptyRow>}
              {rows.map((r) => (
                <tr key={`${r.company_id}|${r.code}`} className={tr}>
                  <td className={td}>{r.company}</td>
                  <td className={`${td} tabular`}>{r.code}</td>
                  <td className={`${td} font-medium`}>{r.name}</td>
                  <td className={td}>{r.level1 || '—'}</td>
                  <td className={td}>{r.level2 || '—'}</td>
                  <td className={td}>{r.level3 || '—'}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <Pager page={page} setPage={setPage} hasMore={!!list.data?.meta.has_more} loading={list.loading} />
        </>
      )}

      {tab === 'missing' && (
        <>
          <FormError message={missing.error} />
          <DataTable label="Productos vendidos sin catálogo">
            <thead>
              <tr>
                <th className={th}>Compañía</th><th className={th}>Código</th><th className={th}>Producto (según ventas)</th>
                <Th info={{ text: 'Categoría que trae el archivo de ventas para el producto; como no está en el catálogo, se toma de las ventas.' }}>Categoría (según ventas)</Th><Th right info={{ text: 'Venta neta del producto en los últimos 12 meses.' }}>Venta 12 meses</Th>
              </tr>
            </thead>
            <tbody>
              {missing.loading && !missing.data && <TableSkeleton cols={5} />}
              {!missing.loading && missingRows.length === 0 && <EmptyRow cols={5}>Todos los productos vendidos están en el catálogo.</EmptyRow>}
              {missingRows.map((r) => (
                <tr key={`${r.company_id}|${r.code}`} className={tr}>
                  <td className={td}>{r.company}</td>
                  <td className={`${td} tabular`}>{r.code}</td>
                  <td className={`${td} font-medium`}>{r.name}</td>
                  <td className={td}>{r.category || '—'}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <Pager page={page} setPage={setPage} hasMore={!!missing.data?.meta.has_more} loading={missing.loading} />
        </>
      )}
    </section>
  )
}
