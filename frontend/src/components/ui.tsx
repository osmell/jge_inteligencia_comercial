import { AlertCircle, CheckCircle2, Info, Loader2, Search, TriangleAlert, X } from 'lucide-react'
import type { Paged } from '../lib/usePaged'
import {
  useEffect,
  useRef,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
  type ThHTMLAttributes,
} from 'react'
import { Link } from 'react-router-dom'
import type { Info as InfoSource } from '../lib/glossary'
import { InfoTip } from './InfoTip'

export type FieldErrors = Record<string, string[]>

/* ───────────── Botones ───────────── */

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'

const variants: Record<Variant, string> = {
  primary: 'bg-jge-blue text-white hover:bg-jge-blue-dark border-transparent',
  secondary: 'bg-surface text-ink border-line-strong hover:bg-surface-muted',
  ghost: 'bg-transparent text-jge-blue border-transparent hover:bg-jge-blue-50',
  danger: 'bg-danger text-white hover:opacity-90 border-transparent',
}

export function Button({
  variant = 'primary',
  small = false,
  loading = false,
  icon,
  children,
  className = '',
  disabled,
  ...rest
}: {
  variant?: Variant
  small?: boolean
  loading?: boolean
  icon?: ReactNode
} & ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      className={`inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg border font-medium transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50 ${
        small ? 'min-h-9 px-3 text-sm' : 'min-h-11 px-4 text-sm'
      } ${variants[variant]} ${className}`}
      disabled={disabled || loading}
      {...rest}
    >
      {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : icon}
      {children}
    </button>
  )
}

/** Compatibilidad con el nombre anterior. */
export function PrimaryButton(props: ButtonHTMLAttributes<HTMLButtonElement> & { icon?: ReactNode }) {
  return <Button {...props} />
}

