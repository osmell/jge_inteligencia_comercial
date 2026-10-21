import { ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { BarChart } from '../components/charts'
import CustomerCredit from '../components/CustomerCredit'
import CustomerWhatsapp from '../components/CustomerWhatsapp'
import CustomerAlerts from '../components/CustomerAlerts'
import CustomerCrossSell from '../components/CustomerCrossSell'
import CustomerSegment from '../components/CustomerSegment'
import CustomerInteractions from '../components/CustomerInteractions'
import CustomerQuotations from '../components/CustomerQuotations'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { ApiError, api } from '../lib/api'
import { formatMoney } from '../lib/format'
import { ABC_HINT, ACTIVITY_LABEL, formatDate, sinceLabel, type CustomerProfile, type CustomerType, type CustomerMaster, type OperatorRow, type SellerRow, type CustomerPurchase, type DocumentLine } from '../lib/customers'
import type { BreakdownRow } from '../lib/sales'
import type { Branch } from '../lib/types'
import { useLoad } from '../lib/useLoad'

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular mt-0.5 text-2xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

function TopTable({ title, label, rows }: { title: string; label: string; rows: BreakdownRow[] }) {
  return (
    <div>
      <h2 className="mb-2 text-sm font-semibold text-ink">{title}</h2>
      <DataTable label={title}>
        <thead><tr><th className={th}>{label}</th><th className={`${th} text-right`}>Venta neta</th><th className={`${th} text-right`}>Unidades</th></tr></thead>
        <tbody>
          {rows.length === 0 && <EmptyRow cols={3}>Sin datos.</EmptyRow>}
          {rows.map((r) => (
            <tr key={r.key} className={tr}>
              <td className={td}>{r.label}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.units)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </div>
  )
}

export default function CustomerProfilePage() {
  const { can } = useAuth()
  const toast = useToast()
  const [typeError, setTypeError] = useState<string | null>(null)
  const [draft, setDraft] = useState<Record<string, string> | null>(null)
  const [draftErrors, setDraftErrors] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState(false)
  const [params] = useSearchParams()
  const code = params.get('codigo') ?? ''
  const companyId = params.get('compania') ?? ''
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<Record<number, DocumentLine[] | 'loading' | 'error'>>({})

  const base = new URLSearchParams({ code, company_id: companyId })
  const ready = code !== '' && companyId !== ''
  const profile = useLoad<{ data: CustomerProfile }>(`/customers/profile?${base}`, { enabled: ready })
  const purchases = useLoad<{ data: CustomerPurchase[]; meta: { has_more: boolean } }>(`/customers/purchases?${base}&page=${page}`, { enabled: ready })

  const types = useLoad<{ data: CustomerType[] }>('/customers/types', { enabled: can('customers.manage') })
  const sellers = useLoad<{ data: SellerRow[] }>(`/customers/sellers?company_id=${companyId}`, { enabled: can('customers.manage') && companyId !== '' })
  const operators = useLoad<{ data: OperatorRow[] }>(`/customers/operators?company_id=${companyId}`, { enabled: can('customers.manage') && companyId !== '' })

  const branches = useLoad<{ data: Branch[] }>('/branches', { enabled: can('customers.manage') })

  function startEdit(c: CustomerMaster) {
    setDraftErrors({})
    setDraft({
      name: c.name, trade_name: c.trade_name ?? '', contact_name: c.contact_name ?? '', phone: c.phone ?? '', whatsapp: c.whatsapp ?? '',
      email: c.email ?? '', address: c.address ?? '', branch_id: c.branch_id === null ? '' : String(c.branch_id),
    })
  }

  async function saveEdit() {
    if (!draft) return
    setSaving(true)
    setDraftErrors({})
    const { branch_id, ...text } = draft
    try {
      await api('/customers/data', { method: 'PUT', body: { company_id: Number(companyId), code, ...text, branch_id: branch_id === '' ? null : Number(branch_id) } })
      toast.success('Datos del cliente actualizados.')
      setDraft(null)
      profile.reload()
    } catch (e) {
      setDraftErrors(e instanceof ApiError ? e.errors : { name: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
    } finally {
      setSaving(false)
    }
  }

  async function assign(patch: { seller_id?: number | null; operator_id?: number | null }) {
    if (!profile.data) return
    setTypeError(null)
    const a = profile.data.data.assignment
    try {
      await api('/customers/assignment', {
        method: 'PUT',
        body: { company_id: Number(companyId), code, seller_id: a.seller?.assigned ? a.seller.id : null, operator_id: a.operator?.id ?? null, ...patch },
      })
      toast.success('Asignación actualizada.')
      profile.reload()
    } catch (e) {
      setTypeError(e instanceof Error ? e.message : 'No se pudo actualizar la asignación.')
    }
  }

  async function changeType(value: string) {
    setTypeError(null)
    try {
      await api('/customers/type', { method: 'PUT', body: { company_id: Number(companyId), code, customer_type_id: value === '' ? null : Number(value) } })
      toast.success('Tipo de cliente actualizado.')
      profile.reload()
    } catch (e) {
      setTypeError(e instanceof Error ? e.message : 'No se pudo actualizar el tipo.')
    }
  }

  async function toggle(id: number) {
    if (open[id]) {
      setOpen(({ [id]: _closed, ...rest }) => rest)
      return
    }
    setOpen((o) => ({ ...o, [id]: 'loading' }))
    try {
      const res = await api<{ data: DocumentLine[] }>(`/customers/document?${base}&document_id=${id}`)
      setOpen((o) => ({ ...o, [id]: res.data }))
    } catch {
      setOpen((o) => ({ ...o, [id]: 'error' }))
    }
  }

  const p = profile.data?.data
  const rows = purchases.data?.data ?? []

  if (!ready) return <Alert tone="warning">Indica un cliente desde el <Link className="underline" to="/clientes">listado</Link>.</Alert>

  return (
    <section>
      <PageHeader
        title={p?.customer.name ?? 'Cliente'}
        description={p ? `${p.customer.company} · código ${p.customer.code}` : undefined}
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver al listado</Link>}
      />
      <FormError message={profile.error} />

      {p && (
        <>
          <Card className="mb-4 p-4">
            <h2 className="mb-2 text-sm font-semibold text-ink">Datos del cliente</h2>
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
              <div>
                <dt className="text-ink-muted">Estado</dt>
                <dd className="font-medium">
                  {ACTIVITY_LABEL[p.activity.status]}{' '}
                  <span className="text-xs font-normal text-ink-muted">(inactivo tras {p.activity.inactive_days} días sin comprar; perdido tras {p.activity.lost_days})</span>
                </dd>
              </div>
              <div>
                <dt className="text-ink-muted">Clasificación ABC</dt>
                <dd className="font-medium">
                  {p.customer.abc ? <>{p.customer.abc} <span className="text-xs font-normal text-ink-muted">{ABC_HINT[p.customer.abc]}</span></> : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-ink-muted">Vendedor</dt>
                <dd className="font-medium">
                  {p.assignment.seller?.name ?? '—'}
                  {p.assignment.seller && !p.assignment.seller.assigned && <span className="text-xs font-normal text-ink-muted"> (tienda por defecto)</span>}
                </dd>
              </div>
              {([
                ['Facturador', p.assignment.operator?.name],
                ['Supervisor', p.assignment.supervisor?.name],
                ['Tipo de cliente', p.customer.type?.name],
                ['Nombre comercial', p.customer.trade_name],
                ['Contacto', p.customer.contact_name],
                ['Sucursal', p.customer.branch],
                ['Teléfono', p.customer.phone],
                ['WhatsApp', p.customer.whatsapp],
                ['Correo', p.customer.email],
                ['Dirección', p.customer.address],
              ] as const).map(([label, value]) => (
                <div key={label}>
                  <dt className="text-ink-muted">{label}</dt>
                  <dd className="font-medium">{value || '—'}</dd>
                </div>
              ))}
            </dl>
            {can('customers.manage') && (
              <div className="mt-3 max-w-xs">
                <SelectField id="customer-type-edit" label="Cambiar tipo" value={p.customer.type?.id ?? ''} onChange={(e) => void changeType(e.target.value)}>
                  <option value="">Sin tipo</option>
                  {(types.data?.data ?? []).filter((t) => t.active || t.id === p.customer.type?.id).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </SelectField>
              </div>
            )}
            {can('customers.manage') && (
              <div className="mt-3 grid max-w-xl gap-3 sm:grid-cols-2">
                <SelectField id="customer-seller-edit" label="Asignar vendedor" value={p.assignment.seller?.assigned ? p.assignment.seller.id : ''} onChange={(e) => void assign({ seller_id: e.target.value === '' ? null : Number(e.target.value) })}>
                  <option value="">Tienda por defecto de la sucursal</option>
                  {(sellers.data?.data ?? []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </SelectField>
                <SelectField id="customer-operator-edit" label="Asignar facturador" value={p.assignment.operator?.id ?? ''} onChange={(e) => void assign({ operator_id: e.target.value === '' ? null : Number(e.target.value) })}>
                  <option value="">Sin facturador</option>
                  {(operators.data?.data ?? []).filter((o) => o.is_active || o.id === p.assignment.operator?.id).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </SelectField>
              </div>
            )}
            <FormError message={typeError} />
            {can('customers.manage') && (
              <div className="mt-4 border-t border-line pt-3">
                {draft === null ? (
                  <Button variant="secondary" onClick={() => startEdit(p.customer)}>Editar datos</Button>
                ) : (
                  <form onSubmit={(e) => { e.preventDefault(); void saveEdit() }} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                      {([
                        ['name', 'Nombre', 'text'], ['trade_name', 'Nombre comercial', 'text'], ['contact_name', 'Contacto', 'text'],
                        ['phone', 'Teléfono', 'tel'], ['whatsapp', 'WhatsApp', 'tel'], ['email', 'Correo', 'email'], ['address', 'Dirección', 'text'],
                      ] as const).map(([key, label, type]) => (
                        <TextField key={key} id={`edit-${key}`} label={label} type={type} value={draft[key]} errors={draftErrors[key]} onChange={(e) => setDraft({ ...draft, [key]: e.target.value })} />
                      ))}
                      <SelectField id="edit-branch_id" label="Sucursal" value={draft.branch_id} errors={draftErrors.branch_id} onChange={(e) => setDraft({ ...draft, branch_id: e.target.value })}>
                        <option value="">Sin sucursal</option>
                        {(branches.data?.data ?? []).filter((b) => String(b.company_id) === companyId).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                      </SelectField>
                    </div>
                    <p className="text-xs text-ink-muted">El código del cliente no se cambia: enlaza al cliente con sus ventas. Una importación de clientes posterior vuelve a escribir estos datos con lo que traiga el archivo.</p>
                    <div className="flex gap-2">
                      <Button type="submit" loading={saving} disabled={draft.name.trim() === ''}>Guardar cambios</Button>
                      <Button type="button" variant="secondary" onClick={() => setDraft(null)}>Cancelar</Button>
                    </div>
                  </form>
                )}
              </div>
            )}
            {p.customer.source === 'sales' && (
              <p className="mt-3 text-xs text-ink-muted">Este cliente se creó al aparecer en las ventas; aún no tiene datos del archivo maestro.</p>
            )}
          </Card>

          <CustomerInteractions companyId={companyId} code={code} />
          {can('interactions.manage') && <CustomerWhatsapp customerId={p.customer.id} />}
          <CustomerQuotations companyId={companyId} code={code} name={p.customer.name} />
          {can('insights.view') && <CustomerAlerts companyId={companyId} code={code} />}
          {can('insights.view') && <CustomerSegment companyId={companyId} code={code} />}
          {can('insights.view') && <CustomerCrossSell companyId={companyId} code={code} />}
          {can('credit.view') && <CustomerCredit companyId={companyId} code={code} name={p.customer.name} netTotal={p.totals.net_total} />}

          <div className="mb-4 space-y-2">
            {p.history_starts_on && (
              <Alert tone="info">
                Historial de ventas disponible desde el {formatDate(p.history_starts_on)}: las compras anteriores a esa fecha no aparecen en esta ficha.
              </Alert>
            )}
            {p.last_purchase === null && <Alert tone="info">Este cliente aún no tiene compras en las ventas importadas.</Alert>}
          </div>

          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
            <Stat label="Venta neta total" value={formatMoney(p.totals.net_total)} />
            <Stat label="Documentos" value={String(p.totals.documents)} />
            <Stat label="Ticket promedio" value={formatMoney(p.totals.average_ticket)} />
            <Stat
              label="Última compra"
              value={p.last_purchase ? formatDate(p.last_purchase) : '—'}
              hint={p.days_since_last_purchase === null ? undefined : sinceLabel(p.days_since_last_purchase)}
            />
            <Stat
              label="Frecuencia"
              value={p.frequency.average_days_between === null ? '—' : `cada ${String(p.frequency.average_days_between).replace('.', ',')} días`}
              hint={`${p.frequency.purchase_days} días con compra`}
            />
            <Stat
              label="Margen estimado"
              value={p.totals.estimated_margin === null ? '—' : formatMoney(p.totals.estimated_margin)}
              hint="Con costo actual, no histórico"
            />
          </div>

          <Card className="mb-4 p-4">
            <h2 className="mb-2 text-sm font-semibold text-ink">Venta neta de los últimos 12 meses</h2>
            <BarChart points={p.monthly.map((m) => ({ key: m.key, value: Number(m.net_total) }))} label="Venta neta mensual del cliente, últimos 12 meses" />
          </Card>

          <div className="mb-6 grid gap-4 lg:grid-cols-2">
            <TopTable title="Productos principales" label="Producto" rows={p.top_products} />
            <TopTable title="Categorías principales" label="Categoría" rows={p.top_categories} />
            <TopTable title="Vendedores que le han vendido" label="Vendedor" rows={p.sellers} />
            <TopTable title="Sucursales donde compra" label="Sucursal" rows={p.branches} />
          </div>
        </>
      )}

      <h2 className="mb-2 text-sm font-semibold text-ink">Historial de compras</h2>
      <FormError message={purchases.error} />
      <DataTable label="Compras del cliente">
        <thead>
          <tr>
            <th className={th}><span className="sr-only">Detalle</span></th>
            <th className={th}>Fecha</th>
            <th className={th}>Documento</th>
            <th className={th}>Sucursal</th>
            <th className={th}>Vendedor</th>
            <th className={th}>Facturó</th>
            <th className={`${th} text-right`}>Líneas</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Total facturado</th>
          </tr>
        </thead>
        <tbody>
          {purchases.loading && !purchases.data && <TableSkeleton cols={9} />}
          {!purchases.loading && rows.length === 0 && <EmptyRow cols={9}>Sin compras.</EmptyRow>}
          {rows.flatMap((r) => {
            const detail = open[r.id]
            return [
              <tr key={r.id} className={tr}>
                <td className={td}>
                  <button
                    type="button"
                    className="rounded p-1 hover:bg-surface-muted"
                    aria-expanded={!!detail}
                    aria-label={`${detail ? 'Ocultar' : 'Ver'} productos de ${r.document}`}
                    onClick={() => void toggle(r.id)}
                  >
                    <ChevronRight className={`h-4 w-4 transition-transform ${detail ? 'rotate-90' : ''}`} aria-hidden="true" />
                  </button>
                </td>
                <td className={td}>{formatDate(r.issued_on)}</td>
                <td className={`${td} tabular`}>{r.document}</td>
                <td className={td}>{r.branch}</td>
                <td className={td}>{r.seller}</td>
                <td className={td}>{r.operator}</td>
                <td className={`${td} tabular text-right`}>{r.lines}</td>
                <td className={`${td} tabular text-right`}>{formatMoney(r.net_total)}</td>
                <td className={`${td} tabular text-right`}>{formatMoney(r.grand_total)}</td>
              </tr>,
              detail && (
                <tr key={`${r.id}-lines`}>
                  <td className="border-b border-line bg-surface-muted px-4 py-3" colSpan={9}>
                    {detail === 'loading' ? (
                      <p className="text-sm text-ink-muted">Cargando productos…</p>
                    ) : detail === 'error' ? (
                      <p className="text-sm text-danger">No se pudo cargar el detalle de la factura.</p>
                    ) : (
                      <ul className="space-y-1 text-sm">
                        {detail.map((l) => (
                          <li key={l.line} className="flex flex-wrap justify-between gap-2">
                            <span>{l.product_name} <span className="text-ink-muted">({l.product_code} · {l.category})</span></span>
                            <span className="tabular">{formatMoney(l.units)} u. · {formatMoney(l.net_total)}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </td>
                </tr>
              ),
            ]
          })}
        </tbody>
      </DataTable>
      <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
        <span>Página {page}</span>
        <div className="flex gap-2">
          <Button variant="secondary" disabled={page === 1 || purchases.loading} onClick={() => setPage((n) => n - 1)}>Anterior</Button>
          <Button variant="secondary" disabled={!purchases.data?.meta.has_more || purchases.loading} onClick={() => setPage((n) => n + 1)}>Siguiente</Button>
        </div>
      </div>
    </section>
  )
}
