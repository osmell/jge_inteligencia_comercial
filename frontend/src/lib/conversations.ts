import { API_URL } from './api'
import type { ConversationSummary, LiveMessage } from './realtime'

export type ChatMessage = LiveMessage
export type ConversationRow = ConversationSummary & { unread: number; can_reply: boolean }
export type Template = { id: number; name: string; body: string; variables: number }
export type Note = { id: number; body: string; author: string | null; created_at: string }
export type MessagesPage = { data: ChatMessage[]; meta: { has_more: boolean; oldest_id: number | null; window_open: boolean; can_reply: boolean } }

/** Archivo de un mensaje (solo funciona con la sesión de quien tiene acceso a la conversación). */
export function attachmentUrl(id: number, inline = true): string {
  return `${API_URL}/conversations/attachments/${id}${inline ? '' : '?inline=0'}`
}

/** Une mensajes por id (el más nuevo reemplaza al anterior) y los deja en orden cronológico. */
export function mergeMessages(current: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  const byId = new Map(current.map((m) => [m.id, m]))
  for (const m of incoming) byId.set(m.id, m)
  return [...byId.values()].sort((a, b) => a.id - b.id)
}

/** Nombre para mostrar: el cliente, el nombre del perfil de WhatsApp o el número. */
export function conversationTitle(c: Pick<ConversationSummary, 'customer' | 'profile_name' | 'wa_number'>): string {
  return c.customer?.name ?? c.profile_name ?? `+${c.wa_number}`
}

/** Hora si es de hoy; «ayer»; o la fecha corta. */
export function formatChatTime(value: string | null, now: Date = new Date()): string {
  if (!value) return ''
  const d = new Date(value)
  const day = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diff = Math.round((day(now) - day(d)) / 86400000)
  if (diff === 0) return d.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
  if (diff === 1) return 'ayer'
  return d.toLocaleDateString('es', { day: '2-digit', month: '2-digit' })
}

export function dayLabel(value: string, now: Date = new Date()): string {
  const d = new Date(value)
  const day = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diff = Math.round((day(now) - day(d)) / 86400000)
  if (diff === 0) return 'Hoy'
  if (diff === 1) return 'Ayer'
  return d.toLocaleDateString('es', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Estado de entrega de un mensaje saliente, en palabras (para lectores de pantalla y como texto de la palomita). */
export const STATUS_TEXT: Record<string, string> = {
  queued: 'Enviando…',
  sent: 'Enviado',
  delivered: 'Entregado',
  read: 'Leído',
  failed: 'No se pudo enviar',
  not_sent: 'No enviado',
}

/** Rellena las variables {{1}}, {{2}}… de una plantilla para mostrar cómo quedará. */
export function renderTemplate(body: string, params: string[]): string {
  return body.replace(/\{\{(\d+)\}\}/g, (_, n: string) => params[Number(n) - 1] || `{{${n}}}`)
}

/** Etiquetas disponibles (la misma lista fija que valida el servidor). */
export const LABELS = ['Cotización', 'Pedido', 'Reclamo', 'Cobranza', 'Urgente'] as const

export type QuickReply = { id: number; title: string; body: string; shared: boolean; mine: boolean; author: string | null }
export type QuotationFile = { id: number; number: string; status: string; amount: string; name: string | null; size: number | null }
export type Candidate = { id: number; code: string; name: string; whatsapp: string | null }

/** Sustituye {nombre} por el nombre del cliente al usar una respuesta rápida. */
export function fillQuickReply(body: string, customerName: string | null): string {
  return body.replace(/\{nombre\}/gi, customerName ?? '').replace(/\s+([,.!?])/g, '$1').trim()
}
