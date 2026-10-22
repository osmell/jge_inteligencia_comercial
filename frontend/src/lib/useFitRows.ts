import { useEffect, useRef, useState } from 'react'

/**
 * Cuántas filas caben en la altura de la ventana (según `rowsFor`). Sigue los cambios de tamaño (con una pausa de 200 ms)
 * y avisa con `onChange` cuando la cantidad cambia, para que la lista vuelva a la primera página.
 */
export function useFitRows(rowsFor: (height: number) => number, onChange: () => void): number {
  const [rows, setRows] = useState(() => rowsFor(window.innerHeight))
  const current = useRef(rows)
  const notify = useRef(onChange)

  useEffect(() => {
    notify.current = onChange
  })

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined
    const onResize = () => {
      clearTimeout(timer)
      timer = setTimeout(() => {
        const next = rowsFor(window.innerHeight)
        if (next !== current.current) {
          current.current = next
          setRows(next)
          notify.current()
        }
      }, 200)
    }
    window.addEventListener('resize', onResize)
    return () => {
      clearTimeout(timer)
      window.removeEventListener('resize', onResize)
    }
  }, [rowsFor])

  return rows
}
