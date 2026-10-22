import { useCallback, useState } from 'react'

/** Los campos de un formulario o de unos filtros en un solo objeto: `set('nombre', valor)` cambia uno sin tocar los demás. */
export function useFormValues<T extends Record<string, unknown>>(initial: T | (() => T)) {
  const [values, setValues] = useState<T>(initial)
  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => setValues((v) => ({ ...v, [key]: value })), [])
  /** Cambia un campo a partir de su valor actual (listas, contadores…). */
  const update = useCallback(<K extends keyof T>(key: K, change: (current: T[K]) => T[K]) => setValues((v) => ({ ...v, [key]: change(v[key]) })), [])
  return { values, set, update, setValues }
}
