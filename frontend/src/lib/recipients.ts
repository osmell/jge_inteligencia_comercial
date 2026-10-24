const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Separa lo escrito (comas, punto y coma, espacios o saltos de línea) en direcciones, sin repetir ni distinguir mayúsculas. */
export function parseRecipients(text: string): { valid: string[]; invalid: string[] } {
  const seen = new Set<string>()
  const valid: string[] = []
  const invalid: string[] = []
  for (const raw of text.split(/[\s,;]+/).filter(Boolean)) {
    const address = raw.toLowerCase()
    if (seen.has(address)) continue
    seen.add(address)
    ;(EMAIL.test(address) ? valid : invalid).push(address)
  }
  return { valid, invalid }
}
