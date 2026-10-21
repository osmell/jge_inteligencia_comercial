import { Link } from 'react-router-dom'
import { formatDateTime } from '../lib/format'
import type { RfmRow, RfmRun } from '../lib/insights'
import { useLoad } from '../lib/useLoad'
import { Badge, Card } from './ui'

/** Segmento RFM del cliente en su ficha, con la explicación de por qué cayó ahí. */
export default function CustomerSegment({ companyId, code }: { companyId: string; code: string }) {
  const result = useLoad<{ data: RfmRow | null; meta: { run: RfmRun | null } }>(`/insights/rfm/customer?${new URLSearchParams({ company_id: companyId, code })}`)
  const row = result.data?.data
  const run = result.data?.meta.run
  if (!result.data) return null

  return (
    <section aria-labelledby="segment-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="segment-title" className="text-sm font-semibold text-ink">Segmento comercial (RFM)</h2>
        <Link className="text-sm text-jge-blue hover:underline" to="/inteligencia/segmentos">Ver segmentación</Link>
      </div>
      <Card className="p-4 text-sm">
        {row ? (
          <>
            <p><Badge>{row.segment_label}</Badge> <span className="tabular ml-2 text-ink-muted">R {row.r} · F {row.f} · V {row.m}</span></p>
            <p className="mt-2 text-ink-muted">{row.explanation}</p>
            {run && <p className="mt-1 text-xs text-ink-muted">Calculado el {formatDateTime(run.computed_at)}. Es distinto del scoring de crédito y de la clasificación ABC.</p>}
          </>
        ) : (
          <p className="text-ink-muted">{run?.status === 'ok' ? 'Este cliente no tuvo compras en el periodo analizado, así que no está segmentado.' : 'La segmentación todavía no está disponible para esta compañía.'}</p>
        )}
      </Card>
    </section>
  )
}
