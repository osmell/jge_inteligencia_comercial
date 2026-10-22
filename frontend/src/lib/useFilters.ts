import { useCallback, useState } from 'react'

/**
 * Filtros de una lista y su página en un solo estado: cambiar cualquier filtro vuelve a la página 1.
 * Reemplaza una fila de `useState` por filtro más el `setPage(1)` que hay que acordarse de llamar en cada uno.
 */
export function useFilters<T extends Record<string, unknown>>(initial: T) {
  const [state, setState] = useState<{ filters: T; page: number }>({ filters: initial, page: 1 })
  const set = useCallback((patch: Partial<T>) => setState((s) => ({ filters: { ...s.filters, ...patch }, page: 1 })), [])
  const setPage = useCallback((page: number) => setState((s) => ({ ...s, page })), [])
  return { filters: state.filters, page: state.page, set, setPage }
}
