import emblem from '../assets/brand/logo-emblem.png'
import full from '../assets/brand/logo-full.png'

/** Logo completo de JGE Soluciones Integrales (azul y rojo: usar sobre fondo claro). */
export function BrandLogo({ className = 'h-16 w-auto' }: { className?: string }) {
  return <img src={full} alt="JGE Soluciones Integrales" width={793} height={357} className={className} />
}

/** Emblema circular de JGE (edificio y vehículo), para espacios compactos. */
export function BrandEmblem({ className = 'h-9 w-9' }: { className?: string }) {
  return <img src={emblem} alt="JGE" width={302} height={302} className={className} />
}

/** Logo completo sobre placa blanca, para fondos oscuros como la barra lateral. */
export function BrandPlate({ className = '' }: { className?: string }) {
  return (
    <div className={`rounded-xl bg-white p-3 shadow-sm ${className}`}>
      <BrandLogo className="h-auto w-full" />
    </div>
  )
}
