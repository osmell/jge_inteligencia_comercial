import { useCallback, useState } from 'react'
import type { FieldErrors } from '../components/ui'
import { ApiError } from './api'

/**
 * Estado de un envío de formulario: guardando, errores por campo y la secuencia «limpiar errores → intentar → mostrar errores».
 * `run` devuelve true si la acción terminó bien. Un error que no es de validación se muestra en `fallbackField`.
 */
export function useSubmit() {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const run = useCallback(async (action: () => Promise<void>, fallbackField = 'form'): Promise<boolean> => {
    setSaving(true)
    setErrors({})
    try {
      await action()
      return true
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { [fallbackField]: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
      return false
    } finally {
      setSaving(false)
    }
  }, [])

  return { errors, saving, run, setErrors }
}
