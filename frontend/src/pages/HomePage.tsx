import { RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { type BlockProps, CompaniesBlock, ConversationsBlock, CreditBlock, FollowupBlock, GoalsBlock, InsightsBlock, InventoryBlock, QuotationsBlock, RankingsBlock, SalesBlock } from '../components/DashboardSections'
import { Alert, Button, PageHeader, SelectField } from '../components/ui'
import { type DashboardMeta, PERIOD_LABEL } from '../lib/dashboard'
import { formatDateTime } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

const BLOCKS: Record<string, (props: BlockProps) => React.ReactNode> = {
  sales: SalesBlock, goals: GoalsBlock, rankings: RankingsBlock, companies: CompaniesBlock, credit: CreditBlock,
  quotations: QuotationsBlock, followup: FollowupBlock, conversations: ConversationsBlock, inventory: InventoryBlock, insights: InsightsBlock,
}

/** Lo primero que necesita un vendedor es su agenda y su meta; el resto de los perfiles conserva el orden de la API. */
const SELLER_ORDER = ['conversations', 'followup', 'goals', 'sales', 'credit', 'quotations', 'insights', 'rankings', 'inventory']

/** Secciones que ocupan todo el ancho; las demás se acomodan en dos columnas. */
const WIDE = new Set(['sales', 'rankings', 'companies'])

const SCOPE_TEXT = { company: 'Todo tu ámbito de compañías.', team: 'Lo de los vendedores que supervisas.', seller: 'Solo lo tuyo: tus ventas, clientes, cartera y cotizaciones.' } as const

/** Pantalla de inicio: indicadores y gráficos según el rol, cada sección con su propia carga. */
export default function HomePage() {
  const { user } = useAuth()
  const meta = useLoad<{ data: DashboardMeta }>('/dashboard')
  const [period, setPeriod] = useState('month')
  const [companyId, setCompanyId] = useState('')
  const [refresh, setRefresh] = useState(0)
  const m = meta.data?.data
  const query = new URLSearchParams({ period, ...(companyId ? { company_id: companyId } : {}) }).toString()

  return (
    <section>
      <PageHeader
        title={`Hola, ${user?.name}`}
        description={m ? `${SCOPE_TEXT[m.profile]} ${m.last_sale ? `Ventas hasta el ${formatDay(m.last_sale)}${m.last_import ? ` (última carga: ${formatDateTime(m.last_import)})` : ''}.` : 'Todavía no hay ventas cargadas.'}` : 'Resumen de tu actividad.'}
      />
      {meta.error && !m && <Alert tone="danger">{meta.error}</Alert>}
      {m && m.profile === 'team' && m.linked_sellers === 0 && <div className="mb-4"><Alert tone="warning">Todavía no tienes vendedores asignados a tu equipo, por eso las cifras salen en cero. Pide a administración que te asigne como supervisor en la ficha de cada vendedor.</Alert></div>}
      {m && m.profile === 'team' && m.team.length > 0 && <p className="mb-4 text-sm text-ink-muted">Tu equipo: <span className="font-medium text-ink">{m.team.join(', ')}</span>.</p>}
      {m && m.profile === 'seller' && m.linked_sellers === 0 && <div className="mb-4"><Alert tone="warning">Tu usuario todavía no está vinculado a un vendedor, por eso las cifras salen en cero. Pide a administración que lo vincule.</Alert></div>}

      {m && m.sections.length > 0 && (
        <>
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <SelectField id="dash-period" label="Periodo" value={period} onChange={(e) => setPeriod(e.target.value)}>
              {m.periods.map((p) => <option key={p} value={p}>{PERIOD_LABEL[p] ?? p}</option>)}
            </SelectField>
            {m.companies.length > 1 && (
              <SelectField id="dash-company" label="Compañía" value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
                <option value="">Todas</option>
                {m.companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </SelectField>
            )}
            <Button variant="secondary" icon={<RefreshCw className="h-4 w-4" aria-hidden="true" />} onClick={() => setRefresh((n) => n + 1)}>Actualizar</Button>
          </div>
          <div key={`${query}-${refresh}`} className="grid gap-6 lg:grid-cols-2">
            {(m.profile === 'seller' ? [...m.sections].sort((a, b) => SELLER_ORDER.indexOf(a) - SELLER_ORDER.indexOf(b)) : m.sections).map((key) => {
              const Block = BLOCKS[key]
              return Block ? <div key={key} className={WIDE.has(key) ? 'lg:col-span-2' : ''}><Block query={query} profile={m.profile} /></div> : null
            })}
          </div>
        </>
      )}
      {m && m.sections.length === 0 && <Alert tone="info">Tu rol todavía no tiene indicadores asignados. Usa la barra del encabezado (tecla /) para ir a las pantallas o pide a administración los permisos que necesites.</Alert>}
    </section>
  )
}
