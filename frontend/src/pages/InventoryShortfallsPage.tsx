import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, td, th, tr, Th } from '../components/ui'
import { api } from '../lib/api'
import { formatDay } from '../lib/quotations'
import { SHORTFALL_REASON, type ShortfallList } from '../lib/inventory'
import type { Branch } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const num = (v: string) => Number(v).toLocaleString('es', { maximumFractionDigits: 8 })

/** Ventas que no pudieron descontar todo su inventario: se concilian al registrar la entrada o el ajuste que falta. */
export default function InventoryShortfallsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const manage = can('inventory.manage')
  const [status, setStatus] = useState<'open' | 'resolved' | 'all'>('open')
  const [branchId, setBranchId] = useState('')
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<number | 'sync' | null>(null)

  const branches = useLoad<{ data: Branch[] }>('/branches')
  const query = new URLSearchParams({ status, page: String(page) })
  if (branchId) query.set('branch_id', branchId)
  const list = useLoad<ShortfallList>(`/inventory/shortfalls?${query}`)
  const rows = list.data?.data ?? []

  async function reconcile(id: number) {
    setBusy(id)
    setError(null)
    try {
      const r = await api<{ data: { applied: string; missing: string; resolved: boolean } }>(`/inventory/shortfalls/${id}/reconcile`, { method: 'POST' })
      toast.success(r.data.resolved ? 'Faltante conciliado.' : r.data.applied === '0' ? 'Aún no hay existencias para aplicar.' : `Se aplicaron ${num(r.data.applied)}; faltan ${num(r.data.missing)}.`)
      list.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo conciliar.')
    } finally {
      setBusy(null)
    }
  }

  async function sync() {
    setBusy('sync')
    setError(null)
    try {
      await api('/inventory/sync-sales', { method: 'POST', body: {} })
      toast.success('Se revisarán las ventas pendientes en segundo plano.')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo iniciar la revisión.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <section>
      <PageHeader
        title="Faltantes por conciliar"
        description="Ventas ya facturadas que no pudieron descontar todo su inventario porque no hay existencias en negativo. Registra la entrada o el ajuste que falta y concilia."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inventario">← Existencias</Link>}
      />
      <div className="mb-4"><Alert tone="info">La venta no se modifica. Al conciliar se descuenta lo que haya disponible, con la fecha de hoy. Solo se descuentan ventas posteriores al inventario inicial de su sucursal.</Alert></div>

      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <SelectField id="sf-status" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1) }}>
            <option value="open">Pendientes</option><option value="resolved">Conciliados</option><option value="all">Todos</option>
          </SelectField>
          <SelectField id="sf-branch" label="Sucursal" value={branchId} onChange={(e) => { setBranchId(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {(branches.data?.data ?? []).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </SelectField>
          {manage && <Button variant="secondary" loading={busy === 'sync'} onClick={() => void sync()}>Revisar ventas pendientes</Button>}
        </div>
      </Card>

      <FormError message={list.error ?? error} />
      <DataTable label="Faltantes por conciliar">
        <thead>
          <tr>
            <Th info={{ text: 'Factura en la que se vendió más de lo que había en existencia.' }}>Venta</Th><th className={th}>Producto</th><th className={th}>Sucursal</th>
            <Th right info={{ text: 'Unidades que pedía la línea de venta.' }}>Vendido</Th><Th right info={{ text: 'Unidades que sí se pudieron descontar de la existencia.' }}>Descontado</Th><Th right info={{ text: 'Unidades vendidas sin existencia que las respaldara. Sigue abierto hasta que se resuelve.', formula: 'Falta = Vendido − Descontado' }}>Falta</Th><Th info={{ text: 'Por qué no se pudo descontar: existencias insuficientes, producto fuera del catálogo o producto sin unidad base.' }}>Motivo</Th>
            {manage && <th className={th}><span className="sr-only">Acciones</span></th>}
          </tr>
        </thead>
        <tbody>
          {list.loading && !list.data ? <TableSkeleton cols={manage ? 8 : 7} /> : rows.length === 0 ? (
            <EmptyRow cols={manage ? 8 : 7}>{status === 'resolved' ? 'No hay faltantes conciliados.' : 'No hay faltantes por conciliar.'}</EmptyRow>
          ) : rows.map((r) => (
            <tr key={r.id} className={tr}>
              <td className={td}>{r.document ?? '—'}<div className="text-xs text-ink-muted">{formatDay(r.issued_on)}</div></td>
              <td className={td}>{r.product.name ?? 'Sin nombre'}<div className="text-xs text-ink-muted">{r.product.code}</div></td>
              <td className={td}>{r.branch}</td>
              <td className={`${td} tabular text-right`}>{num(r.requested)}</td>
              <td className={`${td} tabular text-right`}>{num(r.applied)}</td>
              <td className={`${td} tabular text-right font-medium`}>{num(r.missing)}</td>
              <td className={td}>{r.resolved ? 'Conciliado' : SHORTFALL_REASON[r.reason]}</td>
              {manage && (
                <td className={td}>
                  {!r.resolved && <Button small variant="secondary" loading={busy === r.id} onClick={() => void reconcile(r.id)} aria-label={`Conciliar faltante de ${r.product.name ?? r.product.code} en ${r.document ?? 'la venta'}`}>Conciliar</Button>}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
    </section>
  )
}
