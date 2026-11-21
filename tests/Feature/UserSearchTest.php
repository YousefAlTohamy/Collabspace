<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/users?search=ab')->assertUnauthorized();
    }

    public function test_search_must_be_at_least_two_characters(): void
    {
        $token = $this->tokenFor(User::factory()->create());

        $this->withToken($token)->getJson('/api/v1/users?search=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        $this->withToken($token)->getJson('/api/v1/users')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_search_matches_names_and_returns_only_summary_fields(): void
    {
        $searcher = User::factory()->create(['name' => 'Alice Searcher']);
        $token = $this->tokenFor($searcher);

        $target = User::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'phone' => '+201001234567',
            'job_title' => 'Rear Admiral',
        ]);
        User::factory()->create(['name' => 'Alan Turing']);

        $response = $this->withToken($token)->getJson('/api/v1/users?search=grace');

        $response->assertOk();

        $results = $response->json('data');
        $this->assertCount(1, $results);
        $this->assertSame($target->id, $results[0]['id']);

        // Summary only: no email, no phone, nothing else.
        $this->assertSame(['id', 'name', 'avatar_url', 'job_title'], array_keys($results[0]));
    }

    public function test_search_excludes_the_current_user(): void
    {
        $searcher = User::factory()->create(['name' => 'Alice Searcher', 'email' => 'alice@example.com']);
        $token = $this->tokenFor($searcher);

        $response = $this->withToken($token)->getJson('/api/v1/users?search=alice');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_search_matches_email_exactly_only(): void
    {
        $searcher = User::factory()->create(['name' => 'Alice Searcher']);
        $token = $this->tokenFor($searcher);

        User::factory()->create(['name' => 'Zed', 'email' => 'zed@example.com']);

        // Exact email matches.
        $this->withToken($token)->getJson('/api/v1/users?search=zed@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Partial email does not match (email is exact-match only).
        $this->withToken($token)->getJson('/api/v1/users?search=zed@example')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $searcher = User::factory()->create(['name' => 'Alice Searcher']);
        $token = $this->tokenFor($searcher);

        User::factory()->create(['name' => 'john_doe']);
        User::factory()->create(['name' => 'johnXdoe']);
        User::factory()->create(['name' => 'john doe']);

        // Underscore must be matched literally, not as a single-char wildcard.
        $this->withToken($token)->getJson('/api/v1/users?search=john_doe')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'john_doe');

        User::factory()->create(['name' => '100% Dev']);
        User::factory()->create(['name' => '100X Dev']);

        // Percent must be matched literally, not as any-chars wildcard.
        $this->withToken($token)->getJson('/api/v1/users?search=100%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '100% Dev');
    }
}
