import { useCallback, useState } from 'react'
import type { FieldErrors } from '../components/ui'
import { submitForm } from './saveForm'

/** Envío de un formulario de administración: guardando, errores por campo y mensaje general. `submit` devuelve true si se guardó. */
export function useSaveForm() {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const submit = useCallback(async (method: 'POST' | 'PUT', path: string, body: unknown): Promise<boolean> => {
    setSaving(true)
    const r = await submitForm(method, path, body)
    setSaving(false)
    if (r.ok) return true
    setErrors(r.errors)
    setMessage(r.message)
    return false
  }, [])

  return { errors, message, saving, submit }
}
