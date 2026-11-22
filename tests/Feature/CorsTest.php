<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_only_configured_origins_get_api_cors_headers(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://app.example.test']]);

        foreach (config('cors.allowed_origins') as $origin) {
            $this->options('/api/v1/me', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'authorization, accept',
            ])->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
        }

        $this->options('/api/v1/me', [], [
            'Origin' => 'https://other.example.test',
            'Access-Control-Request-Method' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->get('/up', ['Origin' => 'https://app.example.test'])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
