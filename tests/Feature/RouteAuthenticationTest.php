<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteAuthenticationTest extends TestCase
{
    public function test_api_routes_require_authentication_except_the_four_public_auth_routes(): void
    {
        $public = [
            'api/v1/auth/register',
            'api/v1/auth/login',
            'api/v1/auth/google',
            'api/v1/auth/forgot-password',
            'api/v1/auth/reset-password',
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
                continue;
            }

            if ($uri === 'api/v1/notifications/{id}/read') {
                $uri = str_replace('{id}', '00000000-0000-4000-8000-000000000001', $uri);
            }
            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
            foreach ($route->methods() as $method) {
                if ($method === 'POST' && in_array($uri, $public, true)) {
                    $this->call($method, $path)->assertUnprocessable();

                    continue;
                }

                $response = $this->call($method, $path);
                $this->assertSame(401, $response->status(), "{$method} {$path} must require authentication.");
                if ($method === 'HEAD') {
                    $response->assertHeader('Content-Type', 'application/json')->assertContent('');
                } else {
                    $response->assertExactJson(['message' => 'Unauthenticated.']);
                }
            }
        }
    }
}
