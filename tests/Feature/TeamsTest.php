<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/teams')->assertUnauthorized();
    }

    public function test_returns_the_four_seeded_teams(): void
    {
        $this->seed(TeamSeeder::class);

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/teams');

        $response->assertOk()->assertJsonCount(4, 'data');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Backend', 'Frontend', 'Marketing', 'UI/UX'], $names);

        // Summary shape only.
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }
}
