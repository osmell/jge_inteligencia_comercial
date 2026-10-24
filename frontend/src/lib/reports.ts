import type { paths } from './api-types'
import type { Tone } from './tone'
import { presetRange, type Preset } from './sales'

/** Nivel de un semáforo de evaluación: lo calcula el servidor con los umbrales de `config/reports.php`. */
export type Level = 'good' | 'warn' | 'bad' | 'none'

export const LEVEL_LABEL: Record<Level, string> = { good: 'Bien', warn: 'Atención', bad: 'Crítico', none: 'Sin dato' }

export const LEVEL_TONE: Record<Level, Tone> = { good: 'good', warn: 'warn', bad: 'bad', none: 'neutral' }

export type CompareMode = 'previous' | 'last_year' | 'custom'

export const COMPARE_MODE_LABEL: Record<CompareMode, string> = {
  previous: 'Periodo anterior',
  last_year: 'Mismo periodo del año pasado',
  custom: 'Otro periodo',
}

export type ReportFilterValues = {
  preset: Preset
  from: string
  to: string
  compare: CompareMode
  compare_from: string
  compare_to: string
  company_id: string
  branch_id: string
}

export function initialReportFilters(): ReportFilterValues {
  return { preset: 'month', ...presetRange('month'), compare: 'previous', compare_from: '', compare_to: '', company_id: '', branch_id: '' }
}

const isDate = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v)

/** El periodo es utilizable: fechas completas, en orden, y —si la comparación es personalizada— también las suyas. */
export function reportFiltersValid(f: ReportFilterValues): boolean {
  if (!isDate(f.from) || !isDate(f.to) || f.from > f.to) return false
  return f.compare !== 'custom' || (isDate(f.compare_from) && isDate(f.compare_to) && f.compare_from <= f.compare_to)
}

export function reportQuery(f: ReportFilterValues, extra: Record<string, string> = {}): string {
  const params: Record<string, string> = { from: f.from, to: f.to, compare: f.compare, ...extra }
  if (f.compare === 'custom') {
    params.compare_from = f.compare_from
    params.compare_to = f.compare_to
  }
  if (f.company_id) params.company_id = f.company_id
  if (f.branch_id) params.branch_id = f.branch_id
  return new URLSearchParams(params).toString()
}

// Las respuestas se derivan del contrato generado (backend/openapi.json): si el servidor cambia una forma, esto deja de compilar.
type Body<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer B } } } } } ? B : never

export type ReportContext = Body<'/reports/context'>['data']
export type SellerReport = Body<'/reports/sellers'>['data']
export type SellerReportRow = SellerReport['rows'][number]
export type BillingDetail = Body<'/reports/sellers/{seller}/documents'>['data']
export type BillingDocument = BillingDetail['rows'][number]
export type OperatorReport = Body<'/reports/operators'>['data']
export type OperatorReportRow = OperatorReport['rows'][number]
export type OperatorDistribution = Body<'/reports/operators/{operator}/distribution'>['data']

/** El servidor manda el nivel como texto; cualquier valor desconocido se trata como «sin dato». */
export function asLevel(value: string | undefined): Level {
  return value === 'good' || value === 'warn' || value === 'bad' ? value : 'none'
}

export const REPORT_NOTE: Record<string, string> = {
  portfolio_and_credit_as_of_today: 'La cartera de clientes y la cobranza son a la fecha de hoy, no del periodo elegido.',
  goal_not_comparable_with_branch_filter: 'Con una sucursal elegida no se califica la meta: la venta es parcial y la meta es de toda la compañía.',
  uneven_comparison: 'El periodo de comparación dura distinto que el elegido: los cambios no son directamente comparables.',
  no_operator_cancellations: 'El sistema no sabe qué operador anuló o corrigió un documento: el control de captura se basa solo en lo que traen los archivos de venta (descuentos, líneas sin precio, documentos atípicos y negativos).',
}
