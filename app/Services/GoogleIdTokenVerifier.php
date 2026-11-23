<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GoogleSignInUnavailableException;
use App\Exceptions\InvalidGoogleTokenException;
use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use TypeError;
use UnexpectedValueException;

class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google-sign-in:jwks';

    /** @return array{sub: string, email: string, name: string} */
    public function verify(#[\SensitiveParameter] string $idToken): array
    {
        $clientId = trim((string) config('services.google.client_id'));
        if ($clientId === '') {
            throw new GoogleSignInUnavailableException;
        }

        $kid = $this->keyId($idToken);
        $keys = $this->keys();
        if (! isset($keys[$kid])) {
            $keys = $this->keys(refresh: true);
        }
        if (! isset($keys[$kid])) {
            throw new InvalidGoogleTokenException;
        }

        $previousLeeway = JWT::$leeway;
        try {
            JWT::$leeway = 60;
            $claims = JWT::decode($idToken, $keys);
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException|TypeError) {
            throw new InvalidGoogleTokenException;
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $audience = $claims->aud ?? null;
        $sub = $claims->sub ?? null;
        $email = $claims->email ?? null;
        if (! in_array($claims->iss ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! ($audience === $clientId || (is_array($audience) && in_array($clientId, $audience, true)))
            || ! is_int($claims->exp ?? null) // The library checks the deadline when exp is present.
            || ! is_string($sub) || trim($sub) === '' || mb_strlen($sub) > 64
            || ! is_string($email) || mb_strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! in_array($claims->email_verified ?? null, [true, 'true'], true)) {
            throw new InvalidGoogleTokenException;
        }

        $email = mb_strtolower($email);
        $name = is_string($claims->name ?? null) ? trim($claims->name) : '';

        return ['sub' => $sub, 'email' => $email, 'name' => mb_substr($name !== '' ? $name : Str::beforeLast($email, '@'), 0, 255)];
    }

    private function keyId(#[\SensitiveParameter] string $idToken): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $idToken) !== 1) {
            throw new InvalidGoogleTokenException;
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $idToken, 2)[0]));
        } catch (DomainException) {
            throw new InvalidGoogleTokenException;
        }

        $kid = $header->kid ?? null;
        if (($header->alg ?? null) !== 'RS256' || ! is_string($kid) || $kid === '') {
            throw new InvalidGoogleTokenException;
        }

        return $kid;
    }

    /** @return array<string|int, Key> */
    private function keys(bool $refresh = false): array
    {
        if (! $refresh && ($jwks = Cache::get(self::CACHE_KEY)) !== null) {
            return $this->parseKeys($jwks);
        }

        try {
            $response = Http::timeout(5)->withoutRedirecting()->get(self::CERTS_URL);
        } catch (ConnectionException) {
            throw new GoogleSignInUnavailableException;
        }

        if (! $response->successful()) {
            throw new GoogleSignInUnavailableException;
        }

        $jwks = $response->json();
        $keys = $this->parseKeys($jwks);
        Cache::put(self::CACHE_KEY, $jwks, 3600);

        return $keys;
    }

    /** @return array<string|int, Key> */
    private function parseKeys(mixed $jwks): array
    {
        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null) || ! array_is_list($jwks['keys'])) {
            throw new GoogleSignInUnavailableException;
        }

        $candidates = [];
        foreach ($jwks['keys'] as $key) {
            if (! is_array($key)) {
                throw new GoogleSignInUnavailableException;
            }
            if (($key['kty'] ?? null) !== 'RSA' || ($key['alg'] ?? 'RS256') !== 'RS256' || ($key['use'] ?? 'sig') !== 'sig') {
                continue;
            }

            $kid = $key['kid'] ?? null;
            if (! is_string($kid) || $kid === '' || isset($candidates[$kid])) {
                throw new GoogleSignInUnavailableException;
            }
            $candidates[$kid] = $key;
        }

        try {
            return JWK::parseKeySet(['keys' => array_values($candidates)], 'RS256');
        } catch (InvalidArgumentException|UnexpectedValueException|DomainException|TypeError) {
            throw new GoogleSignInUnavailableException;
        }
    }
}
