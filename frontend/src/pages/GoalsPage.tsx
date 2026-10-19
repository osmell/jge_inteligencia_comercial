import { Download, Pencil, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { api, download } from '../lib/api'
import { formatMoney } from '../lib/format'
import { STATUS_LABEL, STATUS_TONE, currentMonth, type GoalRow, type GoalsResponse, type Suggestion } from '../lib/goals'
import { formatPct } from '../lib/sales'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

interface Draft {
  amount: string
  days_off: string
  starts_on: string
  ends_on: string
  source: 'manual' | 'suggested'
}

const toDraft = (r: GoalRow): Draft => ({
  amount: r.goal_amount === null ? '' : String(r.goal_amount),
  days_off: String(r.days_off),
  starts_on: r.starts_on ?? '',
  ends_on: r.ends_on ?? '',
  source: r.source ?? 'manual',
})

function Progress({ pct }: { pct: number | null }) {
  const width = Math.min(100, Math.max(0, pct ?? 0))
  const tone = pct === null ? 'bg-line' : pct >= 100 ? 'bg-success' : pct >= 70 ? 'bg-warning' : 'bg-danger'
  return (
    <div className="flex items-center gap-2">
      <div className="h-2 w-24 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true">
        <div className={`h-full ${tone}`} style={{ width: `${width}%` }} />
      </div>
      <span className="tabular text-sm">{pct === null ? '—' : formatPct(pct)}</span>
    </div>
  )
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular mt-0.5 text-2xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

export default function GoalsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const canEdit = can('goals.manage')
  const [month, setMonth] = useState(currentMonth())
  const [companyId, setCompanyId] = useState('')
  const [drafts, setDrafts] = useState<Record<number, Draft> | null>(null)
  const [growth, setGrowth] = useState('0')
  const [months, setMonths] = useState('3')
  const [saving, setSaving] = useState(false)
  const [suggesting, setSuggesting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [exporting, setExporting] = useState<'csv' | 'pdf' | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)

  const validMonth = /^\d{4}-(0[1-9]|1[0-2])$/.test(month)
  const query = new URLSearchParams({ month, ...(companyId ? { company_id: companyId } : {}) }).toString()
  const companies = useLoad<{ data: Company[] }>('/companies')
  const goals = useLoad<GoalsResponse>(`/sales/goals?${query}`, { enabled: validMonth })

  const rows = (validMonth ? goals.data?.data : undefined) ?? []
  const meta = validMonth ? goals.data?.meta : undefined
  const totals = meta?.totals
  const editing = drafts !== null
  const behind = rows.filter((r) => r.behind_pace)

  async function exportFile(format: 'csv' | 'pdf') {
    setExporting(format)
    setExportError(null)
    try {
      await download(`/sales/goals/export?${query}&format=${format}`, `metas-${month}.${format}`)
    } catch (e) {
      setExportError(e instanceof Error ? e.message : 'No se pudo exportar las metas.')
    } finally {
      setExporting(null)
    }
  }

  function startEditing() {
    setFormError(null)
    setDrafts(Object.fromEntries(rows.map((r) => [r.seller_id, toDraft(r)])))
  }

  function change(id: number, patch: Partial<Draft>) {
    setDrafts((d) => (d ? { ...d, [id]: { ...d[id], ...patch } } : d))
  }

  async function applySuggestions() {
    if (!drafts) return
    setSuggesting(true)
    setFormError(null)
    try {
      const p = new URLSearchParams({ month, months, growth_pct: growth || '0', ...(companyId ? { company_id: companyId } : {}) })
      const res = await api<{ data: Suggestion[] }>(`/sales/goals/suggestions?${p}`)
      setDrafts((d) => {
        const next = { ...(d ?? {}) }
        for (const s of res.data) if (next[s.seller_id]) next[s.seller_id] = { ...next[s.seller_id], amount: String(s.suggested_amount), source: 'suggested' }
        return next
      })
      if (res.data.length === 0) setFormError('No hay ventas en los meses anteriores para sugerir metas.')
    } catch (e) {
      setFormError(e instanceof Error ? e.message : 'No se pudieron calcular las sugerencias.')
    } finally {
      setSuggesting(false)
    }
  }

  async function save() {
    if (!drafts) return
    setSaving(true)
    setFormError(null)
    try {
      await api('/sales/goals', {
        method: 'PUT',
        body: {
          month,
          goals: Object.entries(drafts).map(([id, d]) => ({
            seller_id: Number(id),
            amount: d.amount === '' ? null : Number(d.amount),
            days_off: Number(d.days_off || 0),
            source: d.source,
            starts_on: d.starts_on || null,
            ends_on: d.ends_on || null,
          })),
        },
      })
      setDrafts(null)
      toast.success('Metas guardadas.')
      goals.reload()
    } catch (e) {
      setFormError(e instanceof Error ? e.message : 'No se pudieron guardar las metas.')
    } finally {
      setSaving(false)
    }
  }

  const cols = editing ? 6 : 8

  return (
    <section>
      <PageHeader
        title="Metas de ventas"
        description="Meta mensual de venta neta (sin IVA) por vendedor. Se prorratea por alta, baja y ausencias."
        action={
          !editing && (
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'pdf'} disabled={!validMonth || rows.length === 0 || exporting !== null} onClick={() => void exportFile('pdf')}>
                Exportar a PDF
              </Button>
              <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporting === 'csv'} disabled={!validMonth || rows.length === 0 || exporting !== null} onClick={() => void exportFile('csv')}>
                Exportar a CSV
              </Button>
              {canEdit && (
                <Button variant="secondary" icon={<Pencil className="h-4 w-4" aria-hidden="true" />} disabled={!validMonth || rows.length === 0} onClick={startEditing}>
                  Editar metas
                </Button>
              )}
            </div>
          )
        }
      />

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <TextField id="goal-month" label="Mes" type="month" value={month} onChange={(e) => { setMonth(e.target.value); setDrafts(null) }} />
        <SelectField id="goal-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setDrafts(null) }}>
          <option value="">Todas</option>
          {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
      </div>

      {!validMonth && <Alert tone="warning">Indica un mes válido.</Alert>}
      <FormError message={validMonth ? goals.error : null} />
      <FormError message={exportError} />

      {totals && !editing && (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Meta efectiva" value={formatMoney(totals.effective_goal)} hint={`${totals.with_goal} de ${totals.sellers} vendedores con meta`} />
            <Stat label="Vendido" value={formatMoney(totals.actual)} hint="Solo vendedores con meta" />
            <Stat label="Cumplimiento" value={totals.compliance_pct === null ? '—' : formatPct(totals.compliance_pct)} />
            <Stat label="Semáforo" value={`${totals.met} · ${totals.warning} · ${totals.danger}`} hint="Cumplida · cerca · por debajo" />
          </div>
          {behind.length > 0 && (
            <div className="mb-4">
              <Alert tone="warning">
                Por debajo del ritmo esperado ({meta?.thresholds.pace_alert_pct} % de lo que debería llevar a hoy): {behind.map((r) => r.seller).join(', ')}.
              </Alert>
            </div>
          )}
        </>
      )}

      {editing && (
        <Card className="mb-4 p-4">
          <p className="mb-3 text-sm text-ink-muted">
            Las sugerencias usan el promedio de venta neta de los meses anteriores con ventas, más el crecimiento indicado. Puedes ajustarlas a mano antes de guardar.
          </p>
          <div className="flex flex-wrap items-end gap-3">
            <TextField id="goal-months" label="Meses previos" type="number" min={1} max={12} value={months} onChange={(e) => setMonths(e.target.value)} />
            <TextField id="goal-growth" label="Crecimiento %" type="number" step="0.1" value={growth} onChange={(e) => setGrowth(e.target.value)} />
            <Button variant="secondary" icon={<Sparkles className="h-4 w-4" aria-hidden="true" />} loading={suggesting} onClick={() => void applySuggestions()}>
              Aplicar sugerencias
            </Button>
          </div>
        </Card>
      )}

      <DataTable label="Metas por vendedor">
        <thead>
          {editing ? (
            <tr>
              <th className={th}>Vendedor</th>
              <th className={th}>Meta del mes</th>
              <th className={th}>Días de ausencia</th>
              <th className={th}>Alta</th>
              <th className={th}>Baja</th>
              <th className={th}>Origen</th>
            </tr>
          ) : (
            <tr>
              <th className={th}>Vendedor</th>
              <th className={`${th} text-right`}>Meta del mes</th>
              <th className={`${th} text-right`}>Meta efectiva</th>
              <th className={`${th} text-right`}>Vendido</th>
              <th className={th}>Cumplimiento</th>
              <th className={th}>Estado</th>
              <th className={`${th} text-right`}>Proyección</th>
              <th className={`${th} text-right`}>Ritmo</th>
            </tr>
          )}
        </thead>
        <tbody>
          {validMonth && goals.loading && !goals.data && <TableSkeleton cols={cols} />}
          {!goals.loading && rows.length === 0 && <EmptyRow cols={cols}>No hay vendedores con ventas ni metas en este mes.</EmptyRow>}
          {editing && drafts
            ? rows.map((r) => {
                const d = drafts[r.seller_id]
                return (
                  <tr key={r.seller_id} className={tr}>
                    <td className={`${td} font-medium`}>{r.seller}</td>
                    <td className={td}><TextField id={`amount-${r.seller_id}`} label={`Meta de ${r.seller}`} type="number" min={0} step="0.01" value={d.amount} onChange={(e) => change(r.seller_id, { amount: e.target.value, source: 'manual' })} /></td>
                    <td className={td}><TextField id={`off-${r.seller_id}`} label={`Días de ausencia de ${r.seller}`} type="number" min={0} max={31} value={d.days_off} onChange={(e) => change(r.seller_id, { days_off: e.target.value })} /></td>
                    <td className={td}><TextField id={`from-${r.seller_id}`} label={`Alta de ${r.seller}`} type="date" value={d.starts_on} onChange={(e) => change(r.seller_id, { starts_on: e.target.value })} /></td>
                    <td className={td}><TextField id={`to-${r.seller_id}`} label={`Baja de ${r.seller}`} type="date" value={d.ends_on} onChange={(e) => change(r.seller_id, { ends_on: e.target.value })} /></td>
                    <td className={`${td} text-sm text-ink-muted`}>{d.amount === '' ? '—' : d.source === 'suggested' ? 'Sugerida' : 'Manual'}</td>
                  </tr>
                )
              })
            : rows.map((r) => (
                <tr key={r.seller_id} className={tr}>
                  <td className={`${td} font-medium`}>
                    {r.seller}
                    {(r.starts_on || r.ends_on || r.days_off > 0) && (
                      <span className="block text-xs font-normal text-ink-muted">{r.eligible_days} de {r.days_in_month} días elegibles</span>
                    )}
                  </td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.goal_amount)}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.effective_goal)}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.actual)}</td>
                  <td className={td}><Progress pct={r.compliance_pct} /></td>
                  <td className={td}><span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_TONE[r.status]}`}>{STATUS_LABEL[r.status]}</span></td>
                  <td className={`${td} tabular text-right`}>{r.projected === null ? '—' : `${formatMoney(r.projected)} (${formatPct(r.projected_pct ?? 0)})`}</td>
                  <td className={`${td} tabular text-right ${r.behind_pace ? 'font-medium text-danger' : ''}`}>{r.pace_pct === null ? '—' : `${formatPct(r.pace_pct)}${r.behind_pace ? ' · atrasado' : ''}`}</td>
                </tr>
              ))}
        </tbody>
      </DataTable>

      {editing && (
        <div className="mt-4">
          <FormError message={formError} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" disabled={saving} onClick={() => setDrafts(null)}>Cancelar</Button>
            <Button loading={saving} onClick={() => void save()}>Guardar metas</Button>
          </div>
        </div>
      )}
    </section>
  )
}
