<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PublicUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Dirección de destino de una integración (webhook de salida): sin acceso a la red interna. Fuera de producción se permite la local para probar. */
class SafeOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (config('security.allow_private_urls') === true) {
            return;
        }
        if (! is_string($value) || ! PublicUrl::isSafe($value)) {
            $fail('La dirección debe ser pública y, en producción, usar HTTPS (no se admiten direcciones locales ni de la red interna).');
        }
    }
}
