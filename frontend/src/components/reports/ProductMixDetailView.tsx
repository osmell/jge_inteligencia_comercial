import { formatMoney } from '../../lib/format'
import { MIX_LEVEL_LABEL, type MixLevel, type MixSubject, type ProductMixDetail } from '../../lib/reports'
import { formatPct } from '../../lib/sales'
import { useLoad } from '../../lib/useLoad'
import { DataTable, EmptyRow, FormError, TableSkeleton, td, th, tr, Th } from '../ui'
import { ReportStat } from './ReportParts'

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('en-US'))
const signed = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${formatPct(v)}`)

const INDEX_HIGH = 150
const INDEX_LOW = 50

/** Índice de mezcla frente al equipo: 100 = igual proporción; arriba de 150 vende mucho más de lo esperado, abajo de 50 mucho menos. */
function MixIndex({ value }: { value: number | null }) {
  if (value === null) return <span className="text-ink-muted">—</span>
  const tone = value >= INDEX_HIGH ? 'font-semibold text-jge-blue' : value <= INDEX_LOW ? 'font-semibold text-warning' : ''
  return <span className={`tabular ${tone}`}>{value}{value >= INDEX_HIGH && <span className="sr-only"> (vende más que el equipo)</span>}{value <= INDEX_LOW && <span className="sr-only"> (vende menos que el equipo)</span>}</span>
}

function Gaps({ title, items, empty }: { title: string; items: ProductMixDetail['gaps']['categories']; empty: string }) {
  return (
    <section className="min-w-0">
      <h3 className="mb-1 text-sm font-semibold">{title}</h3>
      <DataTable label={title}>
        <thead><tr><th className={th}>Nombre</th><Th right info={{ text: 'Venta neta de esa categoría o línea en el equipo (compañeros del mismo tipo en la misma compañía), donde esta persona no vendió nada.' }}>Lo que vendió el equipo</Th><Th right info={{ text: 'Peso de esa categoría o línea en la venta del equipo.', formula: '% del equipo = Venta del equipo en la fila ÷ Venta neta del equipo × 100' }}>% del equipo</Th><Th right info="sales_customers">Clientes</Th></tr></thead>
        <tbody>
          {items.length === 0 && <EmptyRow cols={4}>{empty}</EmptyRow>}
          {items.map((g) => (
            <tr key={g.key} className={tr}>
              <td className={`${td} font-medium`}>{g.name}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(g.net_total)}</td>
              <td className={`${td} tabular text-right`}>{formatPct(g.share_pct)}</td>
              <td className={`${td} tabular text-right`}>{n(g.customers)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}

/**
 * Detalle de un vendedor u operador: lo que más factura (con su índice frente al equipo), lo que el equipo vende y esta persona no,
 * y cuáles de los productos estrella del equipo no vendió. `query` es el periodo y los filtros del reporte.
 */
export default function ProductMixDetailView({ subject, id, query, level }: { subject: MixSubject; id: number; query: string; level: MixLevel }) {
  const detail = useLoad<{ data: ProductMixDetail }>(`/reports/products/${subject === 'seller' ? 'sellers' : 'operators'}/${id}?${query}&level=${level}`)
  const d = detail.data?.data
  const s = d?.summary
  const who = subject === 'seller' ? 'vendedor' : 'operador'

  return (
    <>
      <FormError message={detail.error} />
      {s && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <ReportStat label="Venta neta" value={formatMoney(s.net_total)} hint={`${n(s.products_sold)} productos distintos`} />
          <ReportStat label="Categorías vendidas" value={`${s.categories_sold} de ${s.categories_catalog}`} hint={`cobertura ${formatPct(s.category_coverage_pct)}`} />
          <ReportStat label="Líneas vendidas" value={`${s.lines_sold} de ${s.lines_catalog}`} hint={`cobertura ${formatPct(s.line_coverage_pct)}`} />
          <ReportStat label="Top 5 productos" value={formatPct(s.top5_share_pct)} hint="de su venta sale de solo 5 productos" />
        </div>
      )}

      <h3 className="mb-1 text-sm font-semibold">Lo que más factura, por {MIX_LEVEL_LABEL[level].toLowerCase()}</h3>
      <p className="mb-2 text-xs text-ink-muted">Índice: 100 = pesa igual que en el equipo del {who}; ▲ {INDEX_HIGH} o más, vende mucho más de lo esperado; ▼ {INDEX_LOW} o menos, mucho menos.</p>
      <DataTable label={`Lo que más factura por ${MIX_LEVEL_LABEL[level].toLowerCase()}`}>
        <thead>
          <tr>
            <Th right info="rep_rank">#</Th><th className={th}>{MIX_LEVEL_LABEL[level]}</th><Th right info="sales_net">Venta neta</Th><Th right info={{ text: 'Peso de la fila en la venta de esta persona.', formula: '% de su venta = Venta neta de la fila ÷ Venta neta de la persona × 100' }}>% de su venta</Th>
            <Th right info={{ text: 'Peso de la misma fila en la venta del equipo.', formula: '% en el equipo = Venta del equipo en la fila ÷ Venta neta del equipo × 100' }}>% en el equipo</Th><Th right info={{ text: 'Qué tanto pesa la fila en esta persona frente al equipo. 100 = pesa igual; 200 = el doble; 50 = la mitad. Se marca desde 150 hacia arriba (▲) y desde 50 hacia abajo (▼).', formula: 'Índice = % de su venta ÷ % en el equipo × 100' }}>Índice</Th><Th right info="sales_vs_compared">Cambio</Th><Th right info="sales_customers">Clientes</Th>
          </tr>
        </thead>
        <tbody>
          {detail.loading && !d && <TableSkeleton cols={8} />}
          {d && d.ranking.items.length === 0 && <EmptyRow cols={8}>No tuvo venta en el periodo.</EmptyRow>}
          {d?.ranking.items.map((i) => (
            <tr key={i.key} className={tr}>
              <td className={`${td} tabular text-right text-ink-muted`}>{i.rank}</td>
              <td className={`${td} font-medium`}>{i.name}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(i.net_total)}</td>
              <td className={`${td} tabular text-right`}>{formatPct(i.share_pct)}</td>
              <td className={`${td} tabular text-right`}>{formatPct(i.team_share_pct)}</td>
              <td className={`${td} text-right`}><MixIndex value={i.mix_index} /></td>
              <td className={`${td} tabular text-right`}>{signed(i.growth_pct)}</td>
              <td className={`${td} tabular text-right`}>{n(i.customers)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {d?.ranking.truncated && <p className="mt-1 text-xs text-ink-muted">Se muestran los {d.ranking.items.length} con más venta de {d.ranking.total_items}.</p>}

      {d && (
        <>
          <h3 className="mb-1 mt-6 text-sm font-semibold">Lo que el equipo vende y esta persona no</h3>
          <p className="mb-2 text-xs text-ink-muted">Son las directrices más directas: categorías y líneas con venta en el equipo en las que no hay venta de este {who}. «No vendió» no prueba que no lo haya ofrecido.</p>
          <div className="grid gap-4 lg:grid-cols-2">
            <Gaps title="Categorías sin venta" items={d.gaps.categories} empty="Vendió todas las categorías que vendió el equipo." />
            <Gaps title="Líneas sin venta" items={d.gaps.lines} empty="Vendió todas las líneas que vendió el equipo." />
          </div>

          <h3 className="mb-1 mt-6 text-sm font-semibold">Productos estrella del equipo</h3>
          <p className="mb-2 text-sm text-ink-muted">
            Vendió {d.star_products.sold} de los {d.star_products.top} productos más vendidos del equipo
            {d.star_products.missing.length > 0 ? '; estos no:' : '.'}
          </p>
          {d.star_products.missing.length > 0 && (
            <DataTable label="Productos estrella que no vendió">
              <thead><tr><th className={th}>Código</th><th className={th}>Producto</th><Th right info={{ text: 'Venta neta de ese producto en el equipo (compañeros del mismo tipo en la misma compañía) en el periodo.' }}>Lo que vendió el equipo</Th><Th right info="sales_customers">Clientes</Th></tr></thead>
              <tbody>
                {d.star_products.missing.map((p) => (
                  <tr key={p.key} className={tr}>
                    <td className={`${td} tabular`}>{p.key}</td>
                    <td className={`${td} font-medium`}>{p.name}</td>
                    <td className={`${td} tabular text-right`}>{formatMoney(p.net_total)}</td>
                    <td className={`${td} tabular text-right`}>{n(p.customers)}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          )}
        </>
      )}
    </>
  )
}
