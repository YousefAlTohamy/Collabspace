<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

trait IssuesAuthTokens
{
    private function tokenResponse(User $user, bool $remember, int $status = 200): JsonResponse
    {
        $expiresAt = $remember ? now()->addDays(30) : now()->addDay();
        $token = $user->createToken('auth', ['*'], $expiresAt);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ], $status);
    }
}
