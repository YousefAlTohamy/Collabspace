<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SignInWithGoogle
{
    /** @param array{sub: string, email: string, name: string, trusted_email: bool} $claims */
    public function execute(array $claims): User
    {
        try {
            return $this->matchOrCreate($claims);
        } catch (UniqueConstraintViolationException) {
            // Retry with a new transaction so the winning write is visible.
            return $this->matchOrCreate($claims);
        }
    }

    /** @param array{sub: string, email: string, name: string, trusted_email: bool} $claims */
    private function matchOrCreate(array $claims): User
    {
        return DB::transaction(function () use ($claims): User {
            $user = User::query()->where('google_id', $claims['sub'])->lockForUpdate()->first();
            if ($user !== null) {
                return $user;
            }

            $user = User::query()->where('email', $claims['email'])->lockForUpdate()->first();
            if ($user?->google_id !== null) {
                if ($user->google_id !== $claims['sub']) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'This email is already linked to another Google account.',
                    ], 409));
                }

                return $user;
            }

            if ($user === null) {
                $user = new User([
                    'name' => $claims['name'],
                    'email' => $claims['email'],
                    'password' => Str::random(64),
                ]);
            } else {
                if (! $claims['trusted_email']) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'This email already has an account. Sign in with your password.',
                    ], 409));
                }

                // R15: An unverified signup may have been made by someone else.
                if ($user->email_verified_at === null) {
                    $user->password = Str::random(64);
                    $user->tokens()->delete();
                }
            }

            $user->google_id = $claims['sub'];
            $user->email_verified_at ??= now();
            $user->save();

            return $user->refresh();
        }, attempts: 2);
    }
}
