import { Download, Pencil, Sparkles } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { Alert, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { download } from '../lib/api'
import { formatMoney } from '../lib/format'
import { STATUS_LABEL, STATUS_TONE, currentMonth, type GoalsResponse } from '../lib/goals'
import { formatPct } from '../lib/sales'
import type { Company } from '../lib/types'
import { useExport } from '../lib/useExport'
import { useFormValues } from '../lib/useFormValues'
import { useGoalsEditor } from '../lib/useGoalsEditor'
import { useLoad } from '../lib/useLoad'

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
  const canEdit = can('goals.manage')
  const { values: f, set } = useFormValues({ month: currentMonth(), companyId: '' })
  const { month, companyId } = f
  const exporter = useExport<'csv' | 'pdf'>()

  const validMonth = /^\d{4}-(0[1-9]|1[0-2])$/.test(month)
  const query = new URLSearchParams({ month, ...(companyId ? { company_id: companyId } : {}) }).toString()
  const companies = useLoad<{ data: Company[] }>('/companies')
  const goals = useLoad<GoalsResponse>(`/sales/goals?${query}`, { enabled: validMonth })

  const rows = (validMonth ? goals.data?.data : undefined) ?? []
  const meta = validMonth ? goals.data?.meta : undefined
  const totals = meta?.totals
  const behind = rows.filter((r) => r.behind_pace)

  const editor = useGoalsEditor({ month, companyId, rows, onSaved: goals.reload })
  const { drafts, editing } = editor

  const exportFile = (format: 'csv' | 'pdf') => exporter.run(format, () => download(`/sales/goals/export?${query}&format=${format}`, `metas-${month}.${format}`), 'No se pudo exportar las metas.')

  const cols = editing ? 6 : 8

  return (
    <section>
      <PageHeader
        title="Metas de ventas"
        description="Meta mensual de venta neta (sin IVA) por vendedor. Se prorratea por alta, baja y ausencias."
        action={
          !editing && (
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporter.exporting === 'pdf'} disabled={!validMonth || rows.length === 0 || exporter.exporting !== null} onClick={() => void exportFile('pdf')}>
                Exportar a PDF
              </Button>
              <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} loading={exporter.exporting === 'csv'} disabled={!validMonth || rows.length === 0 || exporter.exporting !== null} onClick={() => void exportFile('csv')}>
                Exportar a CSV
              </Button>
              {canEdit && (
                <Button variant="secondary" icon={<Pencil className="h-4 w-4" aria-hidden="true" />} disabled={!validMonth || rows.length === 0} onClick={editor.start}>
                  Editar metas
                </Button>
              )}
            </div>
          )
        }
      />

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <TextField id="goal-month" label="Mes" type="month" value={month} onChange={(e) => { set('month', e.target.value); editor.cancel() }} />
        <SelectField id="goal-company" label="Compañía" value={companyId} onChange={(e) => { set('companyId', e.target.value); editor.cancel() }}>
          <option value="">Todas</option>
          {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
      </div>

      {!validMonth && <Alert tone="warning">Indica un mes válido.</Alert>}
      <FormError message={validMonth ? goals.error : null} />
      <FormError message={exporter.error} />

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
            <TextField id="goal-months" label="Meses previos" type="number" min={1} max={12} value={editor.months} onChange={(e) => editor.setMonths(e.target.value)} />
            <TextField id="goal-growth" label="Crecimiento %" type="number" step="0.1" value={editor.growth} onChange={(e) => editor.setGrowth(e.target.value)} />
            <Button variant="secondary" icon={<Sparkles className="h-4 w-4" aria-hidden="true" />} loading={editor.suggesting} onClick={() => void editor.applySuggestions()}>
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
                    <td className={td}><TextField id={`amount-${r.seller_id}`} label={`Meta de ${r.seller}`} type="number" min={0} step="0.01" value={d.amount} onChange={(e) => editor.change(r.seller_id, { amount: e.target.value, source: 'manual' })} /></td>
                    <td className={td}><TextField id={`off-${r.seller_id}`} label={`Días de ausencia de ${r.seller}`} type="number" min={0} max={31} value={d.days_off} onChange={(e) => editor.change(r.seller_id, { days_off: e.target.value })} /></td>
                    <td className={td}><TextField id={`from-${r.seller_id}`} label={`Alta de ${r.seller}`} type="date" value={d.starts_on} onChange={(e) => editor.change(r.seller_id, { starts_on: e.target.value })} /></td>
                    <td className={td}><TextField id={`to-${r.seller_id}`} label={`Baja de ${r.seller}`} type="date" value={d.ends_on} onChange={(e) => editor.change(r.seller_id, { ends_on: e.target.value })} /></td>
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
          <FormError message={editor.formError} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" disabled={editor.saving} onClick={editor.cancel}>Cancelar</Button>
            <Button loading={editor.saving} onClick={() => void editor.save()}>Guardar metas</Button>
          </div>
        </div>
      )}
    </section>
  )
}
