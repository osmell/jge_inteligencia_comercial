import { BAND_LABEL, type CreditScore } from '../lib/credit'
import { useLoad } from '../lib/useLoad'
import { FormError } from './ui'

/** Calificación de crédito explicable: puntuación total y cómo aporta cada factor. Orientativa. */
export default function CustomerCreditScore({ companyId, code }: { companyId: string; code: string }) {
  const result = useLoad<{ data: CreditScore }>(`/credit/score?${new URLSearchParams({ company_id: companyId, code })}`)
  const s = result.data?.data

  return (
    <div className="mt-3 border-t border-line pt-3">
      <h3 className="mb-1 text-sm font-semibold">Calificación de crédito</h3>
      <FormError message={result.error} />
      {!s ? <div className="skeleton h-12 w-full" aria-hidden="true" /> : (
        <>
          <p className="text-sm">
            {s.score === null ? 'Sin datos suficientes para calificar.' : (
              <><span className="tabular text-2xl font-semibold">{s.score}</span> de 100 · <span className="font-medium">{BAND_LABEL[s.band ?? 'high']}</span></>
            )}
          </p>
          <div className="mt-2 overflow-x-auto">
            <table className="w-full text-sm" aria-label="Factores de la calificación">
              <thead><tr className="text-left text-xs text-ink-muted"><th className="py-1 pr-3">Factor</th><th className="py-1 pr-3 text-right">Puntos</th><th className="py-1 pr-3 text-right">Peso aplicado</th><th className="py-1">Por qué</th></tr></thead>
              <tbody>
                {s.factors.map((f) => (
                  <tr key={f.key} className="border-t border-line align-top">
                    <td className="py-1.5 pr-3">{f.label}</td>
                    <td className="tabular py-1.5 pr-3 text-right">{f.score === null ? 'Sin datos' : f.score}</td>
                    <td className="tabular py-1.5 pr-3 text-right">{f.score === null ? '—' : `${String(f.effective_weight).replace('.', ',')} %`}</td>
                    <td className="py-1.5 text-ink-muted">{f.detail}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-1 text-xs text-ink-muted">Se mide en los últimos {s.window_days} días. Un factor sin datos no suma ni resta: su peso se reparte entre los demás. {s.disclaimer}</p>
        </>
      )}
    </div>
  )
}
