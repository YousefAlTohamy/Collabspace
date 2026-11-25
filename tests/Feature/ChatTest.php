<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Team;
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

        $channel = Conversation::query()->where('project_id', $projectId)->where('type', 'project')->sole();
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

    public function test_author_can_edit_text_without_changing_the_attachment_or_message_order(): void
    {
        Storage::fake('local');
        $this->freezeSecond();
        $project = Project::factory()->create();
        $channel = $this->projectChannel($project);
        $message = Message::factory()->for($channel)->for($project->creator, 'user')->create([
            'body' => 'Original text',
            'attachment_path' => "chat/{$channel->id}/notes.txt",
            'attachment_name' => 'notes.txt',
            'attachment_mime' => 'text/plain',
            'attachment_size' => 5,
        ]);
        Storage::disk('local')->put($message->attachment_path, 'notes');
        $newest = Message::factory()->for($channel)->create();
        $uri = "/api/v1/messages/{$message->id}";

        $this->travel(1)->minute();
        $editedAt = now()->toISOString();
        $this->actingAs($project->creator, 'sanctum')->patchJson($uri, [
            'body' => 'Corrected text',
            'user_id' => $newest->user_id,
            'conversation_id' => $channel->id + 1,
            'attachment_name' => 'replacement.txt',
            'edited_at' => null,
        ])->assertOk()
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.conversation_id', $channel->id)
            ->assertJsonPath('data.user.id', $project->creator->id)
            ->assertJsonPath('data.body', 'Corrected text')
            ->assertJsonPath('data.attachment.name', 'notes.txt')
            ->assertJsonPath('data.created_at', $message->created_at->toISOString())
            ->assertJsonPath('data.edited_at', $editedAt)
            ->assertJsonMissingPath('data.attachment_path');

        $this->travel(1)->minute();
        $this->patchJson($uri, ['body' => 'Corrected text'])->assertOk()->assertJsonPath('data.edited_at', $editedAt);
        $this->getJson("/api/v1/conversations/{$channel->id}/messages")->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.0.edited_at', null)
            ->assertJsonPath('data.1.body', 'Corrected text')
            ->assertJsonPath('data.1.edited_at', $editedAt);
        $this->get($uri.'/attachment')->assertDownload('notes.txt');
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_project_message_edit_requires_authorship_and_current_membership(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        ProjectMember::factory()->for($project)->for($member)->create();
        $message = Message::factory()->for($this->projectChannel($project))->for($member, 'user')->create();
        $uri = "/api/v1/messages/{$message->id}";
        $payload = ['body' => 'Updated'];

        $this->patchJson($uri, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->patchJson($uri, $payload)->assertForbidden();
        $this->actingAs($project->creator, 'sanctum')->patchJson($uri, $payload)->assertForbidden();
        $this->actingAs($member, 'sanctum');
        foreach ([[], ['body' => '   '], ['body' => ['text']], ['body' => str_repeat('x', 5001)]] as $invalid) {
            $this->patchJson($uri, $invalid)->assertUnprocessable()->assertJsonValidationErrors('body');
        }

        $this->actingAs($project->creator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertOk();
        $this->actingAs($member, 'sanctum')->patchJson($uri, $payload)->assertForbidden();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => $message->body, 'edited_at' => null]);
    }

    public function test_direct_message_can_be_edited_by_its_author_only(): void
    {
        $project = Project::factory()->create();
        $other = User::factory()->create();
        ProjectMember::factory()->for($project)->for($other)->create();
        $this->actingAs($project->creator, 'sanctum');
        $conversationId = $this->postJson('/api/v1/conversations/direct', ['user_id' => $other->id])
            ->assertCreated()->json('data.id');
        $id = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Direct message'])
            ->assertCreated()->json('data.id');
        $uri = "/api/v1/messages/{$id}";

        $this->actingAs($other, 'sanctum')->patchJson($uri, ['body' => 'Someone else’s text'])->assertForbidden();
        $this->actingAs($project->creator, 'sanctum')->patchJson($uri, ['body' => 'Edited direct message'])
            ->assertOk()->assertJsonPath('data.body', 'Edited direct message');
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

    public function test_message_search_is_literal_and_scoped_to_the_authorized_conversation(): void
    {
        $project = Project::factory()->create();
        $conversation = $this->projectChannel($project);
        $uri = "/api/v1/conversations/{$conversation->id}/messages";
        $search = $uri.'?'.http_build_query(['q' => '100%_done', 'per_page' => 1]);

        $older = Message::factory()->for($conversation)->create(['body' => 'Draft is 100%_done']);
        Message::factory()->for($conversation)->create(['body' => 'Draft is 100X_done']);
        Message::factory()->for($conversation)->create(['body' => 'Draft is 100%Xdone']);
        $latest = Message::factory()->for($conversation)->create(['body' => 'Final is 100%_done']);
        Message::factory()->for($this->projectChannel(Project::factory()->create()))
            ->create(['body' => 'Another project is 100%_done']);

        $this->getJson($search)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($search)->assertForbidden();

        $first = $this->actingAs($project->creator, 'sanctum')->getJson($search)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $latest->id);
        $this->getJson($first->json('links.next'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('links.next', null);
        $this->getJson($uri.'?q=x')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($uri.'?q='.str_repeat('x', 201))->assertUnprocessable()->assertJsonValidationErrors('q');
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

    public function test_inbox_search_filters_channels_and_other_participants_within_the_visible_inbox(): void
    {
        $firstParticipant = User::factory()->create(['name' => 'Design 100%_ready']);
        $owner = User::factory()->create(['name' => 'Personal handle']);
        $secondParticipant = User::factory()->create(['name' => 'Design 100%_ready team']);
        $project = Project::factory()->for($owner, 'creator')->create(['name' => 'Design 100%_ready project']);
        Project::factory()->for($owner, 'creator')->create(['name' => 'Design 100X_ready']);
        Project::factory()->create(['name' => 'Foreign Design 100%_ready']);
        $team = Team::query()->create(['name' => 'Review team']);
        $project->members()->where('user_id', $owner->id)->update(['team_id' => $team->id]);
        Conversation::ensureTeamChannel($project->id, $team->id);
        $direct = Conversation::factory()->create(['user_one_id' => $firstParticipant->id, 'user_two_id' => $owner->id]);
        $otherDirect = Conversation::factory()->create(['user_one_id' => $owner->id, 'user_two_id' => $secondParticipant->id]);
        Conversation::factory()->create(['user_one_id' => $firstParticipant->id, 'user_two_id' => $secondParticipant->id]);

        $this->actingAs($owner, 'sanctum');
        $literal = '/api/v1/conversations?'.http_build_query(['q' => '100%_ready']);
        $matches = $this->getJson($literal)->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertEqualsCanonicalizing([
            $this->projectChannel($project)->id,
            Conversation::query()->where('project_id', $project->id)->where('team_id', $team->id)->sole()->id,
            $direct->id,
            $otherDirect->id,
        ], array_column($matches->json('data'), 'id'));
        $page = $this->getJson($literal.'&per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($page->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/conversations?q=Review%20team')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'team');
        $this->getJson('/api/v1/conversations?q=Personal%20handle')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/conversations?q=x')->assertUnprocessable()->assertJsonValidationErrors('q');
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
        return Conversation::query()->where('project_id', $project->id)->where('type', 'project')->sole();
    }
}
