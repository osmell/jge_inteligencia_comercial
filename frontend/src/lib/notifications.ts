export type NoticeStatus = 'new' | 'seen' | 'dismissed'
export type NoticeSeverity = 'info' | 'warning' | 'danger'

export interface Notice {
  id: number
  type: string
  type_label: string
  severity: NoticeSeverity
  title: string
  body: string | null
  url: string | null
  status: NoticeStatus
  created_at: string
  company_id: number | null
}

export interface NoticeSummary {
  unread: number
  latest: Notice[]
  paused: boolean
}

export interface NoticePreferences {
  types: { type: string; label: string; enabled: boolean; email: boolean }[]
  paused: boolean
  email: string | null
}

export const SEVERITY_TEXT: Record<NoticeSeverity, string> = { info: 'Informativo', warning: 'Atención', danger: 'Urgente' }
