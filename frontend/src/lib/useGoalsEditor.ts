import { useState } from 'react'
import { useToast } from '../components/toast'
import { api } from './api'
import type { GoalRow, Suggestion } from './goals'

export interface Draft {
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

/** Edición de las metas del mes: borradores por vendedor, sugerencias a partir de meses anteriores y guardado de todo junto. */
export function useGoalsEditor({ month, companyId, rows, onSaved }: { month: string; companyId: string; rows: GoalRow[]; onSaved: () => void }) {
  const toast = useToast()
  const [drafts, setDrafts] = useState<Record<number, Draft> | null>(null)
  const [growth, setGrowth] = useState('0')
  const [months, setMonths] = useState('3')
  const [saving, setSaving] = useState(false)
  const [suggesting, setSuggesting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  function start() {
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
      onSaved()
    } catch (e) {
      setFormError(e instanceof Error ? e.message : 'No se pudieron guardar las metas.')
    } finally {
      setSaving(false)
    }
  }

  return { drafts, editing: drafts !== null, growth, setGrowth, months, setMonths, saving, suggesting, formError, start, cancel: () => setDrafts(null), change, applySuggestions, save }
}
