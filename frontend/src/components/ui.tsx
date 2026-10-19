import { useEffect, useRef, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react'

const inputClass = 'w-full rounded-md border border-line bg-surface px-3 py-2 text-sm'

export type FieldErrors = Record<string, string[]>

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    ref.current?.querySelector<HTMLElement>('input:not([disabled]), select, button')?.focus()
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
      <div ref={ref} role="dialog" aria-modal="true" aria-label={title} className="mt-8 w-full max-w-lg rounded-lg bg-surface p-5 shadow-lg">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button onClick={onClose} aria-label="Cerrar" className="rounded px-2 py-1 text-xl leading-none hover:bg-canvas">×</button>
        </div>
        {children}
      </div>
    </div>
  )
}

export function FormError({ message }: { message: string | null }) {
  if (!message) return null
  return (
    <p role="alert" className="rounded-md border border-danger bg-red-50 px-3 py-2 text-sm text-danger">
      <span className="font-semibold">Error: </span>
      {message}
    </p>
  )
}

function FieldMessages({ id, errors, hint }: { id: string; errors?: string[]; hint?: string }) {
  return (
    <>
      {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-jge-charcoal/70">{hint}</p>}
      {errors?.map((m) => (
        <p key={m} id={`${id}-error`} className="mt-1 text-xs text-danger">Error: {m}</p>
      ))}
    </>
  )
}

interface FieldProps {
  label: string
  errors?: string[]
  hint?: string
}

export function TextField({ label, errors, hint, id, ...rest }: FieldProps & InputHTMLAttributes<HTMLInputElement> & { id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium">{label}</label>
      <input id={id} className={inputClass} aria-invalid={!!errors?.length} aria-describedby={errors?.length ? `${id}-error` : hint ? `${id}-hint` : undefined} {...rest} />
      <FieldMessages id={id} errors={errors} hint={hint} />
    </div>
  )
}

export function SelectField({ label, errors, hint, id, children, ...rest }: FieldProps & SelectHTMLAttributes<HTMLSelectElement> & { id: string }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium">{label}</label>
      <select id={id} className={inputClass} aria-invalid={!!errors?.length} {...rest}>{children}</select>
      <FieldMessages id={id} errors={errors} hint={hint} />
    </div>
  )
}

export function CheckField({ label, ...rest }: { label: string } & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="flex items-center gap-2 text-sm">
      <input type="checkbox" className="h-4 w-4" {...rest} />
      {label}
    </label>
  )
}

/** El estado se comunica con texto, no solo con color. */
export function StatusBadge({ active }: { active: boolean }) {
  return (
    <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-medium ${active ? 'border-success text-success' : 'border-jge-charcoal/40 text-jge-charcoal/70'}`}>
      {active ? 'Activo' : 'Inactivo'}
    </span>
  )
}

export function FormActions({ saving, onCancel }: { saving: boolean; onCancel: () => void }) {
  return (
    <div className="flex justify-end gap-2 pt-2">
      <button type="button" onClick={onCancel} className="rounded-md border border-line px-3 py-2 text-sm">Cancelar</button>
      <button type="submit" disabled={saving} className="rounded-md bg-jge-blue px-4 py-2 text-sm font-semibold text-white hover:bg-jge-blue-dark disabled:opacity-50">
        {saving ? 'Guardando…' : 'Guardar'}
      </button>
    </div>
  )
}

export function PageHeader({ title, action, level = 1 }: { title: string; action?: ReactNode; level?: 1 | 2 }) {
  const Heading = level === 1 ? 'h1' : 'h2'
  return (
    <div className="mb-4 flex items-center justify-between">
      <Heading className="text-xl font-semibold">{title}</Heading>
      {action}
    </div>
  )
}

export function PrimaryButton({ children, ...rest }: { children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button className="rounded-md bg-jge-blue px-3 py-2 text-sm font-semibold text-white hover:bg-jge-blue-dark" {...rest}>
      {children}
    </button>
  )
}

export function DataTable({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-line bg-surface">
      <table className="w-full text-left text-sm" aria-label={label}>{children}</table>
    </div>
  )
}

export const th = 'border-b border-line px-3 py-2 text-xs font-semibold uppercase tracking-wide text-jge-charcoal/70'
export const td = 'border-b border-line px-3 py-2 align-top'
