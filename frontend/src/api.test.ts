import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from './lib/api'

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
