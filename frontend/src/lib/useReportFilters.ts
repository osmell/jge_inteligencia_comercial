import { initialReportFilters, reportFiltersValid, reportQuery, type ReportContext, type ReportFilterValues } from './reports'
import { presetRange, type Preset } from './sales'
import { useFormValues } from './useFormValues'
import { useLoad } from './useLoad'

/** Filtros compartidos de los reportes de evaluación: periodo, comparación, compañía y sucursal, y lo que la persona puede elegir. */
export function useReportFilters() {
  const { values, set, setValues } = useFormValues<ReportFilterValues>(initialReportFilters)
  const context = useLoad<{ data: ReportContext }>('/reports/context')

  function setPreset(preset: Preset) {
    setValues((v) => ({ ...v, preset, ...(preset === 'custom' ? {} : presetRange(preset)) }))
  }

  /** Al cambiar de compañía la sucursal elegida deja de aplicar. */
  function setCompany(companyId: string) {
    setValues((v) => ({ ...v, company_id: companyId, branch_id: '' }))
  }

  return {
    values,
    set,
    setPreset,
    setCompany,
    context: context.data?.data ?? null,
    contextError: context.error,
    valid: reportFiltersValid(values),
    query: (extra?: Record<string, string>) => reportQuery(values, extra),
  }
}

export type ReportFilters = ReturnType<typeof useReportFilters>
