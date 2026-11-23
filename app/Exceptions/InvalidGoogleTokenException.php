<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidGoogleTokenException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Invalid Google credentials.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 401);
    }
}
