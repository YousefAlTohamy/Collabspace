<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SignInWithGoogle;
use App\Http\Controllers\Concerns\IssuesAuthTokens;
use App\Http\Requests\GoogleSignInRequest;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Http\JsonResponse;

class GoogleAuthController extends Controller
{
    use IssuesAuthTokens;

    public function __invoke(GoogleSignInRequest $request, GoogleIdTokenVerifier $verifier, SignInWithGoogle $action): JsonResponse
    {
        $claims = $verifier->verify($request->validated('id_token'));

        return $this->tokenResponse($action->execute($claims), $request->boolean('remember'));
    }
}
