import { COMPARE_MODE_LABEL, type CompareMode } from '../../lib/reports'
import { PRESET_LABEL, type Preset } from '../../lib/sales'
import type { ReportFilters as Filters } from '../../lib/useReportFilters'
import { SelectField, TextField } from '../ui'

/** Periodo, comparación, compañía y sucursal: la misma barra para todos los reportes de evaluación. */
export default function ReportFilters({ filters, comparison = true }: { filters: Filters; comparison?: boolean }) {
  const { values: v, set, setPreset, setCompany, context } = filters
  const branches = (context?.branches ?? []).filter((b) => !v.company_id || String(b.company_id) === v.company_id)

  return (
    <div className="mb-4 flex flex-wrap items-end gap-3 print:hidden">
      <SelectField id="report-preset" label="Periodo" value={v.preset} onChange={(e) => setPreset(e.target.value as Preset)}>
        {(Object.keys(PRESET_LABEL) as Preset[]).map((p) => <option key={p} value={p}>{PRESET_LABEL[p]}</option>)}
      </SelectField>
      <div className="w-40"><TextField id="report-from" label="Desde" type="date" value={v.from} onChange={(e) => { set('preset', 'custom'); set('from', e.target.value) }} /></div>
      <div className="w-40"><TextField id="report-to" label="Hasta" type="date" value={v.to} onChange={(e) => { set('preset', 'custom'); set('to', e.target.value) }} /></div>
      {comparison && (
        <>
          <SelectField id="report-compare" label="Comparar con" value={v.compare} onChange={(e) => set('compare', e.target.value as CompareMode)}>
            {(Object.keys(COMPARE_MODE_LABEL) as CompareMode[]).map((m) => <option key={m} value={m}>{COMPARE_MODE_LABEL[m]}</option>)}
          </SelectField>
          {v.compare === 'custom' && (
            <>
              <div className="w-40"><TextField id="report-compare-from" label="Comparar desde" type="date" value={v.compare_from} onChange={(e) => set('compare_from', e.target.value)} /></div>
              <div className="w-40"><TextField id="report-compare-to" label="Comparar hasta" type="date" value={v.compare_to} onChange={(e) => set('compare_to', e.target.value)} /></div>
            </>
          )}
        </>
      )}
      <SelectField id="report-company" label="Compañía" value={v.company_id} onChange={(e) => setCompany(e.target.value)}>
        <option value="">Todas</option>
        {(context?.companies ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
      </SelectField>
      <SelectField id="report-branch" label="Sucursal" value={v.branch_id} onChange={(e) => set('branch_id', e.target.value)}>
        <option value="">Todas</option>
        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
      </SelectField>
    </div>
  )
}
