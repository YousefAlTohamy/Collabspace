<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): array
    {
        $token = $user->createToken('test', ['*'], now()->addDay())->plainTextToken;

        return [$user, $token];
    }

    public function test_me_returns_exactly_the_expected_fields(): void
    {
        [$user, $token] = $this->actingAsUser(User::factory()->create());

        $response = $this->withToken($token)->getJson('/api/v1/me');

        $response->assertOk();

        $this->assertSame(
            [
                'id', 'name', 'email', 'phone', 'job_title', 'years_of_experience',
                'bio', 'availability_status', 'avatar_url', 'created_at',
            ],
            array_keys($response->json('data')),
        );

        $this->assertSame($user->email, $response->json('data.email'));
        $this->assertNull($response->json('data.avatar_url'));
    }

    public function test_me_updates_allowed_fields_and_ignores_email(): void
    {
        [$user, $token] = $this->actingAsUser(User::factory()->create());

        $response = $this->withToken($token)->putJson('/api/v1/me', [
            'name' => 'Updated Name',
            'email' => 'hacker@example.com',
            'phone' => '+201001234567',
            'job_title' => 'Backend Developer',
            'years_of_experience' => 6,
            'bio' => 'I build APIs.',
            'availability_status' => 'busy',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.availability_status', 'busy')
            ->assertJsonPath('data.years_of_experience', 6);

        $this->assertDatabaseMissing('users', ['id' => $user->id, 'email' => 'hacker@example.com']);
    }

    public function test_me_rejects_invalid_availability_status(): void
    {
        [$user, $token] = $this->actingAsUser(User::factory()->create());

        $this->withToken($token)->putJson('/api/v1/me', [
            'name' => $user->name,
            'availability_status' => 'offline',
        ])->assertUnprocessable()->assertJsonValidationErrors('availability_status');
    }

    public function test_avatar_upload_accepts_png_and_replaces_the_previous_file(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->actingAsUser(User::factory()->create());

        $this->withToken($token)
            ->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('first.png')])
            ->assertOk();

        $firstPath = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        $response = $this->withToken($token)
            ->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('second.png')])
            ->assertOk();

        $secondPath = $user->fresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);

        $response->assertJsonPath('data.avatar_url', Storage::disk('public')->url($secondPath));
    }

    public function test_avatar_rejects_svg_php_and_oversized_files(): void
    {
        Storage::fake('public');
        [$user, $token] = $this->actingAsUser(User::factory()->create());

        $this->withToken($token)
            ->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->withToken($token)
            ->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('x.php', 10, 'application/x-php')])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->withToken($token)
            ->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('big.png')->size(3000)])
            ->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        [$user, $token] = $this->actingAsUser(User::factory()->create(['password' => 'OldSecret1']));

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'WrongPass1',
            'password' => 'NewSecret1',
            'password_confirmation' => 'NewSecret1',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'OldSecret1',
        ])->assertOk();
    }

    public function test_password_change_rejects_password_equal_to_current(): void
    {
        [$user, $token] = $this->actingAsUser(User::factory()->create(['password' => 'OldSecret1']));

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'OldSecret1',
            'password' => 'OldSecret1',
            'password_confirmation' => 'OldSecret1',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'OldSecret1',
        ])->assertOk();
    }

    public function test_password_change_keeps_only_the_current_token(): void
    {
        $user = User::factory()->create(['password' => 'OldSecret1']);
        $currentToken = $user->createToken('current', ['*'], now()->addDay())->plainTextToken;
        $otherToken = $user->createToken('other', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($currentToken)->putJson('/api/v1/me/password', [
            'current_password' => 'OldSecret1',
            'password' => 'NewSecret1',
            'password_confirmation' => 'NewSecret1',
        ])->assertOk()->assertJsonStructure(['message']);

        // Guards cache the resolved user between test requests; force re-authentication.
        Auth::forgetGuards();

        $this->withToken($currentToken)->getJson('/api/v1/me')->assertOk();

        Auth::forgetGuards();

        $this->withToken($otherToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewSecret1',
        ])->assertOk();
    }
}
