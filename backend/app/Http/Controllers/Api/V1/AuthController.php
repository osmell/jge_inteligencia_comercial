<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(LoginRequest $request, AuditLogger $audit): UserResource
    {
        $username = mb_strtolower(trim($request->validated('username')));
        $throttleKey = $username.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $audit->record('auth.locked', username: $username);

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ])->status(429);
        }

        $credentials = ['username' => $username, 'password' => $request->validated('password'), 'is_active' => true];

        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($throttleKey);
            $audit->record('auth.login_failed', username: $username);

            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login', $user);

        return new UserResource($user->load('role.permissions', 'companies', 'branches'));
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $audit->record('auth.logout', $request->user());

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
