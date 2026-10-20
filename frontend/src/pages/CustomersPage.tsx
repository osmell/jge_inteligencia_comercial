import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Button, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { formatMoney } from '../lib/format'
import { ABC_HINT, ACTIVITY_LABEL, SORT_LABEL, formatDate, profileUrl, type CustomerListRow, type CustomerSort, type ActivityStatus, type CustomerType } from '../lib/customers'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

export default function CustomersPage() {
  const { can } = useAuth()
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [typeId, setTypeId] = useState('')
  const [status, setStatus] = useState('')
  const [sort, setSort] = useState<CustomerSort>('last_purchase')
  const [page, setPage] = useState(1)

  // La búsqueda espera a que termines de escribir.
  useEffect(() => {
    const t = setTimeout(() => {
      setTerm(text.trim())
      setPage(1)
    }, 300)
    return () => clearTimeout(t)
  }, [text])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const types = useLoad<{ data: CustomerType[] }>('/customers/types')
  const query = new URLSearchParams({ sort, page: String(page), ...(term ? { q: term } : {}), ...(companyId ? { company_id: companyId } : {}), ...(typeId ? { type_id: typeId } : {}), ...(status ? { status } : {}) })
  const list = useLoad<{ data: CustomerListRow[]; meta: { has_more: boolean } }>(`/customers?${query}`)
  const rows = list.data?.data ?? []

  return (
    <section>
      <PageHeader title="Clientes" description="Datos maestros de los clientes y su actividad de compra. El código es único por compañía."
        action={(
          <span className="flex gap-4 text-sm">
            <Link className="text-jge-blue hover:underline" to="/clientes/reporte">Ventas por cliente</Link>
            {can('customers.manage') && <Link className="text-jge-blue hover:underline" to="/clientes/vendedores">Vendedores</Link>}
            {can('customers.manage') && <Link className="text-jge-blue hover:underline" to="/clientes/facturadores">Facturadores</Link>}
            {can('customers.manage') && <Link className="text-jge-blue hover:underline" to="/clientes/tipos">Tipos de cliente</Link>}
          </span>
        )}
      />

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-72">
          <TextField id="customer-search" label="Buscar por código o nombre" type="search" value={text} onChange={(e) => setText(e.target.value)} />
        </div>
        <SelectField id="customer-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setPage(1) }}>
          <option value="">Todas</option>
          {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        <SelectField id="customer-type" label="Tipo" value={typeId} onChange={(e) => { setTypeId(e.target.value); setPage(1) }}>
          <option value="">Todos</option>
          {(types.data?.data ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}{t.active ? '' : ' (inactivo)'}</option>)}
        </SelectField>
        <SelectField id="customer-status" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Todos</option>
          {(Object.keys(ACTIVITY_LABEL) as ActivityStatus[]).map((k) => <option key={k} value={k}>{ACTIVITY_LABEL[k]}</option>)}
        </SelectField>
        <SelectField id="customer-sort" label="Ordenar por" value={sort} onChange={(e) => { setSort(e.target.value as CustomerSort); setPage(1) }}>
          {(Object.keys(SORT_LABEL) as CustomerSort[]).map((k) => <option key={k} value={k}>{SORT_LABEL[k]}</option>)}
        </SelectField>
      </div>

      <FormError message={list.error} />
      <DataTable label="Clientes">
        <thead>
          <tr>
            <th className={th}>Compañía</th>
            <th className={th}>Código</th>
            <th className={th}>Nombre</th>
            <th className={th}>Tipo</th>
            <th className={th}>Vendedor</th>
            <th className={th}>Estado</th>
            <th className={th}><abbr title="Clasificación ABC por venta de los últimos 12 meses">ABC</abbr></th>
            <th className={`${th} text-right`}>Documentos</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={th}>Primera compra</th>
            <th className={th}>Última compra</th>
          </tr>
        </thead>
        <tbody>
          {list.loading && !list.data && <TableSkeleton cols={11} />}
          {!list.loading && rows.length === 0 && <EmptyRow cols={11}>{term ? 'Ningún cliente coincide con la búsqueda.' : 'Aún no hay clientes. Cárgalos desde Importaciones.'}</EmptyRow>}
          {rows.map((r) => (
            <tr key={`${r.company_id}|${r.code}`} className={tr}>
              <td className={td}>{r.company}</td>
              <td className={`${td} tabular`}>{r.code}</td>
              <td className={`${td} font-medium`}>
                <Link className="text-jge-blue hover:underline" to={profileUrl(r.code, r.company_id)}>{r.name}</Link>
                {r.trade_name && <span className="block text-xs font-normal text-ink-muted">{r.trade_name}</span>}
              </td>
              <td className={td}>{r.type ?? '—'}</td>
              <td className={td}>{r.seller ? <>{r.seller}{r.seller_is_default && <span className="block text-xs text-ink-muted">por defecto</span>}</> : '—'}</td>
              <td className={td}>{ACTIVITY_LABEL[r.activity]}</td>
              <td className={td}>{r.abc ? <abbr title={ABC_HINT[r.abc]} className="no-underline">{r.abc}</abbr> : '—'}</td>
              <td className={`${td} tabular text-right`}>{r.documents}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
              <td className={td}>{r.first_purchase ? formatDate(r.first_purchase) : '—'}</td>
              <td className={td}>{r.last_purchase ? formatDate(r.last_purchase) : 'Sin compras'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
        <span>Página {page}</span>
        <div className="flex gap-2">
          <Button variant="secondary" disabled={page === 1 || list.loading} onClick={() => setPage((p) => p - 1)}>Anterior</Button>
          <Button variant="secondary" disabled={!list.data?.meta.has_more || list.loading} onClick={() => setPage((p) => p + 1)}>Siguiente</Button>
        </div>
      </div>
    </section>
  )
}
