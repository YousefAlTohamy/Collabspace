<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    public function test_non_debug_api_errors_only_expose_a_message(): void
    {
        config(['app.debug' => false]);

        $this->get('/api/v1/unknown-route')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertJsonCount(1);
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
