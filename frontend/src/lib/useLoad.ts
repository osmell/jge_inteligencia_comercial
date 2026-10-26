import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from './api'

/**
 * Carga un recurso GET al montar y expone `reload`. `path` debe ser estable entre renders.
 * Con `enabled: false` no consulta (p. ej., filtros inválidos).
 * Si se pide otra cosa (otro filtro) antes de que llegue la respuesta, la respuesta vieja se descarta aunque llegue después.
 * Con `pollEvery` y `pollWhile`, vuelve a consultar en silencio mientras `pollWhile(data)` sea verdadero.
 */
export function useLoad<T>(path: string, options: { pollEvery?: number; pollWhile?: (data: T) => boolean; enabled?: boolean } = {}) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const enabled = options.enabled ?? true
  const latest = useRef(0)
  const load = useCallback(
    async (silent: boolean) => {
      if (!enabled) {
        setLoading(false)
        return
      }
      const request = ++latest.current
      if (!silent) setLoading(true)
      try {
        const result = await api<T>(path)
        if (request !== latest.current) return
        setData(result)
        setError(null)
      } catch (e) {
        if (request !== latest.current) return
        setError(e instanceof Error ? e.message : 'No se pudo cargar la información.')
      } finally {
        if (request === latest.current) setLoading(false)
      }
    },
    [path, enabled],
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
