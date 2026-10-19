import { api, ApiError } from './api'
import type { FieldErrors } from '../components/ui'

/** Envía un formulario y traduce los errores 422 a errores por campo. */
export async function submitForm(
  method: 'POST' | 'PUT',
  path: string,
  body: unknown,
): Promise<{ ok: true } | { ok: false; errors: FieldErrors; message: string | null }> {
  try {
    await api(path, { method, body })
    return { ok: true }
  } catch (e) {
    if (e instanceof ApiError) {
      const hasFields = Object.keys(e.errors).length > 0
      return { ok: false, errors: e.errors, message: hasFields ? null : e.message }
    }
    return { ok: false, errors: {}, message: 'No se pudo conectar con el servidor.' }
  }
}
