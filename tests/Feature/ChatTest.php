<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_adds_exactly_one_channel(): void
    {
        $owner = User::factory()->create();
        $projectId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/projects', [
            'name' => 'Project with chat',
            'priority' => 'medium',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        $channel = Conversation::query()->where('project_id', $projectId)->sole();
        $this->assertSame('project', $channel->type->value);
        $this->assertNull($channel->user_one_id);
        $this->assertNull($channel->user_two_id);
    }

    public function test_project_chat_and_attachments_require_current_membership(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $channel = $this->projectChannel($project);
        $member = User::factory()->create();
        ProjectMember::factory()->for($project)->for($member)->create();
        $message = Message::factory()->for($channel)->create([
            'attachment_path' => "chat/{$channel->id}/private.pdf",
            'attachment_name' => 'private.pdf',
        ]);
        Storage::disk('local')->put($message->attachment_path, 'sample');
        $messages = "/api/v1/conversations/{$channel->id}/messages";
        $attachment = "/api/v1/messages/{$message->id}/attachment";

        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $this->getJson($messages)->assertUnauthorized();
        $this->getJson($attachment)->assertUnauthorized();

        $this->actingAs($member, 'sanctum')->getJson($messages)->assertOk();
        $this->get($attachment)->assertOk();

        $this->actingAs($project->creator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertOk();

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($messages)->assertForbidden();
        $this->postJson($messages, ['body' => 'Outside the project'])->assertForbidden();
        $this->getJson($attachment)->assertForbidden();
    }

    public function test_direct_requires_shared_project_and_is_unique_in_both_directions(): void
    {
        $project = Project::factory()->create();
        $first = $project->creator;
        $second = User::factory()->create();
        $uri = '/api/v1/conversations/direct';

        $this->actingAs($first, 'sanctum')->postJson($uri, ['user_id' => $first->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson($uri, ['user_id' => $second->id])->assertForbidden();
        ProjectMember::factory()->for($project)->for($second)->create();

        $id = $this->actingAs($second, 'sanctum')->postJson($uri, ['user_id' => $first->id])
            ->assertCreated()->assertJsonPath('data.user.id', $first->id)->json('data.id');
        $this->actingAs($first, 'sanctum')->postJson($uri, ['user_id' => $second->id])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.user.id', $second->id);
        $this->assertDatabaseHas('conversations', ['id' => $id, 'user_one_id' => $first->id, 'user_two_id' => $second->id]);

        $message = Message::factory()->create(['conversation_id' => $id]);
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson("/api/v1/conversations/{$id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'Hello'])->assertForbidden();
        $this->getJson("/api/v1/messages/{$message->id}/attachment")->assertForbidden();
    }

    public function test_cursor_paginates_messages_newest_first(): void
    {
        $project = Project::factory()->create();
        $channel = $this->projectChannel($project);
        [$first, $second, $third] = Message::factory()->count(3)->for($channel)->create()->all();
        $uri = "/api/v1/conversations/{$channel->id}/messages";

        $response = $this->actingAs($project->creator, 'sanctum')->getJson($uri.'?per_page=2')->assertOk()
            ->assertJsonPath('data.0.id', $third->id)->assertJsonPath('data.1.id', $second->id);
        $this->getJson($response->json('links.next'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($uri.'?cursor=invalid')->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    public function test_inbox_shows_only_my_conversations_in_activity_order(): void
    {
        $project = Project::factory()->create();
        $owner = $project->creator;
        $empty = Project::factory()->for($owner, 'creator')->create();
        $channel = $this->projectChannel($project);
        $other = User::factory()->create();
        ProjectMember::factory()->for($project)->for($other)->create();
        $direct = Conversation::factory()->create(['user_one_id' => $owner->id, 'user_two_id' => $other->id]);
        Message::factory()->for($channel)->create(['created_at' => now()->subHours(2)]);
        Message::factory()->for($direct)->create(['created_at' => now()->subHour()]);
        $last = Message::factory()->for($direct)->for($other, 'user')->create(['body' => str_repeat('é', 90)]);
        Message::factory()->create(); // A conversation outside the inbox.

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $direct->id)
            ->assertJsonPath('data.0.last_message.id', $last->id)
            ->assertJsonPath('data.0.last_message.body', str_repeat('é', 80))
            ->assertJsonPath('data.0.last_message.sender_name', $other->name)
            ->assertJsonPath('data.0.last_message.has_attachment', false)
            ->assertJsonPath('data.1.project_id', $project->id)
            ->assertJsonPath('data.2.project_id', $empty->id)
            ->assertJsonPath('data.2.last_message', null);
    }

    public function test_inbox_query_count_is_constant_from_one_to_ten(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'sanctum');

        $countQueries = function (int $expected): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount($expected, 'data');

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };

        $addChannel = function () use ($owner): void {
            $project = Project::factory()->for($owner, 'creator')->create();
            Message::factory()->for($this->projectChannel($project))->create();
        };
        $addChannel();
        $one = $countQueries(1);
        for ($i = 0; $i < 9; $i++) {
            $addChannel();
        }
        $this->assertSame($one, $countQueries(10));
    }

    private function projectChannel(Project $project): Conversation
    {
        return Conversation::query()->where('project_id', $project->id)->sole();
    }
}
