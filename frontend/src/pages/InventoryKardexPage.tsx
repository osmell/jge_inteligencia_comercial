import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert, Card, DataTable, EmptyRow, FormError, PageHeader, TableSkeleton, TextField, td, th, tr, Th } from '../components/ui'
import { formatMoney } from '../lib/format'
import { MOVEMENT_TYPE_LABEL, type Kardex } from '../lib/inventory'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

const num = (v: string) => Number(v).toLocaleString('en-US', { maximumFractionDigits: 8 })

/** Movimientos de un producto en una sucursal con el saldo corrido. */
export default function InventoryKardexPage() {
  const [params] = useSearchParams()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const ready = ['company_id', 'branch_id', 'product_id'].every((k) => params.get(k))
  const query = new URLSearchParams({ company_id: params.get('company_id') ?? '', branch_id: params.get('branch_id') ?? '', product_id: params.get('product_id') ?? '' })
  if (from) query.set('from', from)
  if (to) query.set('to', to)
  const kardex = useLoad<Kardex>(`/inventory/kardex?${query}`, { enabled: ready })
  const d = kardex.data?.data
  const meta = kardex.data?.meta
  const costs = meta?.costs_visible === true
  const cols = costs ? 9 : 7

  return (
    <section>
      <PageHeader
        title={meta ? `Kárdex: ${meta.product.name}` : 'Kárdex'}
        description={meta ? `${meta.product.code} · ${meta.branch} · en ${meta.product.unit ?? 'unidad base'}` : undefined}
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inventario">← Existencias</Link>}
      />
      {!ready && <Alert tone="info">Elige un producto desde la pantalla de existencias.</Alert>}
      <FormError message={kardex.error} />

      {ready && (
        <>
          <Card className="mb-4 p-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <TextField id="kardex-from" label="Desde" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <TextField id="kardex-to" label="Hasta" type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
            </div>
          </Card>
          {d && !d.reconciled && <div className="mb-3"><Alert tone="warning">Esta sucursal no tiene inventario inicial cargado: el saldo es parcial.</Alert></div>}
          {d?.truncated && <div className="mb-3"><Alert tone="info">Se muestran los primeros 500 movimientos del rango; acota las fechas para ver el resto.</Alert></div>}

          <DataTable label="Movimientos del producto">
            <thead>
              <tr>
                <th className={th}>Fecha</th><Th info={{ text: 'Tipo de movimiento: entrada o salida del producto.' }}>Movimiento</Th><Th right info={{ text: 'Unidades del movimiento, en la unidad base del producto.' }}>Cantidad</Th><Th info={{ text: 'Fecha y hora en que se registró el movimiento en el sistema.' }}>Capturado</Th>
                {costs && <Th right info={{ text: 'Valor del movimiento: las entradas llevan su costo y las salidas se valoran a costo promedio ponderado.' }}>Valor</Th>}
                <Th right info={{ text: 'Existencia después de este movimiento.', formula: 'Saldo = Saldo anterior ± Cantidad' }}>Saldo</Th>{costs && <Th right info={{ text: 'Valor de la existencia después de este movimiento: las entradas suman su costo y las salidas restan a costo promedio ponderado.' }}>Valor del saldo</Th>}
                <th className={th}>Referencia y nota</th><th className={th}>Registró</th>
              </tr>
            </thead>
            <tbody>
              {kardex.loading && !d ? <TableSkeleton cols={cols + 1} /> : (
                <>
                  <tr className={tr}>
                    <td className={td} colSpan={costs ? 5 : 4}>Saldo inicial del rango</td>
                    <td className={`${td} tabular text-right font-medium`}>{d ? num(d.opening.quantity) : '—'}</td>
                    {costs && <td className={`${td} tabular text-right`}>{formatMoney(d?.opening.value)}</td>}
                    <td className={td} colSpan={2} />
                  </tr>
                  {d && d.rows.length === 0 && <EmptyRow cols={cols + 2}>No hay movimientos en este rango.</EmptyRow>}
                  {(d?.rows ?? []).map((m) => (
                    <tr key={m.id} className={tr}>
                      <td className={td}>{formatDay(m.date)}</td>
                      <td className={td}>{MOVEMENT_TYPE_LABEL[m.type]}{m.reversed && <div className="text-xs font-medium text-danger">Revertido</div>}</td>
                      <td className={`${td} tabular text-right`}>{num(m.quantity)}</td>
                      <td className={td}>{m.entered_unit ? `${num(m.entered)} ${m.entered_unit}` : '—'}</td>
                      {costs && <td className={`${td} tabular text-right`}>{formatMoney(m.value)}</td>}
                      <td className={`${td} tabular text-right font-medium`}>{num(m.balance)}</td>
                      {costs && <td className={`${td} tabular text-right`}>{formatMoney(m.balance_value)}</td>}
                      <td className={td}>{[m.reference, m.note].filter(Boolean).join(' · ') || '—'}</td>
                      <td className={td}>{m.user ?? '—'}</td>
                    </tr>
                  ))}
                </>
              )}
            </tbody>
          </DataTable>
        </>
      )}
    </section>
  )
}
