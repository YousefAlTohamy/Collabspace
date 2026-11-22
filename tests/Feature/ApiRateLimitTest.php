<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_api_limit_allows_120_requests_per_minute(): void
    {
        $this->freezeTime();
        $this->actingAs(User::factory()->create(), 'sanctum');

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson('/api/v1/me')->assertOk();
        }

        $this->getJson('/api/v1/me')->assertTooManyRequests();
    }
}
