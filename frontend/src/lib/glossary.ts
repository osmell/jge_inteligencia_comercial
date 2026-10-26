/**
 * Glosario de datos: qué significa cada dato que se muestra en pantalla y, si es calculado, con qué fórmula.
 * Las explicaciones salen de las reglas del servidor (importadores y reportes); si una regla cambia, se cambia aquí.
 * Una fórmula se escribe con palabras del negocio, no con nombres técnicos.
 */
export interface InfoContent {
  /** Qué es el dato y de dónde sale. */
  text: string
  /** Solo si el dato es calculado. */
  formula?: string
}

export const GLOSSARY = {
  units: { text: 'Cantidad vendida de la línea, tal como viene en el archivo. Con decimales indica una equivalencia (por ejemplo, 0,25 es un cuarto de galón).' },
  unit_price: { text: 'Precio unitario aplicado en la venta, sin impuesto, tal como viene en el archivo.' },
  normal_price: { text: 'Precio unitario normal (de lista) de la línea, tal como viene en el archivo. Puede incluir el impuesto.' },
  net_total: {
    text: 'Valor de la línea sin impuesto, tal como viene en el archivo importado. El sistema no lo corrige.',
    formula: 'Total neto = Sub total − Descuento',
  },
  tax_total: { text: 'Impuesto de la línea.', formula: 'Total impuesto = Total neto × IVA' },
  sale_plus_tax: { text: 'Valor de la línea con el impuesto incluido.', formula: 'Venta + impuesto = Total neto + Total impuesto' },
  // ───── Ventas ─────
  sales_net: {
    text: 'Venta del periodo sin impuesto y con el descuento ya restado. Es la cifra de ventas que usa todo el sistema y se toma tal como viene en el archivo.',
    formula: 'Venta neta = suma del Total neto de las líneas del periodo',
  },
  sales_tax: { text: 'Impuesto de las ventas del periodo.', formula: 'Impuesto = suma del Total impuesto de las líneas del periodo' },
  sales_grand: { text: 'Lo facturado al cliente en el periodo, con el impuesto incluido.', formula: 'Total facturado = Venta neta + Impuesto' },
  sales_discount: {
    text: 'Descuento otorgado en el periodo. Ya está restado de la venta neta; no se resta otra vez. El porcentaje se calcula solo sobre las líneas que traen Sub total.',
    formula: 'Descuento % = Descuento ÷ Sub total × 100',
  },
  sales_documents: { text: 'Facturas distintas del periodo. Una factura de varias líneas cuenta una sola vez.' },
  sales_customers: { text: 'Clientes distintos que compraron en el periodo.' },
  sales_ticket: { text: 'Venta neta promedio por factura.', formula: 'Ticket promedio = Venta neta ÷ Documentos' },
  sales_units: { text: 'Unidades vendidas en el periodo. Las fracciones (por ejemplo 0,25) son equivalencias de un envase mayor.', formula: 'Unidades = suma de las unidades de las líneas' },
  sales_margin: {
    text: 'Ganancia estimada de las líneas que tienen costo. Usa el costo actual del producto, no el costo histórico, así que es una estimación.',
    formula: 'Margen estimado = Venta neta de las líneas con costo − Costo de esas líneas',
  },
  sales_margin_pct: {
    text: 'Margen estimado sobre la venta de las líneas que tienen costo. Usa el costo actual del producto.',
    formula: 'Margen % = (Venta neta − Costo) ÷ Venta neta de las líneas con costo × 100',
  },
  sales_vs_compared: {
    text: 'Cambio de la venta neta frente al periodo con el que se compara. Sin venta en el periodo comparado no hay porcentaje.',
    formula: 'Cambio % = (Venta neta del periodo − Venta neta comparada) ÷ Venta neta comparada × 100',
  },
  sales_share: { text: 'Barra proporcional a la venta neta de la fila más alta de la tabla.' },
  // ───── Metas ─────
  goal_month: { text: 'Meta de venta neta (sin impuesto) que se le fijó al vendedor para el mes completo.' },
  goal_effective: {
    text: 'Meta del mes ajustada a los días en que el vendedor trabajó: se descuentan los días anteriores a su alta, posteriores a su baja y sus ausencias.',
    formula: 'Meta efectiva = Meta del mes × Días elegibles ÷ Días del mes',
  },
  goal_actual: { text: 'Venta neta del vendedor dentro del mes.' },
  goal_compliance: {
    text: 'Qué parte de la meta efectiva se ha vendido. Semáforo: verde desde 100 %, ámbar desde 70 %, rojo por debajo de 70 %.',
    formula: 'Cumplimiento % = Vendido ÷ Meta efectiva × 100',
  },
  goal_status: { text: 'Semáforo del cumplimiento: cumplida (100 % o más), cerca (70 % a menos de 100 %) o por debajo (menos de 70 %). «Sin meta» y «Exento» no se evalúan.' },
  goal_projected: {
    text: 'Lo que vendería el vendedor al terminar el mes si mantiene el ritmo actual. Solo se calcula en el mes en curso.',
    formula: 'Proyección = Vendido ÷ Parte transcurrida de sus días elegibles',
  },
  goal_pace: {
    text: 'Compara lo vendido con lo que debería llevar a esta fecha. Por debajo de 80 % (pasado el 20 % del mes) se marca como atrasado.',
    formula: 'Ritmo % = Vendido ÷ (Meta efectiva × Parte transcurrida del mes) × 100',
  },
  // ───── Cotizaciones ─────
  quotation_open: { text: 'Cotizaciones emitidas, enviadas o en seguimiento que todavía no vencen. Solo se cuentan las del alcance de la persona.' },
  quotation_expiring: { text: 'Cotizaciones abiertas cuya fecha de validez vence dentro de los próximos 3 días.' },
  quotation_expired: { text: 'Cotizaciones que siguen abiertas (emitidas, enviadas o en seguimiento) pero ya pasó su fecha de validez. Hay que cerrarlas o renovarlas.' },
  quotation_status: { text: 'Etapa de la cotización. «Vencida» no es una etapa guardada: se muestra cuando una cotización abierta pasó su fecha de validez.' },
  // ───── Crédito ─────
  credit_balance: { text: 'Lo que los clientes todavía deben: el total de cada factura menos lo abonado y acreditado.', formula: 'Saldo = Total de la factura − Abonado y acreditado' },
  credit_overdue: {
    text: 'Saldo de las facturas con al menos un día desde su fecha de emisión. La antigüedad se cuenta desde la emisión de la factura, no desde una fecha de vencimiento.',
    formula: 'Saldo vencido = Saldo por cobrar − Saldo de las facturas emitidas hoy',
  },
  credit_age: { text: 'Días transcurridos desde la fecha de emisión de la factura. Se agrupa en tramos (al día, 1–30, 31–60…).', formula: 'Antigüedad = Hoy − Fecha de emisión' },
  credit_oldest: { text: 'Antigüedad, en días, de la factura con saldo más antigua del cliente.' },
  // ───── Reportes de evaluación ─────
  rep_rank: { text: 'Puesto según la venta neta del periodo (1 = la mayor).' },
  rep_goal: {
    text: 'Venta del periodo frente a la meta del periodo. La meta de cada mes se prorratea por los días que trabajó el vendedor. Si algún mes en que trabajó no tiene meta, o se eligió una sucursal, no se califica («sin dato»).',
    formula: 'Cumplimiento % = Venta neta ÷ Meta del periodo × 100',
  },
  rep_discount: {
    text: 'Descuento sobre el Sub total, solo de las líneas que informan Sub total. Ya está restado de la venta neta.',
    formula: 'Descuento % = Descuento ÷ Sub total × 100',
  },
  rep_below_list: {
    text: 'Parte de la venta, de las líneas que traen precio normal, que se vendió con un precio unitario menor al normal.',
    formula: 'Bajo precio normal % = Venta de las líneas con precio menor al normal ÷ Venta de las líneas con precio normal × 100',
  },
  rep_top3: { text: 'Cuánto de la venta depende de sus tres mejores clientes. Más alto, más dependencia de pocos clientes.', formula: 'Top 3 % = Venta de los 3 mejores clientes ÷ Venta neta × 100' },
  rep_portfolio: { text: 'Clientes de la cartera a hoy. Un cliente pertenece a quien le facturó la última vez, si fue en los últimos 365 días.' },
  rep_inactive: { text: 'Clientes de la cartera sin compra por más del plazo de inactividad configurado en Clientes, pero que aún no se consideran perdidos.' },
  rep_lost: {
    text: 'Clientes de la cartera sin compra por más del plazo de pérdida configurado en Clientes. El porcentaje se compara con el total de la cartera.',
    formula: 'Perdidos % = Clientes perdidos ÷ Clientes de la cartera × 100',
  },
  rep_receivable: { text: 'Saldo por cobrar a hoy de las facturas de crédito, según las facturas que se atribuyen a esta fila.' },
  rep_overdue: {
    text: 'Parte del saldo que está en facturas emitidas hace más de 30 días; el sistema no guarda plazos por cliente. Es distinto del «saldo vencido» de Crédito, que cuenta desde 1 día.',
    formula: 'Vencido % = Saldo de facturas con más de 30 días ÷ Saldo por cobrar × 100',
  },
  rep_quote_conversion: {
    text: 'Cotizaciones emitidas en el periodo que ya se convirtieron en venta.',
    formula: 'Conversión % = Cotizaciones convertidas ÷ Cotizaciones emitidas × 100',
  },
  rep_contacts: { text: 'Contactos con clientes que registró en el periodo el usuario vinculado al vendedor. Sin usuario vinculado se muestra «sin usuario».' },
  rep_pending: { text: 'Acciones de seguimiento sin atender cuya fecha ya pasó, a hoy.' },
  rep_lines: { text: 'Líneas facturadas en el periodo.' },
  rep_active_days: { text: 'Días del periodo en que el operador facturó al menos una línea.' },
  rep_high_discount: {
    text: 'Líneas cuyo descuento es al menos el 20 % de su Sub total, sobre el total de líneas del operador.',
    formula: 'Descuento alto % = Líneas con descuento ≥ 20 % del Sub total ÷ Líneas × 100',
  },
  rep_zero_price: {
    text: 'Líneas con cantidad cuyo precio unitario o venta neta es cero (regaladas o mal capturadas). Las devoluciones no cuentan.',
    formula: 'Sin precio % = Líneas con precio o venta neta en cero ÷ Líneas × 100',
  },
  rep_atypical: {
    text: 'Documentos cuya venta neta supera el límite de valores extremos de todos los documentos de su compañía en el periodo. Con menos de 20 documentos en la compañía no se evalúa.',
    formula: 'Límite = Q3 + 3 × (Q3 − Q1) de la venta neta por documento; Atípicos % = Documentos sobre el límite ÷ Documentos × 100',
  },
  rep_negative: { text: 'Documentos del operador con venta neta menor que cero (devoluciones o notas de crédito).' },
  rep_direct: {
    text: 'Parte de la venta cuyo vendedor se llama igual que el operador, es decir, quien facturó fue quien vendió.',
    formula: 'Venta directa % = Venta con vendedor igual al operador ÷ Venta neta × 100',
  },
  rep_top_seller: {
    text: 'Parte de la venta de la sucursal que aporta el vendedor que más vende. No cuenta a los vendedores «Tienda …» (ventas de mostrador creadas por omisión).',
    formula: 'Vendedor principal % = Venta del vendedor que más vende ÷ Venta neta de la sucursal × 100',
  },
  rep_stock_products: { text: 'Productos con existencia hoy en la sucursal. Solo cantidades de productos, sin importes ni costos.' },
  rep_no_rotation: {
    text: 'Productos con existencia y sin ventas en los últimos 60 días, sobre los productos con existencia.',
    formula: 'Sin rotación % = Productos con existencia sin ventas en 60 días ÷ Productos con existencia × 100',
  },
  rep_stockout: {
    text: 'Productos vendidos en los últimos 90 días que hoy no tienen existencia, sobre los productos vendidos.',
    formula: 'Agotados % = Productos vendidos sin existencia hoy ÷ Productos vendidos en 90 días × 100',
  },
  rep_shortfalls: { text: 'Ventas que superaron la existencia y siguen sin resolver.' },
  // ───── Clientes ─────
  customer_status: {
    text: 'Situación del cliente según sus días sin comprar. Nuevo: primera compra reciente. Recuperado: volvió a comprar tras pasar el plazo de inactividad. Activo: compró dentro de ese plazo. Inactivo: pasó el plazo de inactividad. Perdido: pasó el plazo de pérdida. Sin ventas: nunca ha comprado. Los plazos se configuran.',
  },
  customer_abc: {
    text: 'Clasificación por venta neta de los últimos 12 meses, de mayor a menor cliente: A hasta el 80 % acumulado de la venta, B hasta el 95 % y C el resto. Sin compras en el periodo no tiene clase.',
    formula: 'Clase por Venta acumulada % antes del cliente: A < 80 %, B < 95 %, C el resto',
  },
  customer_direct: {
    text: 'Venta del cliente en el periodo cuyo vendedor se llama igual que quien facturó, es decir, quien facturó fue también quien vendió.',
    formula: 'Venta directa = suma de la venta neta de las líneas con vendedor igual al facturador',
  },
} as const satisfies Record<string, InfoContent>

export type InfoKey = keyof typeof GLOSSARY

/** Un dato se explica con una entrada del glosario o con un texto propio de la pantalla. */
export type Info = InfoKey | InfoContent

export const resolveInfo = (info: Info): InfoContent => (typeof info === 'string' ? GLOSSARY[info] : info)
