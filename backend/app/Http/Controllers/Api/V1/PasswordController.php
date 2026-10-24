<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\PasswordChanger;
use App\Services\SessionEpoch;

/** Cambio de la propia clave. Es la única acción (junto con ver el perfil y salir) que permite una cuenta obligada a cambiarla. */
class PasswordController extends Controller
{
    public function __construct(private readonly PasswordChanger $passwords, private readonly SessionEpoch $epoch) {}

    public function update(ChangePasswordRequest $request): UserResource
    {
        $user = $request->user();
        $this->passwords->change($user, $request->string('current_password')->toString(), $request->string('password')->toString());
        if ($request->hasSession()) {
            $request->session()->regenerate();      // sesión nueva tras cambiar de credenciales
        }
        $this->epoch->adopt($user->refresh());      // esta sesión sigue; las demás no

        return new UserResource($user->load('role.permissions', 'companies', 'branches'));
    }
}
