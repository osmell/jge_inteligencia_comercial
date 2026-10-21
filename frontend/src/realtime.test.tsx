import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationBell from './components/NotificationBell'
import { AuthProvider } from './auth/AuthContext'
import { onConversationEvent, onNotice, onRealtimeStatus, realtimeConnected, startRealtime } from './lib/realtime'
import { playMessageSound, setSoundEnabled, soundEnabled } from './lib/sound'

const channels: Record<string, Record<string, (e: unknown) => void>> = {}
const echoOptions: Record<string, unknown>[] = []
const disconnect = vi.fn()
const bound: Record<string, () => void> = {}

vi.mock('laravel-echo', () => ({
  default: class {
    connector = { pusher: { connection: { bind: (e: string, cb: () => void) => { bound[e] = cb } } } }
    constructor(options: Record<string, unknown>) {
      echoOptions.push(options)
    }
    private(name: string) {
      const handlers: Record<string, (e: unknown) => void> = (channels[name] = {})
      return { listen(event: string, cb: (e: unknown) => void) { handlers[event] = cb; return this } }
    }
    disconnect = disconnect
  },
}))
vi.mock('pusher-js', () => ({ default: class {} }))

let config: unknown

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  config = { key: 'clave', host: 'localhost', port: 8080, scheme: 'http' }
  for (const k of Object.keys(channels)) delete channels[k]
  echoOptions.length = 0
  disconnect.mockClear()
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    if (url.endsWith('/realtime/config')) return new Response(JSON.stringify({ data: config }), { status: 200 })
    if (url.endsWith('/notifications/summary')) return new Response(JSON.stringify({ data: { unread: 0, paused: false, latest: [] } }), { status: 200 })
    return new Response(JSON.stringify({ data: {} }), { status: 200 })
  }))
})
afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('tiempo real', () => {
  it('sin configuración del servidor no se conecta y la aplicación sigue con consultas', async () => {
    config = null
    const stop = await startRealtime(7, true)
    expect(echoOptions).toHaveLength(0)
    expect(realtimeConnected()).toBe(false)
    stop()
  })

  it('escucha solo los canales privados de la persona y reparte los eventos', async () => {
    const stop = await startRealtime(7, true)
    expect(echoOptions[0]).toMatchObject({ broadcaster: 'reverb', key: 'clave', wsHost: 'localhost', wsPort: 8080, forceTLS: false })
    expect(Object.keys(channels).sort()).toEqual(['users.7.conversations', 'users.7.notifications'])

    const events: string[] = []
    const off = onConversationEvent((e) => events.push(`${e.kind}:${e.message.body}`))
    const types: string[] = []
    onNotice((t) => types.push(t))
    channels['users.7.conversations']!['.message.created']!({ conversation: { id: 1 }, message: { body: 'Hola' } })
    channels['users.7.conversations']!['.message.updated']!({ conversation: { id: 1 }, message: { body: 'Hola' } })
    channels['users.7.notifications']!['.notice.created']!({ type: 'conversation_message' })
    expect(events).toEqual(['created:Hola', 'updated:Hola'])
    expect(types).toEqual(['conversation_message'])
    off()
    channels['users.7.conversations']!['.message.created']!({ conversation: { id: 1 }, message: { body: 'Otro' } })
    expect(events).toHaveLength(2)

    const states: string[] = []
    onRealtimeStatus((s) => states.push(s))
    bound['connected']!()
    bound['disconnected']!()
    expect(states.slice(-2)).toEqual(['connected', 'disconnected'])
    stop()
    expect(disconnect).toHaveBeenCalled()
  })

  it('quien no ve conversaciones solo escucha sus avisos', async () => {
    const stop = await startRealtime(9, false)
    expect(Object.keys(channels)).toEqual(['users.9.notifications'])
    stop()
  })

  it('el sonido es opcional, se recuerda y nunca rompe si el navegador no tiene audio', () => {
    expect(soundEnabled()).toBe(true)
    setSoundEnabled(false)
    expect(soundEnabled()).toBe(false)
    expect(() => playMessageSound()).not.toThrow()
    setSoundEnabled(true)
    expect(() => playMessageSound()).not.toThrow()
  })

  it('la campana se actualiza al llegar un aviso por tiempo real y permite apagar el sonido', async () => {
    render(<MemoryRouter><AuthProvider><NotificationBell /></AuthProvider></MemoryRouter>)
    const calls = () => (fetch as unknown as { mock: { calls: unknown[][] } }).mock.calls.filter((c) => String(c[0]).endsWith('/notifications/summary')).length
    await waitFor(() => expect(calls()).toBe(1))
    await startRealtime(7, false)
    channels['users.7.notifications']!['.notice.created']!({ type: 'conversation_message' })
    await waitFor(() => expect(calls()).toBe(2))

    await userEvent.click(screen.getByRole('button', { name: /avisos/i }))
    await userEvent.click(screen.getByRole('checkbox', { name: /sonido/i }))
    expect(soundEnabled()).toBe(false)
  })
})
