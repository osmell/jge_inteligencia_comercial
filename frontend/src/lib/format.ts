const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** '1234.5000' → '1,234.50' (punto decimal y coma de miles; solo para mostrar: los importes viajan como texto). */
export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return money.format(Number(value))
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function formatDateTime(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString('es') : '—'
}
