<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SessionEpoch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(LoginRequest $request, AuditLogger $audit, SessionEpoch $epoch): UserResource
    {
        $username = mb_strtolower(trim($request->validated('username')));
        $throttleKey = $username.'|'.$request->ip();
        // Además del límite por usuario, un límite por dirección: probar una clave contra muchos usuarios también se frena.
        $ipKey = 'login-ip|'.$request->ip();
        $ipLimit = (int) config('security.login_per_ip_per_minute');

        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            $audit->record('auth.locked', username: $username);

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($ipKey)]),
            ])->status(429);
        }

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $audit->record('auth.locked', username: $username);

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ])->status(429);
        }

        $credentials = ['username' => $username, 'password' => $request->validated('password'), 'is_active' => true];

        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($ipKey);
            $audit->record('auth.login_failed', username: $username);

            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $epoch->adopt($user);
        $audit->record('auth.login', $user);

        return new UserResource($user->load('role.permissions', 'companies', 'branches'));
    }

    public function logout(Request $request, AuditLogger $audit, SessionEpoch $epoch): JsonResponse
    {
        $audit->record('auth.logout', $request->user());

        // Subir la época cierra TODAS las sesiones de la persona y mata cualquier sesión que una petición en curso reviva después.
        $epoch->bump($request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('role.permissions', 'companies', 'branches'));
    }
}
