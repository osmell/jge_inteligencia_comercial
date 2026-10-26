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
} as const satisfies Record<string, InfoContent>

export type InfoKey = keyof typeof GLOSSARY

/** Un dato se explica con una entrada del glosario o con un texto propio de la pantalla. */
export type Info = InfoKey | InfoContent

export const resolveInfo = (info: Info): InfoContent => (typeof info === 'string' ? GLOSSARY[info] : info)
