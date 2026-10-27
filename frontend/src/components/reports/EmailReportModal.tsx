import { CircleAlert, CircleCheck } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { api } from '../../lib/api'
import { parseRecipients } from '../../lib/recipients'
import type { ReportFilters } from '../../lib/useReportFilters'
import { useFormValues } from '../../lib/useFormValues'
import { useSubmit } from '../../lib/useSubmit'
import { Alert, Button, CheckField, Modal, SelectField, TextAreaField } from '../ui'
import type { ReportKey } from './ReportActions'

const MAX_RECIPIENTS = 10

interface Result {
  to: string
  status: string
  error: string | null
}

/**
 * Enviar un reporte por correo en dos pasos: primero se escriben las direcciones y el mensaje; luego se revisan una por una y se confirma
 * que esas personas pueden recibir las cifras. Es lo único irreversible de los reportes: las cifras salen de la empresa.
 */
export default function EmailReportModal({ report, filters, extra, onClose }: { report: ReportKey; filters: ReportFilters; extra?: Record<string, string>; onClose: () => void }) {
  const { values: v, set } = useFormValues({ recipients: '', format: 'xlsx', message: '', confirmed: false })
  const { errors, saving, run } = useSubmit()
  const [step, setStep] = useState<'write' | 'confirm' | 'done'>('write')
  const [results, setResults] = useState<Result[]>([])
  const parsed = useMemo(() => parseRecipients(v.recipients), [v.recipients])
  const tooMany = parsed.valid.length > MAX_RECIPIENTS
  const canReview = parsed.valid.length > 0 && parsed.invalid.length === 0 && !tooMany

  async function send(e: FormEvent) {
    e.preventDefault()
    const ok = await run(async () => {
      const query = Object.fromEntries(new URLSearchParams(filters.query(extra)))
      const res = await api<{ data: { results: Result[] } }>(`/reports/${report}/email`, {
        method: 'POST',
        body: { ...query, format: v.format, recipients: parsed.valid, message: v.message || null, confirmed: v.confirmed },
      })
      setResults(res.data.results)
    }, 'recipients')
    if (ok) setStep('done')
  }

  if (step === 'done') {
    const failed = results.filter((r) => r.status !== 'sent')
    return (
      <Modal title="Reporte enviado" onClose={onClose} extraWide>
        <ul className="mb-4 space-y-2 text-sm">
          {results.map((r) => (
            <li key={r.to} className="flex items-start gap-2">
              {r.status === 'sent'
                ? <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-success" aria-hidden="true" />
                : <CircleAlert className="mt-0.5 h-4 w-4 shrink-0 text-danger" aria-hidden="true" />}
              <span><span className="font-medium">{r.to}</span>: {r.status === 'sent' ? 'enviado' : `no se pudo enviar${r.error ? ` (${r.error})` : ''}`}</span>
            </li>
          ))}
        </ul>
        {failed.length > 0 && <div className="mb-4"><Alert tone="warning">{failed.length === 1 ? 'Un envío falló' : `${failed.length} envíos fallaron`}. Quedaron en el registro de correos; revisa la configuración del correo.</Alert></div>}
        <div className="flex justify-end"><Button onClick={onClose}>Cerrar</Button></div>
      </Modal>
    )
  }

  return (
    <Modal title="Enviar el reporte por correo" onClose={onClose} extraWide>
      {step === 'write' ? (
        <form onSubmit={(e) => { e.preventDefault(); if (canReview) setStep('confirm') }} className="space-y-4" noValidate>
          <TextAreaField
            id="mail-recipients"
            label="Para"
            rows={3}
            value={v.recipients}
            onChange={(e) => set('recipients', e.target.value)}
            hint={`Una o varias direcciones, separadas por coma, espacio o salto de línea (máximo ${MAX_RECIPIENTS}). Cada persona recibe su propio correo.`}
            errors={parsed.invalid.length > 0 ? [`No es una dirección válida: ${parsed.invalid.join(', ')}`] : tooMany ? [`Máximo ${MAX_RECIPIENTS} destinatarios por envío.`] : undefined}
          />
          <SelectField id="mail-format" label="Archivo adjunto" value={v.format} onChange={(e) => set('format', e.target.value)}>
            <option value="xlsx">Excel</option>
            <option value="pdf">PDF</option>
          </SelectField>
          <TextAreaField id="mail-message" label="Mensaje (opcional)" rows={3} value={v.message} maxLength={1000} onChange={(e) => set('message', e.target.value)} />
          <p className="text-xs text-ink-muted">Siguiente: revisarás los destinatarios antes de enviar.</p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" type="button" onClick={onClose}>Cancelar</Button>
            <Button type="submit" disabled={!canReview}>Revisar destinatarios</Button>
          </div>
        </form>
      ) : (
        <form onSubmit={(e) => void send(e)} className="space-y-4" noValidate>
          <Alert tone="warning">Las cifras de ventas saldrán de la empresa y el envío no se puede deshacer. Revisa a quién va.</Alert>
          <section aria-label="Destinatarios">
            <p className="mb-1 text-sm font-medium">Se enviará {v.format === 'pdf' ? 'el PDF' : 'el Excel'} a:</p>
            <ul className="list-inside list-disc text-sm">{parsed.valid.map((a) => <li key={a}>{a}</li>)}</ul>
          </section>
          <CheckField label={`Confirmo que ${parsed.valid.length === 1 ? 'esta persona puede recibir' : 'estas personas pueden recibir'} estas cifras`} checked={v.confirmed} onChange={(e) => set('confirmed', e.target.checked)} />
          {Object.entries(errors).map(([field, messages]) => messages.map((m) => <Alert key={`${field}-${m}`} tone="danger">{m}</Alert>))}
          <div className="flex justify-between gap-2">
            <Button variant="secondary" type="button" onClick={() => setStep('write')}>Volver</Button>
            <Button type="submit" loading={saving} disabled={!v.confirmed}>Enviar ahora</Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
