<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AiRole;
use App\Models\AiMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://provider.test/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.base_url' => 'https://provider.test/v1/',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
            'services.ai.timeout' => 30,
        ]);
        Http::preventStrayRequests();
    }

    public function test_ai_sends_only_the_latest_twenty_thread_messages_and_stores_the_pair(): void
    {
        $owner = User::factory()->create(['name' => 'Private user name']);
        $foreign = User::factory()->create();
        Project::factory()->for($owner, 'creator')->create(['name' => 'Private project name']);
        AiMessage::factory()->create(['user_id' => $foreign->id, 'content' => 'Foreign thread']);
        AiMessage::factory()->count(24)->sequence(fn (Sequence $sequence): array => [
            'role' => $sequence->index % 2 === 0 ? AiRole::User : AiRole::Assistant,
            'content' => 'History '.$sequence->index,
        ])->create(['user_id' => $owner->id]);

        $answer = str_repeat('a', 20001);
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $answer]]]])]);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/ai/messages', [
            'content' => 'New question',
            'user_id' => $foreign->id,
            'role' => 'assistant',
        ])->assertCreated()
            ->assertJsonPath('data.user_message.content', 'New question')
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.content', str_repeat('a', 20000))
            ->assertJsonPath('data.assistant_message.role', 'assistant');
        $this->assertSame(26, AiMessage::query()->where('user_id', $owner->id)->count());
        $this->assertSame(1, AiMessage::query()->where('user_id', $foreign->id)->count());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $this->assertSame(['model', 'messages'], array_keys($payload));
            $this->assertSame('test-model', $payload['model']);
            $messages = $payload['messages'];
            $this->assertCount(22, $messages);
            $this->assertSame('system', $messages[0]['role']);
            $this->assertStringContainsString('Collabspace', $messages[0]['content']);
            $this->assertSame(
                array_map(fn (int $i): array => ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => 'History '.$i], range(4, 23)),
                array_slice($messages, 1, 20)
            );
            $this->assertSame(['role' => 'user', 'content' => 'New question'], $messages[21]);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));

            return $request->url() === self::ENDPOINT;
        });
    }

    public function test_missing_configuration_does_not_contact_provider_or_store_messages(): void
    {
        Http::fake();
        config(['services.ai.base_url' => null, 'services.ai.api_key' => null]);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/ai/messages', ['content' => 'Hello'])
            ->assertServiceUnavailable()->assertExactJson(['message' => 'AI assistant is unavailable.']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_messages', 0);
    }

    /** @return array<string, array{string}> */
    public static function providerFailures(): array
    {
        return [
            '500 response' => ['status'],
            'timeout' => ['connection'],
            'empty answer' => ['empty'],
            'invalid answer' => ['invalid'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_is_generic_and_stores_nothing(string $failure): void
    {
        Http::fake([self::ENDPOINT => match ($failure) {
            'status' => Http::response(['message' => 'Private provider diagnostics'], 500),
            'connection' => Http::failedConnection('Private connection diagnostics'),
            'empty' => Http::response(['choices' => [['message' => ['content' => '   ']]]]),
            'invalid' => Http::response(['choices' => [['message' => ['content' => ['unexpected']]]]]),
        }]);
        $user = User::factory()->create();
        $prior = AiMessage::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/ai/messages', ['content' => 'Failed turn'])
            ->assertServiceUnavailable()->assertExactJson(['message' => 'AI assistant is unavailable.']);

        $this->assertDatabaseCount('ai_messages', 1);
        $this->assertDatabaseHas('ai_messages', ['id' => $prior->id, 'content' => $prior->content]);
    }

    public function test_history_is_cursor_paginated_and_only_mine_can_be_deleted(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        [$first, $last] = AiMessage::factory()->count(2)->create(['user_id' => $owner->id])->all();
        $foreign = AiMessage::factory()->create();
        $uri = '/api/v1/ai/messages';

        $this->getJson($uri)->assertUnauthorized();
        $this->postJson($uri, ['content' => 'Hello'])->assertUnauthorized();
        $response = $this->actingAs($owner, 'sanctum')->getJson($uri.'?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $last->id);
        $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('links.next', null);
        $this->postJson($uri, ['content' => str_repeat('a', 4001)])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->deleteJson($uri)->assertOk();
        $this->getJson($uri)->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('ai_messages', ['id' => $foreign->id]);
        Http::assertNothingSent();
    }

    public function test_twenty_first_ai_request_is_throttled_per_user(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(), 'sanctum');
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/ai/messages')->assertOk();
        }

        $this->deleteJson('/api/v1/ai/messages')->assertTooManyRequests();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/ai/messages')->assertOk();
        Http::assertNothingSent();
    }

    public function test_multibyte_reply_fits_the_mysql_text_column(): void
    {
        $answer = str_repeat('🙂', 20001);
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $answer]]]])]);
        $reply = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/ai/messages', ['content' => 'Hello'])->assertCreated()
            ->json('data.assistant_message.content');

        $this->assertSame(str_repeat('🙂', 16383), $reply);
        $this->assertDatabaseCount('ai_messages', 2);
    }
}