/* ───────────── Contenedores ───────────── */

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-[var(--radius-card)] border border-line bg-surface shadow-[var(--shadow-card)] ${className}`}>{children}</div>
}

export function PageHeader({
  title,
  description,
  action,
  level = 1,
}: {
  title: string
  description?: string
  action?: ReactNode
  level?: 1 | 2
}) {
  const Heading = level === 1 ? 'h1' : 'h2'
  return (
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <Heading className={`font-semibold tracking-tight text-ink ${level === 1 ? 'text-2xl' : 'text-lg'}`}>{title}</Heading>
        {description && <p className="mt-0.5 text-sm text-ink-muted">{description}</p>}
      </div>
      {action}
    </div>
  )
}

/* ───────────── Avisos ───────────── */

const tones = {
  danger: { box: 'border-danger/40 bg-danger-50 text-danger', icon: AlertCircle, label: 'Error' },
  success: { box: 'border-success/40 bg-success-50 text-success', icon: CheckCircle2, label: 'Listo' },
  warning: { box: 'border-warning/40 bg-warning-50 text-warning', icon: TriangleAlert, label: 'Atención' },
  info: { box: 'border-jge-blue/30 bg-info-50 text-jge-blue', icon: Info, label: 'Información' },
} as const

export function Alert({ tone = 'info', children }: { tone?: keyof typeof tones; children: ReactNode }) {
  const t = tones[tone]
  const Icon = t.icon
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={`flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm ${t.box}`}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <p>
        <span className="font-semibold">{t.label}: </span>
        {children}
      </p>
    </div>
  )
}

/** Error de formulario o de carga. Recibe el foco al aparecer para que lo anuncien los lectores de pantalla. */
export function FormError({ message }: { message: string | null }) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (message) ref.current?.focus()
  }, [message])

  if (!message) return null
  return (
    <div ref={ref} tabIndex={-1} className="outline-none">
      <Alert tone="danger">{message}</Alert>
    </div>
  )
}

/* ───────────── Diálogo ───────────── */

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** `wide`: tablas medianas; `extraWide`: varias tablas o descripciones largas (hasta 72 rem, sin pasar del ancho de la pantalla). */
/** Ventanas abiertas, de la más antigua a la más reciente: solo la de arriba atiende Escape y Tab cuando hay una ventana sobre otra. */
const openModals: object[] = []

export function Modal({ title, onClose, children, wide = false, extraWide = false }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean; extraWide?: boolean }) {
  const ref = useRef<HTMLDivElement>(null)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const node = ref.current
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    node?.querySelector<HTMLElement>('input:not([disabled]), select:not([disabled]), textarea')?.focus()

    const token = {}
    openModals.push(token)
    const onKey = (e: KeyboardEvent) => {
      if (openModals[openModals.length - 1] !== token) return
      if (e.key === 'Escape') return onCloseRef.current()
      if (e.key !== 'Tab' || !node) return
      const items = Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE))
      if (items.length === 0) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      openModals.splice(openModals.indexOf(token), 1)
      document.body.style.overflow = overflow
      previous?.focus()
    }
  }, [])

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink/50 p-4 backdrop-blur-[1px]">
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`my-6 w-full rounded-xl bg-surface shadow-[var(--shadow-pop)] ${extraWide ? 'max-w-6xl' : wide ? 'max-w-2xl' : 'max-w-lg'}`}
      >
        <div className="flex items-center justify-between border-b border-line px-5 py-3.5">
          <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
          <button onClick={onClose} aria-label="Cerrar" className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-ink-muted transition-colors hover:bg-canvas">
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
        <div className="px-5 py-4">{children}</div>
      </div>
    </div>
  )
}

/* ───────────── Campos ───────────── */

const inputClass =
  'w-full min-h-11 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink transition-colors placeholder:text-ink-subtle hover:border-ink-subtle disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-subtle aria-[invalid=true]:border-danger'

function FieldMessages({ id, errors, hint }: { id: string; errors?: string[]; hint?: string }) {
  return (
    <>
      {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-ink-muted">{hint}</p>}
      {errors?.map((m) => (
        <p key={m} id={`${id}-error`} className="mt-1 flex items-center gap-1 text-xs font-medium text-danger">
          <AlertCircle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          Error: {m}
        </p>
      ))}
    </>
  )
}

interface FieldProps {
  label: string
  errors?: string[]
  hint?: string
}

const describedBy = (id: string, errors?: string[], hint?: string) =>
  [errors?.length ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined

export function TextField({ label, errors, hint, id, ...rest }: FieldProps & InputHTMLAttributes<HTMLInputElement> & { id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">{label}</label>
      <input id={id} className={inputClass} aria-invalid={!!errors?.length} aria-describedby={describedBy(id, errors, hint)} {...rest} />
      <FieldMessages id={id} errors={errors} hint={hint} />
    </div>
  )
}

export function SelectField({ label, errors, hint, id, children, ...rest }: FieldProps & SelectHTMLAttributes<HTMLSelectElement> & { id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">{label}</label>
      <select id={id} className={inputClass} aria-invalid={!!errors?.length} aria-describedby={describedBy(id, errors, hint)} {...rest}>{children}</select>
      <FieldMessages id={id} errors={errors} hint={hint} />
    </div>
  )
}

export function TextAreaField({ label, errors, hint, id, ...rest }: FieldProps & TextareaHTMLAttributes<HTMLTextAreaElement> & { id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">{label}</label>
      <textarea id={id} rows={3} className={inputClass} aria-invalid={!!errors?.length} aria-describedby={describedBy(id, errors, hint)} {...rest} />
      <FieldMessages id={id} errors={errors} hint={hint} />
    </div>
  )
}

export function CheckField({ label, ...rest }: { label: string } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="flex min-h-9 cursor-pointer items-center gap-2.5 text-sm text-ink has-[:disabled]:cursor-not-allowed has-[:disabled]:text-ink-subtle">
      <input type="checkbox" className="h-4 w-4 cursor-pointer accent-jge-blue" {...rest} />
      {label}
    </label>
  )
}

export function SearchInput({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <div className="relative w-full max-w-xs">
      <label htmlFor="table-search" className="sr-only">{label}</label>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
      <input
        id="table-search"
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder ?? 'Buscar…'}
        className={`${inputClass} pl-9`}
      />
    </div>
  )
}

export function FormActions({ saving, onCancel }: { saving: boolean; onCancel: () => void }) {
  return (
    <div className="flex justify-end gap-2 border-t border-line pt-4">
      <Button type="button" variant="secondary" onClick={onCancel}>Cancelar</Button>
      <Button type="submit" loading={saving}>{saving ? 'Guardando…' : 'Guardar'}</Button>
    </div>
  )
}

/* ───────────── Estado y tablas ───────────── */

/** El estado se comunica con punto + texto, nunca solo con color. */
export function StatusBadge({ active, activeLabel = 'Activo', inactiveLabel = 'Inactivo' }: { active: boolean; activeLabel?: string; inactiveLabel?: string }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${active ? 'border-success/30 bg-success-50 text-success' : 'border-line-strong bg-surface-muted text-ink-muted'}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-success' : 'bg-ink-subtle'}`} aria-hidden="true" />
      {active ? activeLabel : inactiveLabel}
    </span>
  )
}

export function Badge({ children }: { children: ReactNode }) {
  return <span className="inline-flex items-center rounded-md bg-jge-blue-50 px-2 py-0.5 text-xs font-medium text-jge-blue">{children}</span>
}

/**
 * `scroll`: para tablas anchas y largas (reportes). Se desplaza dentro de la propia tarjeta, así la barra horizontal queda a la vista
 * y el encabezado se queda fijo; celdas compactas y sin saltos de línea. Al imprimir se muestra completa.
 */
