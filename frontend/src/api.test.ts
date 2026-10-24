import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, SESSION_EXPIRED_EVENT, api } from './lib/api'

afterEach(() => vi.unstubAllGlobals())

describe('api', () => {
  it('un límite de peticiones (429) se explica en español, no con el texto en inglés del servidor', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Too Many Attempts.' }), { status: 429 })))

    await expect(api('/sales/summary')).rejects.toMatchObject({ status: 429, message: expect.stringContaining('Demasiadas solicitudes') })
  })

  it('un error de validación conserva los mensajes por campo', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Datos no válidos', errors: { name: ['Obligatorio'] } }), { status: 422 })))

    const error = await api('/x').catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).errors).toEqual({ name: ['Obligatorio'] })
  })
})

describe('sesión revocada', () => {
  it('una respuesta 401 avisa a la aplicación para que mande a iniciar sesión', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Tu sesión ya no es válida.', code: 'session_revoked' }), { status: 401 })))
    const heard = vi.fn()
    window.addEventListener(SESSION_EXPIRED_EVENT, heard)

    await expect(api('/sales/summary')).rejects.toMatchObject({ status: 401 })

    expect(heard).toHaveBeenCalledTimes(1)
    window.removeEventListener(SESSION_EXPIRED_EVENT, heard)
  })

  it('un 403 o un 422 no cierran la sesión', async () => {
    const heard = vi.fn()
    window.addEventListener(SESSION_EXPIRED_EVENT, heard)
    for (const status of [403, 422]) {
      vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'No' }), { status })))
      await api('/x').catch(() => undefined)
    }

    expect(heard).not.toHaveBeenCalled()
    window.removeEventListener(SESSION_EXPIRED_EVENT, heard)
  })
})
