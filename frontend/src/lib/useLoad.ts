import { useCallback, useEffect, useState } from 'react'
import { api } from './api'

/**
 * Carga un recurso GET al montar y expone `reload`. `path` debe ser estable entre renders.
 * Con `pollEvery` y `pollWhile`, vuelve a consultar en silencio mientras `pollWhile(data)` sea verdadero.
 */
export function useLoad<T>(path: string, options: { pollEvery?: number; pollWhile?: (data: T) => boolean } = {}) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const load = useCallback(
    async (silent: boolean) => {
      if (!silent) setLoading(true)
      try {
        setData(await api<T>(path))
        setError(null)
      } catch (e) {
        setError(e instanceof Error ? e.message : 'No se pudo cargar la información.')
      } finally {
        if (!silent) setLoading(false)
      }
    },
    [path],
  )

  const reload = useCallback(() => load(false), [load])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load(false)
  }, [load])

  const polling = !!options.pollEvery && !!data && !!options.pollWhile?.(data)
  useEffect(() => {
    if (!polling || !options.pollEvery) return
    const timer = setInterval(() => void load(true), options.pollEvery)
    return () => clearInterval(timer)
  }, [polling, options.pollEvery, load])

  return { data, error, loading, reload }
}
