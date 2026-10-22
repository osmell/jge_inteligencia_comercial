<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** Reglas de las contraseñas, en un solo lugar: las usan el alta de usuarios y el cambio de clave. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public const HINT = 'Mínimo 10 caracteres, con letras y números.';

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->letters()->numbers();
    }
}
