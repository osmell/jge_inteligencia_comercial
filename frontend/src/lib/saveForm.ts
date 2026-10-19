import { api, ApiError } from './api'
import type { FieldErrors } from '../components/ui'

export type SubmitResult = { ok: true } | { ok: false; errors: FieldErrors; message: string }

/** Envía un formulario y traduce los errores 422 a errores por campo más un mensaje general. */
export async function submitForm(method: 'POST' | 'PUT', path: string, body: unknown): Promise<SubmitResult> {
  try {
    await api(path, { method, body })
    return { ok: true }
  } catch (e) {
    if (e instanceof ApiError) {
      const hasFields = Object.keys(e.errors).length > 0
      return { ok: false, errors: e.errors, message: hasFields ? 'Revisa los campos marcados e inténtalo de nuevo.' : e.message }
    }
    return { ok: false, errors: {}, message: 'No se pudo conectar con el servidor.' }
  }
}
