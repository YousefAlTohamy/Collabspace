<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleChatAttachments
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasFile('attachment')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'uploads');
        }

        return $next($request);
    }
}
