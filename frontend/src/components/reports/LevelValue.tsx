import { CircleAlert, CircleCheck, CircleMinus, TriangleAlert } from 'lucide-react'
import { asLevel, LEVEL_LABEL } from '../../lib/reports'

const STYLE: Record<ReturnType<typeof asLevel>, { icon: typeof CircleCheck; text: string }> = {
  good: { icon: CircleCheck, text: 'text-success' },
  warn: { icon: TriangleAlert, text: 'text-warning' },
  bad: { icon: CircleAlert, text: 'text-danger' },
  none: { icon: CircleMinus, text: 'text-ink-muted' },
}

/** Un valor con su semáforo. El nivel se distingue por el ícono y el texto, no solo por el color. */
export default function LevelValue({ level, children }: { level: string | undefined; children: React.ReactNode }) {
  const l = asLevel(level)
  const { icon: Icon, text } = STYLE[l]

  return (
    <span className={`inline-flex items-center justify-end gap-1.5 whitespace-nowrap ${text}`} title={LEVEL_LABEL[l]}>
      <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span className="tabular text-ink">{children}</span>
      <span className="sr-only">{LEVEL_LABEL[l]}</span>
    </span>
  )
}
