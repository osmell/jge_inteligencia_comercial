import { Download } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Button, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { download } from '../lib/api'
import { currentMonthRange, formatDate, profileUrl, type CustomerSalesRow, type ReportPerson } from '../lib/customers'
import { formatMoney } from '../lib/format'
import type { Branch, Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

function People({ list, more }: { list: ReportPerson[]; more: number }) {
  if (list.length === 0) return <>—</>
  return (
    <>
      {list.map((p) => <span key={p.name} className="block">{p.name} <span className="tabular text-xs text-ink-muted">{formatMoney(p.net_total)}</span></span>)}
      {more > 0 && <span className="block text-xs text-ink-muted">y {more} más (completo en el archivo)</span>}
    </>
  )
}

export default function CustomerSalesReportPage() {
  const initial = currentMonthRange()
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [page, setPage] = useState(1)
  const [exporting, setExporting] = useState<'csv' | 'xlsx' | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)

  const validRange = /^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to) && from <= to
  const filters = new URLSearchParams({ from, to, ...(companyId ? { company_id: companyId } : {}), ...(branchId ? { branch_id: branchId } : {}) }).toString()
  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const report = useLoad<{ data: CustomerSalesRow[]; meta: { has_more: boolean } }>(`/customers/sales-report?${filters}&page=${page}`, { enabled: validRange })
  const rows = report.data?.data ?? []

  async function exportFile(format: 'csv' | 'xlsx') {
    setExporting(format)
    setExportError(null)
    try {
      await download(`/customers/sales-report/export?${filters}&format=${format}`, `ventas-por-cliente-${from}_${to}.${format}`)
    } catch (e) {
      setExportError(e instanceof Error ? e.message : 'No se pudo exportar el reporte.')
    } finally {
      setExporting(null)
    }
  }

  const reset = <T,>(set: (v: T) => void) => (v: T) => {
    set(v)
    setPage(1)
  }

  return (
    <section>
      <PageHeader
        title="Ventas por cliente"
        description="Venta neta de cada cliente en el periodo, con quién le vendió y quién le facturó."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver a clientes</Link>}
      />

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-40"><TextField id="report-from" label="Desde" type="date" value={from} onChange={(e) => reset(setFrom)(e.target.value)} /></div>
        <div className="w-40"><TextField id="report-to" label="Hasta" type="date" value={to} onChange={(e) => reset(setTo)(e.target.value)} /></div>
        <SelectField id="report-company" label="Compañía" value={companyId} onChange={(e) => { reset(setCompanyId)(e.target.value); setBranchId('') }}>
          <option value="">Todas</option>
          {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        <SelectField id="report-branch" label="Sucursal" value={branchId} onChange={(e) => reset(setBranchId)(e.target.value)}>
          <option value="">Todas</option>
          {(branches.data?.data ?? []).filter((b) => !companyId || String(b.company_id) === companyId).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </SelectField>
        <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'xlsx'} disabled={!validRange || exporting !== null} onClick={() => void exportFile('xlsx')}>Exportar a Excel</Button>
        <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'csv'} disabled={!validRange || exporting !== null} onClick={() => void exportFile('csv')}>Exportar a CSV</Button>
      </div>

      {!validRange && <div className="mb-3"><Alert tone="warning">Indica un periodo válido: la fecha inicial no puede ser posterior a la final.</Alert></div>}
      <div className="mb-3"><Alert tone="info">Una venta es del vendedor; si vendedor y facturador tienen el mismo nombre, es una venta directa del facturador. Las devoluciones aún no se descuentan.</Alert></div>
      <FormError message={exportError ?? report.error} />
      <DataTable label="Ventas por cliente">
        <thead>
          <tr>
            <th className={th}>Cliente</th>
            <th className={th}>Vendedor asignado</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Documentos</th>
            <th className={th}>Última compra</th>
            <th className={th}>Quién vendió</th>
            <th className={th}>Quién facturó</th>
            <th className={`${th} text-right`}>Venta directa del facturador</th>
          </tr>
        </thead>
        <tbody>
          {validRange && report.loading && !report.data && <TableSkeleton cols={8} />}
          {validRange && !report.loading && rows.length === 0 && <EmptyRow cols={8}>No hay ventas en el periodo.</EmptyRow>}
          {rows.map((r) => (
            <tr key={`${r.company_id}|${r.code}`} className={tr}>
              <td className={`${td} font-medium`}>
                <Link className="text-jge-blue hover:underline" to={profileUrl(r.code, r.company_id)}>{r.name}</Link>
                <span className="block text-xs font-normal text-ink-muted">{r.company} · {r.code}{r.type ? ` · ${r.type}` : ''}</span>
              </td>
              <td className={td}>
                {r.assigned_seller ?? '—'}
                {r.assigned_seller_is_default && <span className="block text-xs text-ink-muted">por defecto</span>}
                {r.supervisor && <span className="block text-xs text-ink-muted">Supervisor: {r.supervisor}</span>}
              </td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
              <td className={`${td} tabular text-right`}>{r.documents}</td>
              <td className={td}>{formatDate(r.last_purchase)}</td>
              <td className={td}><People list={r.sellers} more={r.sellers_more} /></td>
              <td className={td}><People list={r.operators} more={r.operators_more} /></td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.direct_net_total)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
        <span>Página {page}</span>
        <div className="flex gap-2">
          <Button variant="secondary" disabled={page === 1 || report.loading} onClick={() => setPage((p) => p - 1)}>Anterior</Button>
          <Button variant="secondary" disabled={!report.data?.meta.has_more || report.loading} onClick={() => setPage((p) => p + 1)}>Siguiente</Button>
        </div>
      </div>
    </section>
  )
}
