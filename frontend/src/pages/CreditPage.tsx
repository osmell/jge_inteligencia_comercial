import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr, Stat } from '../components/ui'
import { bucketLabel, type CreditList } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const STATUS = [['open', 'Con saldo'], ['paid', 'Pagadas'], ['all', 'Todas']] as const

export default function CreditPage() {
  const [params] = useSearchParams()
  const [text, setText] = useState(params.get('q') ?? '')
  const [term, setTerm] = useState((params.get('q') ?? '').trim())
  const [companyId, setCompanyId] = useState(params.get('company_id') ?? '')
  const [status, setStatus] = useState<'open' | 'paid' | 'all'>('open')
  const [bucket, setBucket] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    const t = setTimeout(() => { setTerm(text.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [text])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const query = new URLSearchParams({ status, page: String(page) })
  if (term) query.set('q', term)
  if (companyId) query.set('company_id', companyId)
  if (bucket) query.set('bucket', bucket)
  const list = useLoad<CreditList>(`/credit/invoices?${query}`)
  const meta = list.data?.meta
  const rows = list.data?.data ?? []

  return (
    <section>
      <PageHeader title="Crédito y cobranzas" description="Facturas de crédito y su saldo. El saldo se calcula desde los movimientos; la antigüedad se cuenta desde la fecha de la factura." action={<Link className="text-sm text-jge-blue hover:underline" to="/cartera">Antigüedad de cartera por cliente →</Link>} />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2" aria-busy={list.loading}>
        <Stat label="Saldo por cobrar" value={meta ? formatMoney(meta.open_balance) : '—'} hint="Facturas con saldo, según la compañía y la búsqueda elegidas" />
        <Stat label="Facturas con saldo" value={meta ? meta.open_count.toLocaleString('es') : '—'} />
      </div>

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SearchInput label="Buscar por documento o cliente" value={text} onChange={setText} placeholder="Documento o cliente" />
          <SelectField id="credit-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="credit-status" label="Estado" value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setBucket(''); setPage(1) }}>
            {STATUS.map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </SelectField>
          <SelectField id="credit-bucket" label="Antigüedad" value={bucket} onChange={(e) => { setBucket(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {(meta?.buckets ?? []).map((b) => <option key={b} value={b}>{bucketLabel(b)}</option>)}
          </SelectField>
        </div>
      </Card>

      <FormError message={list.error} />

      <DataTable label="Facturas de crédito">
        <thead>
          <tr>
            <th className={th}>Documento</th><th className={th}>Fecha</th><th className={th}>Cliente</th><th className={th}>Vendedor</th>
            <th className={`${th} text-right`}>Total</th><th className={`${th} text-right`}>Saldo</th><th className={th}>Antigüedad</th>
          </tr>
        </thead>
        <tbody>
          {list.loading && !list.data ? <TableSkeleton cols={7} /> : rows.length === 0 ? (
            <EmptyRow cols={7}>No hay facturas de crédito con estos filtros.</EmptyRow>
          ) : rows.map((r) => (
            <tr key={r.id} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/credito/${r.id}`}>{r.document}</Link>
                <div className="text-xs text-ink-muted">{r.company}</div>
              </td>
              <td className={td}>{formatDay(r.issued_on)}</td>
              <td className={td}>{r.customer.name}<div className="text-xs text-ink-muted">{r.customer.code}</div></td>
              <td className={td}>{r.seller}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(r.total)}</td>
              <td className={`${td} tabular text-right font-medium`}>{formatMoney(r.balance)}</td>
              <td className={td}>
                {r.bucket === null ? 'Pagada' : bucketLabel(r.bucket)}
                {r.bucket !== null && r.bucket !== 'current' && <div className="text-xs text-ink-muted">{r.age_days} días desde la factura</div>}
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {(page > 1 || meta?.has_more) && (
        <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
          <span>Página {page}</span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</Button>
            <Button variant="secondary" disabled={!meta?.has_more} onClick={() => setPage(page + 1)}>Siguiente</Button>
          </div>
        </div>
      )}
    </section>
  )
}
