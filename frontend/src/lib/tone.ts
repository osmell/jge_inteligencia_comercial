export type Tone = 'good' | 'warn' | 'bad' | 'neutral'

/** Estado a partir del avance contra la meta, con los umbrales ya usados en Metas (100 % cumplida, 75 % cerca). */
export function goalTone(pct: number | null): Tone {
  return pct === null ? 'neutral' : pct >= 100 ? 'good' : pct >= 75 ? 'warn' : 'bad'
}
