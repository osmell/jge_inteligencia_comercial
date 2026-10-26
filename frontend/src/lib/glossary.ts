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
} as const satisfies Record<string, InfoContent>

export type InfoKey = keyof typeof GLOSSARY

/** Un dato se explica con una entrada del glosario o con un texto propio de la pantalla. */
export type Info = InfoKey | InfoContent

export const resolveInfo = (info: Info): InfoContent => (typeof info === 'string' ? GLOSSARY[info] : info)
