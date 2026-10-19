/** Marca provisional JGE (sustituir por el logo original cuando esté disponible). */
export function BrandMark({ className = 'h-9 w-9' }: { className?: string }) {
  return (
    <svg viewBox="0 0 40 40" className={className} role="img" aria-label="JGE">
      <rect width="40" height="40" rx="8" fill="#ffffff" />
      <text x="20" y="25" fontFamily="Fira Sans, Arial, sans-serif" fontSize="15" fontWeight="700" textAnchor="middle" fill="#07579B">JGE</text>
      <rect x="9" y="29" width="22" height="2.5" rx="1.25" fill="#E50918" />
    </svg>
  )
}