export function DataTable({ children, label, scroll = false }: { children: ReactNode; label: string; scroll?: boolean }) {
  const box = 'relative ' + (scroll ? 'max-h-[calc(100dvh-7rem)] overflow-auto print:max-h-none print:overflow-visible' : 'overflow-x-auto print:overflow-visible')
  const dense = scroll ? ' [&_th]:px-2.5 [&_th]:py-2 [&_td]:whitespace-nowrap [&_td]:px-2.5 [&_td]:py-2 [&_thead_tr:nth-child(2)_th]:top-[33px]' : ''
  return (
    <Card className="overflow-hidden">
      <div className={box}>
        <table className={`w-full text-left text-sm print:text-[9px]${dense}`} aria-label={label}>{children}</table>
      </div>
    </Card>
  )
}

/** Paginador de una tabla ya cargada (ver `usePaged`). No se muestra si todo cabe en una página. */
export function Pager({ paged: { page, pages, total, size, setPage } }: { paged: Paged }) {
  if (pages <= 1) return null
  const from = (page - 1) * size + 1
  return (
    <div className="mt-3 flex items-center justify-between text-sm text-ink-muted print:hidden">
      <span>{from}–{Math.min(page * size, total)} de {total.toLocaleString('en-US')} · Página {page} de {pages}</span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</Button>
        <Button variant="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>Siguiente</Button>
      </div>
    </div>
  )
}

export const th = 'sticky top-0 whitespace-nowrap border-b border-line bg-surface-muted px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted'
/** Encabezado de columna con una «i» opcional que explica el dato (y su fórmula, si es calculado). */
export function Th({ children, info, right = false, className = '', ...rest }: ThHTMLAttributes<HTMLTableCellElement> & { info?: InfoSource; right?: boolean }) {
  return (
    <th className={`${th}${right ? ' text-right' : ''}${className ? ` ${className}` : ''}`} {...rest}>
      <span className="inline-flex items-center gap-1">
        {children}
        {info && <InfoTip info={info} label={typeof children === 'string' ? children : undefined} />}
      </span>
    </th>
  )
}

export const td = 'border-b border-line px-4 py-3 align-middle'
export const tr = 'transition-colors hover:bg-surface-muted'

/** Tarjeta de un indicador: título, valor y nota. `info` agrega la «i» con la explicación; `to` la vuelve un enlace. */
export function Stat({ label, value, hint, tone, info, to }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'bad' | 'good'; info?: InfoSource; to?: string }) {
  const card = (
    <Card className={`h-full p-4 ${to ? 'transition-colors hover:border-jge-blue' : ''}`}>
      <p className="flex items-center gap-1 text-sm text-ink-muted">{label}{info && !to && <InfoTip info={info} label={label} />}</p>
      <p className={`tabular mt-0.5 text-2xl font-semibold tracking-tight ${tone === 'bad' ? 'text-danger' : tone === 'good' ? 'text-success' : ''}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
  if (!to) return card
  // La «i» queda fuera del enlace: un botón dentro de un enlace no es accesible.
  return (
    <div className="relative">
      <Link to={to} className="block h-full">{card}</Link>
      {info && <span className="absolute right-3 top-3"><InfoTip info={info} label={label} /></span>}
    </div>
  )
}

export function TableSkeleton({ cols, rows = 4 }: { cols: number; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }, (_, r) => (
        <tr key={r} aria-hidden="true">
          {Array.from({ length: cols }, (_, c) => (
            <td key={c} className={td}><div className="skeleton h-4 w-full max-w-[10rem]" /></td>
          ))}
        </tr>
      ))}
    </>
  )
}

export function EmptyRow({ cols, children }: { cols: number; children: ReactNode }) {
  return (
    <tr>
      <td colSpan={cols} className="px-4 py-10 text-center text-sm text-ink-muted">{children}</td>
    </tr>
  )
}

/* ───────────── Confirmación ───────────── */

export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  danger = false,
  busy = false,
  onConfirm,
  onCancel,
}: {
  title: string
  children: ReactNode
  confirmLabel: string
  danger?: boolean
  busy?: boolean
  onConfirm: () => void
  onCancel: () => void
}) {
  return (
    <Modal title={title} onClose={onCancel}>
      <div className="space-y-4">
        <div className="text-sm text-ink-muted">{children}</div>
        <div className="flex justify-end gap-2 border-t border-line pt-4">
          <Button variant="secondary" onClick={onCancel} disabled={busy}>Cancelar</Button>
          <Button variant={danger ? 'danger' : 'primary'} loading={busy} onClick={onConfirm}>{confirmLabel}</Button>
        </div>
      </div>
    </Modal>
  )
}
