<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // 'remember' controls the token lifetime, it is not a user attribute.
        $user = User::create($request->safe()->except('remember'));

        return $this->tokenResponse($user, $this->tokenExpiry($request->boolean('remember')), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null) {
            // Burn one bcrypt round (result ignored) so an unknown email is not
            // measurably faster than a wrong password; both paths cost one hash.
            Hash::make((string) $request->validated('password'));

            return $this->invalidCredentials();
        }

        if (! Hash::check((string) $request->validated('password'), (string) $user->password)) {
            return $this->invalidCredentials();
        }

        return $this->tokenResponse($user, $this->tokenExpiry($request->boolean('remember')));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Logged out.')]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        // Always the same answer, whether or not the email exists.
        return response()->json([
            'message' => 'If the email is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete(); // reset revokes every token
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)]);
        }

        // Generic message: does not reveal whether the email or the token was wrong.
        return response()->json([
            'message' => __('passwords.token'),
            'errors' => ['token' => [__('passwords.token')]],
        ], 422);
    }

    private function tokenExpiry(bool $remember): DateTimeInterface
    {
        // Same lifetime rule for register and login: 1 day, 30 days with remember.
        return $remember ? now()->addDays(30) : now()->addDay();
    }

    private function invalidCredentials(): JsonResponse
    {
        // One generic message for unknown email and wrong password (no user enumeration).
        return response()->json(['message' => __('auth.failed')], 401);
    }

    private function tokenResponse(User $user, DateTimeInterface $expiresAt, int $status = 200): JsonResponse
    {
        $token = $user->createToken('auth', ['*'], $expiresAt);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ], $status);
    }
}
