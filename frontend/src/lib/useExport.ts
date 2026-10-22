import { useCallback, useState } from 'react'

/** Estado de una descarga (CSV, Excel, PDF): cuál formato se está generando y el último error. Un botón por formato, uno a la vez. */
export function useExport<F extends string = string>() {
  const [exporting, setExporting] = useState<F | null>(null)
  const [error, setError] = useState<string | null>(null)

  const run = useCallback(async (format: F, action: () => Promise<void>, failure = 'No se pudo exportar.') => {
    setExporting(format)
    setError(null)
    try {
      await action()
    } catch (e) {
      setError(e instanceof Error ? e.message : failure)
    } finally {
      setExporting(null)
    }
  }, [])

  return { exporting, error, run }
}
