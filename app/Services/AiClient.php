<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AiClient
{
    /** @param list<array{role: string, content: string}> $messages */
    public function chat(array $messages): string
    {
        $base = trim((string) config('services.ai.base_url'));
        $key = trim((string) config('services.ai.api_key'));
        $model = trim((string) config('services.ai.model'));
        $timeout = max(1, (int) config('services.ai.timeout', 30));

        if ($key === '' || $model === '' || ! filter_var($base, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new AiUnavailableException;
        }

        try {
            $response = Http::withToken($key)->timeout($timeout)->withoutRedirecting()
                ->post(rtrim($base, '/').'/chat/completions', ['model' => $model, 'messages' => $messages]);
        } catch (ConnectionException|InvalidArgumentException) {
            throw new AiUnavailableException;
        }

        if (! $response->successful()) {
            Log::warning('AI provider returned an unsuccessful status.', ['status' => $response->status()]);

            throw new AiUnavailableException;
        }

        $answer = $response->json('choices.0.message.content');
        if (! is_string($answer) || trim($answer) === '') {
            throw new AiUnavailableException;
        }

        return trim($answer);
    }
}
