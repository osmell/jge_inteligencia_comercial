import { CheckCircle2 } from 'lucide-react'
import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'

interface ToastApi {
  success: (message: string) => void
}

const ToastContext = createContext<ToastApi | null>(null)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<{ id: number; text: string }[]>([])

  const success = useCallback((text: string) => {
    const id = Date.now() + Math.random()
    setMessages((m) => [...m, { id, text }])
    setTimeout(() => setMessages((m) => m.filter((x) => x.id !== id)), 4000)
  }, [])

  const api = useMemo(() => ({ success }), [success])

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[60] flex flex-col gap-2" role="status" aria-live="polite">
        {messages.map((m) => (
          <div key={m.id} className="toast-in pointer-events-auto flex items-center gap-2 rounded-lg border border-success/30 bg-surface px-4 py-3 text-sm font-medium text-ink shadow-[var(--shadow-pop)]">
            <CheckCircle2 className="h-4 w-4 text-success" aria-hidden="true" />
            {m.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useToast(): ToastApi {
  const ctx = useContext(ToastContext)
  return ctx ?? { success: () => undefined }
}
