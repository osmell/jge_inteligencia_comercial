import { useState } from 'react'
import ProductMixDetailView from '../components/reports/ProductMixDetailView'
import { PeriodLine, ReconciliationNote, ReportLegend, ReportNotes, ReportStat } from '../components/reports/ReportParts'
import ReportActions from '../components/reports/ReportActions'
import ReportFilters from '../components/reports/ReportFilters'
import { Alert, DataTable, EmptyRow, FormError, Modal, PageHeader, SelectField, TableSkeleton, td, th, tr, Th } from '../components/ui'
import { formatMoney } from '../lib/format'
import { MIX_LEVEL_LABEL, MIX_SUBJECT_LABEL, type MixLevel, type MixSubject, type ProductMixReport, type ProductMixRow } from '../lib/reports'
import { formatPct } from '../lib/sales'
import { useLoad } from '../lib/useLoad'
import { useReportFilters } from '../lib/useReportFilters'

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('es'))
const signed = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${formatPct(v)}`)

/** La cobertura se dibuja como barra y como número: nunca solo con color. */
function Coverage({ sold, catalog, pct }: { sold: number; catalog: number; pct: number | null }) {
  return (
    <div className="flex min-w-28 items-center justify-end gap-2">
      <span className="tabular">{sold} de {catalog}</span>
      <span className="tabular w-12 text-xs text-ink-muted">{formatPct(pct)}</span>
      <span className="h-1.5 w-12 overflow-hidden rounded-full bg-surface-muted print:hidden" aria-hidden="true">
        <span className="block h-full rounded-full bg-jge-blue" style={{ width: `${Math.min(100, pct ?? 0)}%` }} />
      </span>
    </div>
  )
}

function PersonRow({ r, onOpen }: { r: ProductMixRow; onOpen: () => void }) {
  return (
    <tr className={tr}>
      <td className={`${td} tabular text-right text-ink-muted`}>{r.rank ?? '—'}</td>
      <td className={`${td} font-medium`}>
        <button type="button" className="cursor-pointer text-left text-jge-blue hover:underline" onClick={onOpen} aria-label={`Ver el detalle de ${r.subject.name}`}>{r.subject.name}</button>
        {!r.subject.is_active && <span className="block text-xs font-normal text-ink-muted">inactivo</span>}
      </td>
      <td className={`${td} tabular text-right font-medium`}>{formatMoney(r.net_total)}<span className="block text-xs font-normal text-ink-muted">{formatPct(r.share_pct)} del total</span></td>
      <td className={`${td} tabular text-right`}>{n(r.products_sold)}</td>
      <td className={`${td} border-l border-line text-right`}><Coverage sold={r.categories_sold} catalog={r.categories_catalog} pct={r.category_coverage_pct} /></td>
      <td className={`${td} text-right`}><Coverage sold={r.lines_sold} catalog={r.lines_catalog} pct={r.line_coverage_pct} /></td>
      <td className={`${td} tabular text-right`}>{n(r.groups_sold)}</td>
      <td className={`${td} border-l border-line tabular text-right`}>{formatPct(r.top5_share_pct)}</td>
    </tr>
  )
}

export default function ReportProductsPage() {
  const filters = useReportFilters()
  const [subject, setSubject] = useState<MixSubject>('seller')
  const [level, setLevel] = useState<MixLevel>('category')
  const [open, setOpen] = useState<ProductMixRow | null>(null)
  const extra = { subject, level }
  const report = useLoad<{ data: ProductMixReport }>(`/reports/products?${filters.query(extra)}`, { enabled: filters.valid })
  // Mientras llegan los datos de otro nivel o de otra persona evaluada no se muestran los anteriores bajo la etiqueta nueva.
  const loaded = report.data?.data
  const data = loaded && loaded.subject === subject && loaded.level === level ? loaded : undefined
  const t = data?.totals
  const items = data?.ranking.items ?? []
  const who = subject === 'seller' ? 'vendedor' : 'operador'
  const levelName = MIX_LEVEL_LABEL[level].toLowerCase()

  return (
    <section>
      <PageHeader title="Mezcla de productos" description="Qué es lo que más se factura y qué tan amplio es el catálogo que mueve cada vendedor u operador: cobertura de categorías y líneas, dependencia de pocos productos y lo que no vende." />
      <ReportFilters filters={filters} />
      <div className="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        <SelectField id="mix-subject" label="Evaluar a" value={subject} onChange={(e) => { setSubject(e.target.value as MixSubject); setOpen(null) }}>
          {(Object.keys(MIX_SUBJECT_LABEL) as MixSubject[]).map((s) => <option key={s} value={s}>{MIX_SUBJECT_LABEL[s]}</option>)}
        </SelectField>
        <SelectField id="mix-level" label="Ver la venta por" value={level} onChange={(e) => setLevel(e.target.value as MixLevel)}>
          {(Object.keys(MIX_LEVEL_LABEL) as MixLevel[]).map((l) => <option key={l} value={l}>{MIX_LEVEL_LABEL[l]}</option>)}
        </SelectField>
      </div>
      <ReportActions report="products" filters={filters} extra={extra} />
      <FormError message={filters.contextError ?? report.error} />
      {!filters.valid && <div className="mb-3"><Alert tone="warning">Indica un periodo válido (y, si comparas con otro periodo, sus dos fechas): la fecha inicial no puede ser posterior a la final.</Alert></div>}
      {data && <PeriodLine data={data} />}
      {data && <ReportNotes notes={data.notes} />}

      {t && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <ReportStat label="Venta neta" value={formatMoney(t.net_total)} hint={`${n(data?.ranking.total_items ?? 0)} ${levelName === 'categoría' ? 'categorías' : levelName === 'línea' ? 'líneas' : levelName === 'grupo' ? 'grupos' : 'productos'} con venta`} />
          <ReportStat label="Catálogo" value={`${n(t.catalog_categories)} categorías`} hint={`${n(t.catalog_lines)} líneas`} />
          <ReportStat label="Cobertura promedio" value={formatPct(t.average_category_coverage_pct)} hint="de las categorías del catálogo" />
          <ReportStat label="Con menos de la mitad" value={n(t.below_half_coverage)} hint={`${subject === 'seller' ? 'vendedores' : 'operadores'} que venden menos de la mitad de las categorías`} />
        </div>
      )}

      {data && <ReconciliationNote rec={data.reconciliation} who={subject === 'seller' ? 'los vendedores' : 'los operadores'} />}

      <h2 className="mb-1 text-sm font-semibold">Lo más facturado por {levelName}</h2>
      <DataTable scroll label={`Lo más facturado por ${levelName}`}>
        <thead>
          <tr>
            <Th right info="rep_rank">#</Th>
            <th className={th}>{MIX_LEVEL_LABEL[level]}</th>
            <Th right info="sales_net">Venta neta</Th>
            <Th right info={{ text: 'Peso de la fila en la venta neta total del periodo.', formula: 'Peso % = Venta neta de la fila ÷ Venta neta total × 100' }}>% del total</Th>
            <Th right info="sales_vs_compared">Cambio</Th>
            <Th right info="sales_units">Unidades</Th>
            <Th right info="sales_documents">Documentos</Th>
            <Th right info="sales_customers">Clientes</Th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={8} />}
          {data && items.length === 0 && <EmptyRow cols={8}>No hay venta en el periodo.</EmptyRow>}
          {items.map((i) => (
            <tr key={i.key} className={tr}>
              <td className={`${td} tabular text-right text-ink-muted`}>{i.rank}</td>
              <td className={`${td} font-medium`}>{i.name}{level === 'product' && <span className="ml-2 text-xs font-normal text-ink-muted">{i.key}</span>}</td>
              <td className={`${td} tabular text-right font-medium`}>{formatMoney(i.net_total)}</td>
              <td className={`${td} tabular text-right`}>{formatPct(i.share_pct)}</td>
              <td className={`${td} tabular text-right`}>{signed(i.growth_pct)}</td>
              <td className={`${td} tabular text-right`}>{n(Math.round(i.units))}</td>
              <td className={`${td} tabular text-right`}>{n(i.documents)}</td>
              <td className={`${td} tabular text-right`}>{n(i.customers)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {data?.ranking.truncated && <p className="mt-1 text-xs text-ink-muted">Se muestran los {items.length} con más venta de {data.ranking.total_items}.</p>}

      <h2 className="mb-1 mt-8 text-sm font-semibold">Amplitud de catálogo por {who}</h2>
      <DataTable scroll label={`Amplitud de catálogo por ${who}`}>
        <thead>
          <tr>
            <Th right info="rep_rank">#</Th>
            <th className={th}>{subject === 'seller' ? 'Vendedor' : 'Operador'}</th>
            <Th right info="sales_net">Venta neta</Th>
            <Th right info={{ text: 'Productos distintos con venta neta positiva en el periodo. Una devolución no cuenta como haber vendido.' }}>Productos distintos</Th>
            <Th right className="border-l border-line" info={{ text: 'Categorías del catálogo de la compañía con venta neta positiva, frente al total de categorías del catálogo (por ejemplo «3 de 4»). El porcentaje es la cobertura.', formula: 'Cobertura % = Categorías vendidas ÷ Categorías del catálogo × 100' }}>Categorías vendidas</Th>
            <Th right info={{ text: 'Líneas del catálogo de la compañía con venta neta positiva, frente al total de líneas del catálogo.', formula: 'Cobertura % = Líneas vendidas ÷ Líneas del catálogo × 100' }}>Líneas vendidas</Th>
            <Th right info={{ text: 'Grupos del catálogo (nivel 1) en los que tuvo venta neta positiva.' }}>Grupos</Th>
            <Th right className="border-l border-line" info={{ text: 'Dependencia: parte de su venta que sale de sus 5 productos principales. Más alto, más repite lo mismo.', formula: 'Top 5 % = Venta de sus 5 productos principales ÷ Venta neta × 100' }}>Top 5 productos</Th>
          </tr>
        </thead>
        <tbody>
          {filters.valid && report.loading && !data && <TableSkeleton cols={8} />}
          {data && data.rows.length === 0 && <EmptyRow cols={8}>No hay personas con venta ni actividad en el periodo.</EmptyRow>}
          {data?.rows.map((r) => <PersonRow key={r.subject.id} r={r} onOpen={() => setOpen(r)} />)}
        </tbody>
      </DataTable>

      <ReportLegend>
        <span>Cobertura: categorías (o líneas) del catálogo con venta positiva. «Top 5 productos»: cuánto de su venta sale de solo cinco productos; mientras más alto, más repite lo mismo. Haz clic en un nombre para ver qué no vende.</span>
      </ReportLegend>

      {open && (
        <Modal title={`Mezcla de productos de ${open.subject.name}`} onClose={() => setOpen(null)} extraWide>
          <ProductMixDetailView subject={subject} id={open.subject.id} query={filters.query()} level={level} />
        </Modal>
      )}
    </section>
  )
}
