<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/auth/google';

    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CLIENT_ID = 'collabspace-test.apps.googleusercontent.com';

    private const KEY_ID = 'primary-key';

    private static OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        self::$key ??= $this->generateKey();
        config(['services.google.client_id' => self::CLIENT_ID]);
        Http::preventStrayRequests();
    }

    public function test_creates_a_user_from_verified_claims_and_issues_a_working_token(): void
    {
        $this->fakeKeys();
        $response = $this->postJson(self::ENDPOINT, [
            'id_token' => $this->sign(['picture' => 'https://example.test/avatar.png'] + $this->claims()),
            'name' => 'Ignored name',
            'email' => 'ignored@example.test',
            'google_id' => 'ignored-subject',
        ])->assertOk()
            ->assertJsonStructure(['user', 'token', 'token_type', 'expires_at'])
            ->assertJsonPath('user.name', 'Google User')
            ->assertJsonPath('user.email', 'google.user@example.test')
            ->assertJsonPath('user.availability_status', 'available')
            ->assertJsonPath('user.avatar_url', null)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonMissingPath('user.password');

        $user = User::query()->sole();
        $this->assertSame('google-subject', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.google_id')
            ->assertJsonMissingPath('data.password');
        Http::assertSentCount(1);
    }

    public function test_links_by_email_then_keeps_using_the_same_user_if_the_google_email_changes(): void
    {
        $this->fakeKeys();
        $password = 'original-password';
        $user = User::factory()->unverified()->create([
            'email' => 'google.user@gmail.com',
            'password' => $password,
        ]);
        $oldTokens = [$user->createToken('laptop')->accessToken, $user->createToken('phone')->accessToken];

        $gmailClaims = ['email' => 'Google.User@GMAIL.COM'] + $this->claims();
        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign($gmailClaims)])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $user->refresh();
        $this->assertSame('google-subject', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::isHashed($user->password));
        $this->assertFalse(Hash::check($password, $user->password));
        foreach ($oldTokens as $oldToken) {
            $this->assertModelMissing($oldToken);
        }
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = $this->sign([
            'email' => 'changed@example.test',
            'aud' => ['other-client', self::CLIENT_ID],
            'iss' => 'accounts.google.com',
            'email_verified' => 'true',
        ] + $this->claims());
        $this->postJson(self::ENDPOINT, ['id_token' => $token])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'google.user@gmail.com');
        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(1);
    }

    public function test_links_a_verified_workspace_user_and_preserves_the_password_and_tokens(): void
    {
        $this->fakeKeys();
        $verifiedAt = now()->subDays(2)->startOfSecond();
        $user = User::factory()->create([
            'email' => 'google.user@example.test',
            'email_verified_at' => $verifiedAt,
        ]);
        $passwordHash = $user->password;
        $oldToken = $user->createToken('laptop')->accessToken;

        $token = $this->sign(['hd' => 'example.test'] + $this->claims());
        $this->postJson(self::ENDPOINT, ['id_token' => $token])
            ->assertOk()->assertJsonPath('user.id', $user->id);

        $user->refresh();
        $this->assertSame('google-subject', $user->google_id);
        $this->assertSame($passwordHash, $user->password);
        $this->assertTrue($user->email_verified_at->equalTo($verifiedAt));
        $this->assertModelExists($oldToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('untrustedHostedDomainClaims')]
    public function test_rejects_linking_an_existing_non_gmail_account_without_a_hosted_domain(array $hostedDomainClaims): void
    {
        $this->fakeKeys();
        $user = User::factory()->unverified()->create(['email' => 'google.user@example.test']);
        $oldToken = $user->createToken('laptop')->accessToken;
        $original = $user->refresh()->getAttributes();

        $token = $this->sign($hostedDomainClaims + $this->claims());
        $this->postJson(self::ENDPOINT, ['id_token' => $token])->assertConflict()
            ->assertExactJson(['message' => 'This email already has an account. Sign in with your password.']);

        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertModelExists($oldToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public static function untrustedHostedDomainClaims(): array
    {
        return [
            'missing' => [[]],
            'empty string' => [['hd' => '']],
            'whitespace' => [['hd' => ' ']],
            'non-string' => [['hd' => true]],
        ];
    }

    public function test_accepts_a_subject_of_255_characters(): void
    {
        $this->fakeKeys();
        $subject = str_repeat('a', 255);

        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign(['sub' => $subject] + $this->claims())])->assertOk();

        $this->assertSame($subject, User::query()->sole()->google_id);
    }

    public function test_rejects_an_email_already_linked_to_a_different_google_subject(): void
    {
        $this->fakeKeys();
        $user = User::factory()->create(['email' => 'google.user@example.test']);
        $user->google_id = 'Google-subject';
        $user->save();

        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign($this->claims())])
            ->assertConflict()->assertExactJson(['message' => 'This email is already linked to another Google account.']);
        $this->assertSame('Google-subject', $user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('invalidClaims')]
    public function test_rejects_invalid_google_claims_with_the_same_generic_error(array $overrides, ?string $missing = null): void
    {
        $this->fakeKeys();
        $claims = $overrides + $this->claims();
        if ($missing !== null) {
            unset($claims[$missing]);
        }

        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign($claims)])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid Google credentials.']);
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong audience' => [['aud' => 'wrong-client']],
            'wrong issuer' => [['iss' => 'https://example.test']],
            'expired token' => [['exp' => 1]],
            'missing expiry' => [[], 'exp'],
            'unverified email' => [['email_verified' => false]],
            'false string' => [['email_verified' => 'false']],
            'missing email' => [[], 'email'],
            'invalid email' => [['email' => 'not-an-email']],
            'empty subject' => [['sub' => '']],
            'oversized subject' => [['sub' => str_repeat('a', 256)]],
        ];
    }

    #[DataProvider('invalidTokenFormats')]
    public function test_rejects_wrong_signatures_and_algorithms(string $kind, int $requests): void
    {
        $this->fakeKeys();
        $claims = $this->claims();
        $token = match ($kind) {
            'wrong key' => JWT::encode($claims, $this->generateKey(), 'RS256', self::KEY_ID),
            'none' => JWT::urlsafeB64Encode(JWT::jsonEncode(['alg' => 'none', 'kid' => self::KEY_ID]))
                .'.'.JWT::urlsafeB64Encode(JWT::jsonEncode($claims)).'.',
            'HS256' => JWT::encode($claims, openssl_pkey_get_details(self::$key)['key'], 'HS256', self::KEY_ID),
            'garbage' => 'not-a-token',
        };

        $this->postJson(self::ENDPOINT, ['id_token' => $token])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid Google credentials.']);
        Http::assertSentCount($requests);
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidTokenFormats(): array
    {
        return [
            'different RSA key' => ['wrong key', 1],
            'unsigned' => ['none', 0],
            'symmetric' => ['HS256', 0],
            'garbage string' => ['garbage', 0],
        ];
    }

    public function test_unknown_key_id_refetches_the_certs_at_most_once_per_minute(): void
    {
        $this->fakeKeys();
        $this->travelTo(now()->startOfSecond());
        $token = JWT::encode($this->claims(), self::$key, 'RS256', 'unknown-key');

        $this->postJson(self::ENDPOINT, ['id_token' => $token])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid Google credentials.']);
        Http::assertSentCount(2);

        $this->postJson(self::ENDPOINT, ['id_token' => $token])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid Google credentials.']);
        Http::assertSentCount(2);

        $this->travel(61)->seconds();
        $this->postJson(self::ENDPOINT, ['id_token' => $token])
            ->assertUnauthorized()->assertExactJson(['message' => 'Invalid Google credentials.']);
        Http::assertSentCount(3);
    }

    public function test_reuses_cached_keys_and_accepts_a_key_published_on_refresh(): void
    {
        $rotatedKey = $this->generateKey();
        Http::fake([
            self::CERTS_URL => Http::sequence()
                ->push($this->jwks(self::$key, self::KEY_ID))
                ->push($this->jwks($rotatedKey, 'rotated-key')),
        ]);

        $token = $this->sign($this->claims());
        $this->postJson(self::ENDPOINT, ['id_token' => $token])->assertOk();
        $this->postJson(self::ENDPOINT, ['id_token' => $token])->assertOk();
        Http::assertSentCount(1);

        $rotated = JWT::encode($this->claims(), $rotatedKey, 'RS256', 'rotated-key');
        $this->postJson(self::ENDPOINT, ['id_token' => $rotated])->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_returns_503_without_contacting_google_if_the_client_id_is_empty(): void
    {
        $this->fakeKeys();
        config(['services.google.client_id' => '']);

        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign($this->claims())])
            ->assertServiceUnavailable()->assertExactJson(['message' => 'Google sign-in is unavailable.']);
        Http::assertNothingSent();
    }

    #[DataProvider('certFailures')]
    public function test_certs_failures_return_a_generic_503(string $kind): void
    {
        Http::fake([
            self::CERTS_URL => match ($kind) {
                'remote error' => Http::response('upstream details', 500),
                'connection' => Http::failedConnection('upstream details'),
                'invalid json' => Http::response('upstream details'),
                'invalid keys' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => self::KEY_ID, 'n' => []]]]),
            },
        ]);

        $this->postJson(self::ENDPOINT, ['id_token' => $this->sign($this->claims())])
            ->assertServiceUnavailable()->assertExactJson(['message' => 'Google sign-in is unavailable.']);
        $this->assertDatabaseCount('users', 0);
    }

    public static function certFailures(): array
    {
        return [
            '500' => ['remote error'],
            'connection failure' => ['connection'],
            'invalid JSON' => ['invalid json'],
            'invalid key set' => ['invalid keys'],
        ];
    }

    public function test_remember_selects_lifetime_and_missing_name_uses_the_email_local_part(): void
    {
        $this->fakeKeys();
        $this->travelTo(now()->startOfSecond());
        $token = $this->sign(['name' => null, 'exp' => now()->subSeconds(20)->timestamp] + $this->claims());

        $this->postJson(self::ENDPOINT, ['id_token' => $token])->assertOk()
            ->assertJsonPath('user.name', 'google.user')
            ->assertJsonPath('expires_at', now()->addDay()->toIso8601String());
        $this->postJson(self::ENDPOINT, ['id_token' => $token, 'remember' => true])->assertOk()
            ->assertJsonPath('expires_at', now()->addDays(30)->toIso8601String());
    }

    public function test_the_shared_login_limiter_enforces_the_ip_wide_ceiling(): void
    {
        $this->fakeKeys();
        $this->freezeTime();

        for ($i = 0; $i < 30; $i++) {
            $this->postJson(self::ENDPOINT, ['id_token' => 'garbage', 'email' => "fixture-{$i}@example.test"])
                ->assertUnauthorized();
        }
        $this->postJson(self::ENDPOINT, ['id_token' => 'garbage', 'email' => 'last@example.test'])
            ->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_google_requests_without_an_email_share_the_five_attempt_login_bucket(): void
    {
        $this->fakeKeys();
        $this->freezeTime();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::ENDPOINT, ['id_token' => 'garbage'])->assertUnauthorized();
        }
        $this->postJson(self::ENDPOINT, ['id_token' => 'garbage'])->assertTooManyRequests();
        Http::assertNothingSent();
    }

    private function fakeKeys(): void
    {
        Http::fake([self::CERTS_URL => Http::response($this->jwks(self::$key, self::KEY_ID))]);
    }

    private function generateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        return $key ?: throw new RuntimeException('Could not generate the test RSA key.');
    }

    private function jwks(OpenSSLAsymmetricKey $key, string $kid): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return ['keys' => [[
            'kid' => $kid,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
        ]]];
    }

    private function claims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-subject',
            'exp' => time() + 3600,
            'email' => 'Google.User@Example.test',
            'email_verified' => true,
            'name' => 'Google User',
        ];
    }

    private function sign(array $claims): string
    {
        return JWT::encode($claims, self::$key, 'RS256', self::KEY_ID);
    }
}
