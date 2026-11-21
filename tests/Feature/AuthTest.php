<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => '  Jane@Example.COM ',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'user' => [
                    'id', 'name', 'email', 'phone', 'job_title', 'years_of_experience',
                    'bio', 'availability_status', 'avatar_url', 'created_at',
                ],
                'token', 'token_type', 'expires_at',
            ]);

        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('token'));

        // Email is stored lowercased and trimmed.
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_token_is_valid(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_register_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'TAKEN@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_register_rejects_weak_password(): void
    {
        // No numbers.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_rejects_password_mismatch(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret456',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token', 'token_type', 'expires_at'])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_wrong_password_and_unknown_email_return_identical_responses(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPass1',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'WrongPass1',
        ]);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();

        $this->assertSame($wrongPassword->getContent(), $unknownEmail->getContent());
    }

    public function test_register_remember_controls_token_lifetime(): void
    {
        $shortLived = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'short@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ]);

        $longLived = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'long@example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'remember' => true,
        ]);

        $this->assertEqualsWithDelta(
            now()->addDay()->getTimestamp(),
            Carbon::parse($shortLived->json('expires_at'))->getTimestamp(),
            60,
        );

        $this->assertEqualsWithDelta(
            now()->addDays(30)->getTimestamp(),
            Carbon::parse($longLived->json('expires_at'))->getTimestamp(),
            60,
        );
    }

    public function test_login_remember_controls_token_lifetime(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        $shortLived = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'remember' => false,
        ]);

        $longLived = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'remember' => true,
        ]);

        $this->assertEqualsWithDelta(
            now()->addDay()->getTimestamp(),
            Carbon::parse($shortLived->json('expires_at'))->getTimestamp(),
            60,
        );

        $this->assertEqualsWithDelta(
            now()->addDays(30)->getTimestamp(),
            Carbon::parse($longLived->json('expires_at'))->getTimestamp(),
            60,
        );
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();

        $currentToken = $user->createToken('current', ['*'], now()->addDay())->plainTextToken;
        $otherToken = $user->createToken('other', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($currentToken)->postJson('/api/v1/auth/logout')->assertOk();

        // Guards cache the resolved user between test requests; force re-authentication.
        Auth::forgetGuards();

        $this->withToken($currentToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($otherToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_protected_route_without_token_returns_401_json(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertUnauthorized()
            ->assertJsonStructure(['message']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_forgot_password_always_answers_the_same_and_only_notifies_existing_users(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        $existing->assertOk();
        $unknown->assertOk();
        $this->assertSame($existing->getContent(), $unknown->getContent());

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_reset_password_succeeds_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['password' => 'OldSecret1']);
        $oldToken = $user->createToken('old', ['*'], now()->addDay())->plainTextToken;
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'NewSecret1',
            'password_confirmation' => 'NewSecret1',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewSecret1',
        ])->assertOk();
    }

    public function test_reset_password_with_invalid_token_fails_generically(): void
    {
        $user = User::factory()->create(['password' => 'OldSecret1']);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'NewSecret1',
            'password_confirmation' => 'NewSecret1',
        ]);

        $response->assertUnprocessable()->assertJsonStructure(['message', 'errors']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'OldSecret1',
        ])->assertOk();
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'WrongPass1',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPass1',
        ])->assertStatus(429)->assertJsonStructure(['message']);
    }

    public function test_login_ip_wide_limit_blocks_email_rotation(): void
    {
        // 30 attempts, each with a different email: no single email|ip bucket
        // exceeds 5, but the ip-only bucket counts every attempt.
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "ghost{$attempt}@example.com",
                'password' => 'WrongPass1',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'another-ghost@example.com',
            'password' => 'WrongPass1',
        ])->assertStatus(429)->assertJsonStructure(['message']);
    }
}
