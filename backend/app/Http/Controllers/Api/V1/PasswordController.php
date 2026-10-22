<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\PasswordChanger;

/** Cambio de la propia clave. Es la única acción (junto con ver el perfil y salir) que permite una cuenta obligada a cambiarla. */
class PasswordController extends Controller
{
    public function __construct(private readonly PasswordChanger $passwords) {}

    public function update(ChangePasswordRequest $request): UserResource
    {
        $user = $request->user();
        $this->passwords->change($user, $request->string('current_password')->toString(), $request->string('password')->toString());
        if ($request->hasSession()) {
            $request->session()->regenerate();      // sesión nueva tras cambiar de credenciales
        }

        return new UserResource($user->refresh()->load('role.permissions', 'companies', 'branches'));
    }
}
