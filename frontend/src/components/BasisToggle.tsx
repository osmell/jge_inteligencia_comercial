import { InfoTip } from './InfoTip'
import { salesBasis, setSalesBasis, type SalesBasis } from '../lib/salesBasis'

const OPTIONS: { value: SalesBasis; label: string }[] = [
  { value: 'corrected', label: 'Corregidas' },
  { value: 'imported', label: 'Como se importaron' },
]

/** Elige con qué base se muestran las cifras de venta. Al cambiar se recarga la página para que todas las pantallas pidan los datos de nuevo. */
export default function BasisToggle() {
  const current = salesBasis()

  return (
    <div className="hidden items-center gap-1.5 lg:flex">
      <label htmlFor="sales-basis" className="text-xs font-medium text-ink-muted">Cifras de venta</label>
      <select
        id="sales-basis"
        value={current}
        onChange={(e) => {
          setSalesBasis(e.target.value as SalesBasis)
          window.location.reload()
        }}
        className="min-h-9 cursor-pointer rounded-lg border border-line-strong bg-surface px-2 text-sm text-ink"
      >
        {OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
      <InfoTip
        label="Cifras de venta"
        info={{
          text: 'Corregidas: las ventas por equivalencia (1/4 de galón…) se muestran con su precio real y las facturas anuladas no suman. Como se importaron: los importes del archivo tal cual, sin corregir.',
        }}
      />
    </div>
  )
}
