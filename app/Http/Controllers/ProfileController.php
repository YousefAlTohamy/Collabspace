<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        // 'email' has no rule, so it can never come through validated().
        $request->user()->update($request->validated());

        return new UserResource($request->user()->refresh());
    }

    public function avatar(UpdateAvatarRequest $request): UserResource
    {
        $user = $request->user();
        $previousPath = $user->avatar_path;

        // Store the new file and point the user at it first; only then remove the
        // old one, so a failed store never leaves the user without an avatar.
        // store() generates a random hash name; SVG is blocked by validation.
        $user->update([
            'avatar_path' => $request->file('avatar')->store('avatars', 'public'),
        ]);

        if ($previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

        return new UserResource($user);
    }

    public function password(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);

        // Keep the token used for this request, revoke every other one.
        $user->tokens()
            ->whereKeyNot($request->user()->currentAccessToken()->getKey())
            ->delete();

        return response()->json(['message' => __('Password updated.')]);
    }
}
