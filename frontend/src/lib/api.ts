import { API_URL } from './apiUrl'
import { withBasis } from './salesBasis'

export { API_URL }

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return
  await fetch(`${new URL(API_URL).origin}/sanctum/csrf-cookie`, { credentials: 'include' })
}

/** Se emite cuando el servidor responde 401: quien escuche (AuthProvider) manda a iniciar sesión. */
export const SESSION_EXPIRED_EVENT = 'auth:expired'

export async function api<T>(path: string, options: { method?: string; body?: unknown } = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (method !== 'GET') {
    await ensureCsrfCookie()
    headers['X-XSRF-TOKEN'] = readCookie('XSRF-TOKEN') ?? ''
  }
  // FormData lo serializa el navegador (con su propio Content-Type y boundary).
  const isForm = options.body instanceof FormData
  if (options.body !== undefined && !isForm) headers['Content-Type'] = 'application/json'

  const response = await fetch(`${API_URL}${withBasis(path)}`, {
    method,
    headers,
    credentials: 'include',
    body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
  })

  if (response.status === 204) return undefined as T

  const data = await response.json().catch(() => ({}))
  if (response.status === 401) window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT))   // la sesión ya no vale (cerrada en otro equipo, clave cambiada, cuenta desactivada…)
  if (!response.ok) {
    const message = response.status === 429 ? 'Demasiadas solicitudes seguidas: espera unos segundos e inténtalo de nuevo.' : (data.message ?? 'Ocurrió un error inesperado.')
    throw new ApiError(response.status, message, data.errors)
  }
  return data as T
}

/** Descarga un archivo autenticado (plantilla, CSV…) y lo entrega al navegador. */
export async function download(path: string, fallbackName: string): Promise<void> {
  const response = await fetch(`${API_URL}${withBasis(path)}`, { credentials: 'include', headers: { Accept: '*/*' } })
  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new ApiError(response.status, data.message ?? 'No se pudo descargar el archivo.')
  }

  const match = /filename="?([^";]+)"?/i.exec(response.headers.get('content-disposition') ?? '')
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = match?.[1] ?? fallbackName
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
