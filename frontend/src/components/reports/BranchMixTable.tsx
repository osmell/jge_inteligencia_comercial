import { ArrowDown, ArrowUp } from 'lucide-react'
import type { BranchMix } from '../../lib/reports'
import { formatPct } from '../../lib/sales'
import { DataTable, td, th, tr } from '../ui'

/** Índice de venta de una categoría en una sucursal frente al conjunto: 100 = igual, 200 = el doble, 50 = la mitad. */
const HIGH = 150
const LOW = 50

function Cell({ share, index }: { share: number; index: number | null }) {
  const high = index !== null && index >= HIGH
  const low = index !== null && index <= LOW
  return (
    <td className={`${td} text-right ${high ? 'bg-jge-blue/10' : low ? 'bg-warning/10' : ''}`}>
      <span className="tabular">{formatPct(share)}</span>
      <span className="ml-1.5 inline-flex items-center gap-0.5 text-xs text-ink-muted">
        {high && <ArrowUp className="h-3 w-3 text-jge-blue" aria-hidden="true" />}
        {low && <ArrowDown className="h-3 w-3 text-warning" aria-hidden="true" />}
        <span className="tabular">{index ?? '—'}</span>
        {high && <span className="sr-only">vende más que el conjunto</span>}
        {low && <span className="sr-only">vende menos que el conjunto</span>}
      </span>
    </td>
  )
}

/** Categorías en filas y sucursales en columnas: qué parte de la venta de cada sucursal es de cada categoría y cómo se compara con el conjunto. */
export default function BranchMixTable({ mix }: { mix: BranchMix }) {
  return (
    <section aria-labelledby="mix-title" className="mt-8">
      <h2 id="mix-title" className="text-sm font-semibold">Mezcla de categorías por sucursal</h2>
      <p className="mb-2 mt-1 text-xs text-ink-muted">
        Cada celda: % de la venta de la sucursal que es de esa categoría y su índice frente al conjunto (100 = igual; ▲ {HIGH} o más: vende más de lo esperado; ▼ {LOW} o menos: vende menos).
        {mix.total_categories > mix.shown && ` Se muestran las ${mix.shown} categorías con más venta de ${mix.total_categories}.`}
      </p>
      <DataTable label="Mezcla de categorías por sucursal">
        <thead>
          <tr>
            <th className={th}>Categoría</th>
            <th className={`${th} text-right`}>% del total</th>
            {mix.branches.map((b) => <th key={b.id} className={`${th} text-right`}>{b.name}</th>)}
          </tr>
        </thead>
        <tbody>
          {mix.categories.map((c) => (
            <tr key={c.name} className={tr}>
              <td className={`${td} font-medium`}>{c.name}</td>
              <td className={`${td} tabular text-right`}>{formatPct(c.share_pct)}</td>
              {c.cells.map((cell) => <Cell key={cell.branch_id} share={cell.share_pct} index={cell.index} />)}
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
