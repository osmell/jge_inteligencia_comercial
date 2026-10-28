import { useEffect, useState } from 'react'

export const PAGE_SIZE = 50

/** Pagina en pantalla una lista ya cargada: la página se reinicia cuando cambia la cantidad de filas (otro periodo o filtro). */
export function usePaged<T>(rows: readonly T[], size = PAGE_SIZE) {
  const [page, setPage] = useState(1)
  useEffect(() => setPage(1), [rows.length])
  const pages = Math.max(1, Math.ceil(rows.length / size))
  const current = Math.min(page, pages)
  return { rows: rows.slice((current - 1) * size, current * size), page: current, pages, total: rows.length, size, setPage }
}

export type Paged = Pick<ReturnType<typeof usePaged>, 'page' | 'pages' | 'total' | 'size' | 'setPage'>
