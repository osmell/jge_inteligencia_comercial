import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Alert, Button, Card, CheckField, DataTable, EmptyRow, FormError, Modal, PageHeader, SearchInput, SelectField, TableSkeleton, TextAreaField, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { formatMoney } from '../lib/format'
import { STATUS_LABEL, formatDay, type QuotationDetail, type QuotationList, type QuotationStatus } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useDebounced } from '../lib/useDebounced'
import { useFilters } from '../lib/useFilters'
import { useLoad } from '../lib/useLoad'
import { useSubmit } from '../lib/useSubmit'

interface CustomerHit {
  company_id: number
  code: string
  name: string
}

function NewQuotation({ preset, onClose }: { preset: CustomerHit | null; onClose: () => void }) {
  const navigate = useNavigate()
  const [term, setTerm] = useState('')
  const [picked, setPicked] = useState(preset ? `${preset.company_id}|${preset.code}` : '')
  const [form, setForm] = useState({ amount: '', valid_until: '', description: '', external_reference: '', issue: false })
  const { errors, saving, run } = useSubmit()
  const debounced = useDebounced(term.trim())

  const hits = useLoad<{ data: CustomerHit[] }>(`/customers?q=${encodeURIComponent(debounced)}`, { enabled: !preset && debounced.length >= 2 })
  const options = preset ? [preset] : hits.data?.data ?? []

  async function save() {
    const [companyId, code] = picked.split('|')
    await run(async () => {
      const res = await api<{ data: QuotationDetail }>('/quotations', {
        method: 'POST',
        body: {
          company_id: Number(companyId), code, status: form.issue ? 'issued' : 'draft',
          amount: form.amount === '' ? null : form.amount.replace(',', '.'), valid_until: form.valid_until || null,
          description: form.description.trim() || null, external_reference: form.external_reference.trim() || null,
        },
      })
      navigate(`/cotizaciones/${res.data.id}`)
    }, 'code')
  }

  return (
    <Modal title="Nueva cotización" onClose={onClose} wide>
      <form onSubmit={(e) => { e.preventDefault(); void save() }} className="space-y-3">
        {preset ? (
          <p className="text-sm">Cliente: <span className="font-medium">{preset.name}</span> <span className="text-ink-muted">({preset.code})</span></p>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField id="q-search" label="Buscar cliente" value={term} onChange={(e) => setTerm(e.target.value)} hint="Escribe al menos 2 letras del nombre o el código." errors={errors.code} />
            <SelectField id="q-customer" label="Cliente" value={picked} onChange={(e) => setPicked(e.target.value)}>
              <option value="">{debounced.length < 2 ? 'Primero busca un cliente' : options.length === 0 ? 'Sin resultados' : 'Elige un cliente'}</option>
              {options.map((c) => <option key={`${c.company_id}|${c.code}`} value={`${c.company_id}|${c.code}`}>{c.name} ({c.code})</option>)}
            </SelectField>
          </div>
        )}
        <div className="grid gap-3 sm:grid-cols-3">
          <TextField id="q-amount" label="Importe" inputMode="decimal" value={form.amount} errors={errors.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          <TextField id="q-valid" label="Válida hasta" type="date" value={form.valid_until} errors={errors.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} />
          <TextField id="q-ref" label="Referencia externa (opcional)" value={form.external_reference} errors={errors.external_reference} maxLength={100} onChange={(e) => setForm({ ...form, external_reference: e.target.value })} />
        </div>
        <TextAreaField id="q-desc" label="Descripción (qué se cotiza)" value={form.description} errors={errors.description} maxLength={2000} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <CheckField label="Emitirla de una vez (exige importe y fecha de vencimiento)" checked={form.issue} onChange={(e) => setForm({ ...form, issue: e.target.checked })} />
        <FormError message={errors.status?.[0] ?? null} />
        <div className="flex gap-2">
          <Button type="submit" loading={saving} disabled={picked === ''}>Crear cotización</Button>
          <Button type="button" variant="secondary" onClick={onClose}>Cancelar</Button>
        </div>
      </form>
    </Modal>
  )
}

export default function QuotationsPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const { filters: f, page, set, setPage } = useFilters({ text: '', companyId: '', status: '' as QuotationStatus | '', mine: false })
  const [creating, setCreating] = useState(params.get('nueva') === '1')
  const term = useDebounced(f.text.trim())

  const companies = useLoad<{ data: Company[] }>('/companies')
  const query = new URLSearchParams({ page: String(page) })
  if (term) query.set('q', term)
  if (f.companyId) query.set('company_id', f.companyId)
  if (f.status) query.set('status', f.status)
  if (f.mine) query.set('mine', '1')
  const list = useLoad<QuotationList>(`/quotations?${query}`)
  const counts = list.data?.meta.counts ?? {}
  const total = Object.values(counts).reduce((n, c) => n + (c?.count ?? 0), 0)

  const preset: CustomerHit | null = params.get('codigo') && params.get('compania')
    ? { company_id: Number(params.get('compania')), code: params.get('codigo') ?? '', name: params.get('cliente') ?? params.get('codigo') ?? '' }
    : null

  function closeCreate() {
    setCreating(false)
    if (params.has('nueva')) setParams({}, { replace: true })
  }

  const tabs: [QuotationStatus | '', string, number][] = [['', 'Todas', total], ...(Object.keys(STATUS_LABEL) as QuotationStatus[]).map((s): [QuotationStatus, string, number] => [s, STATUS_LABEL[s], counts[s]?.count ?? 0])]

  return (
    <section>
      <PageHeader
        title="Cotizaciones"
        description="Cotizaciones a clientes, su estado y la factura que las convierte en venta."
        action={can('interactions.manage') ? <Button onClick={() => setCreating(true)}>Nueva cotización</Button> : undefined}
      />

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <SearchInput label="Buscar por número, referencia o cliente" value={f.text} onChange={(v) => set({ text: v })} placeholder="Número, referencia o cliente" />
          <SelectField id="quot-company" label="Compañía" value={f.companyId} onChange={(e) => set({ companyId: e.target.value })}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <div className="self-end"><CheckField label="Solo las que yo registré" checked={f.mine} onChange={(e) => set({ mine: e.target.checked })} /></div>
        </div>
        <div role="group" aria-label="Estado" className="mt-3 flex flex-wrap gap-1">
          {tabs.map(([key, label, n]) => (
            <button
              key={key || 'all'}
              type="button"
              aria-pressed={f.status === key}
              onClick={() => set({ status: key })}
              className={`min-h-9 cursor-pointer rounded-lg px-3 text-sm font-medium transition-colors ${f.status === key ? 'bg-jge-blue text-white' : 'text-ink-muted hover:bg-jge-blue-50 hover:text-jge-blue'}`}
            >
              {label} ({n})
            </button>
          ))}
        </div>
      </Card>

      <FormError message={list.error} />
      {f.status === 'accepted' && <div className="mb-3"><Alert tone="info">Una cotización aceptada todavía no es una venta: pasa a «Convertida en venta» cuando se vincula su factura.</Alert></div>}

      <DataTable label="Cotizaciones">
        <thead>
          <tr>
            <th className={th}>Número</th><th className={th}>Cliente</th><th className={`${th} text-right`}>Importe</th>
            <th className={th}>Válida hasta</th><th className={th}>Estado</th><th className={th}>Vendedor</th>
          </tr>
        </thead>
        <tbody>
          {list.loading && !list.data ? <TableSkeleton cols={6} /> : (list.data?.data ?? []).length === 0 ? (
            <EmptyRow cols={6}>No hay cotizaciones con estos filtros.</EmptyRow>
          ) : (list.data?.data ?? []).map((q) => (
            <tr key={q.id} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/cotizaciones/${q.id}`}>{q.number}</Link>
                {q.external_reference && <div className="text-xs text-ink-muted">Ref. {q.external_reference}</div>}
              </td>
              <td className={td}>{q.customer.name}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(q.amount)}</td>
              <td className={td}>{q.valid_until ? formatDay(q.valid_until) : '—'}</td>
              <td className={td}>
                {STATUS_LABEL[q.effective_status]}
                {q.document && <div className="text-xs text-ink-muted">Factura {q.document.number}</div>}
              </td>
              <td className={td}>{q.seller ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {(page > 1 || list.data?.meta.has_more) && (
        <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
          <span>Página {page}</span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</Button>
            <Button variant="secondary" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</Button>
          </div>
        </div>
      )}

      {creating && <NewQuotation preset={preset} onClose={closeCreate} />}
    </section>
  )
}
