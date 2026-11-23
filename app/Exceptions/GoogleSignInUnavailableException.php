<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class GoogleSignInUnavailableException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Google sign-in is unavailable.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }
}
