<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_projects_creates_one_channel_for_each_assigned_team(): void
    {
        $backend = Team::query()->create(['name' => 'Backend']);
        $frontend = Team::query()->create(['name' => 'Frontend']);
        [$first, $second, $third] = User::factory()->count(3)->create()->all();
        $this->actingAs(User::factory()->create(), 'sanctum');

        for ($i = 0; $i < 2; $i++) {
            $projectId = $this->postJson('/api/v1/projects', [
                'name' => 'Project '.$i,
                'priority' => 'medium',
                'start_date' => today()->toDateString(),
                'end_date' => today()->addDay()->toDateString(),
                'members' => [
                    ['user_id' => $first->id, 'team_id' => (string) $backend->id],
                    ['user_id' => $second->id, 'team_id' => $backend->id],
                    ['user_id' => $third->id, 'team_id' => $frontend->id],
                ],
            ])->assertCreated()->json('data.id');

            $channels = Conversation::query()->where('project_id', $projectId);
            $this->assertSame(1, (clone $channels)->where('type', 'project')->count());
            $this->assertSame(
                [$backend->id, $frontend->id],
                $channels->where('type', 'team')->orderBy('team_id')->pluck('team_id')->all(),
            );
        }
    }

    public function test_adding_two_members_of_the_same_team_reuses_its_channel(): void
    {
        $project = Project::factory()->create();
        $team = Team::query()->create(['name' => 'Backend']);
        $this->actingAs($project->creator, 'sanctum');

        foreach (User::factory()->count(2)->create() as $member) {
            $this->postJson("/api/v1/projects/{$project->id}/members", [
                'user_id' => $member->id, 'team_id' => $team->id,
            ])->assertCreated();
            $this->assertSame(1, Conversation::query()->where('project_id', $project->id)->where('type', 'team')->count());
            $this->teamChannel($project, $team);
        }
    }

    public function test_moving_members_reuses_the_destination_channel_and_keeps_the_empty_channel(): void
    {
        $project = Project::factory()->create();
        $frontend = Team::query()->create(['name' => 'Frontend']);
        $backend = Team::query()->create(['name' => 'Backend']);
        [$first, $second] = User::factory()->count(2)->create()->all();
        $original = $this->assignTeam($project, $frontend, $first, $second);
        $this->actingAs($project->creator, 'sanctum');

        foreach ([$first, $second] as $member) {
            $this->putJson("/api/v1/projects/{$project->id}/members/{$member->id}", ['team_id' => $backend->id])
                ->assertOk()->assertJsonPath('data.team.id', $backend->id);
            $this->assertSame(2, Conversation::query()->where('project_id', $project->id)->where('type', 'team')->count());
            $this->teamChannel($project, $backend);
        }

        $this->assertFalse($project->members()->where('team_id', $frontend->id)->exists());
        $this->assertSame($original->id, $this->teamChannel($project, $frontend)->id);
    }

    public function test_only_current_members_of_the_projects_team_can_use_its_messages_and_downloads(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $owner = $project->creator;
        $backend = Team::query()->create(['name' => 'Backend']);
        $frontend = Team::query()->create(['name' => 'Frontend']);
        [$member, $frontendMember, $outsider] = User::factory()->count(3)->create()->all();
        $channel = $this->assignTeam($project, $backend, $member);
        $this->assignTeam($project, $frontend, $frontendMember);
        $this->assignTeam(Project::factory()->create(), $backend, $outsider);
        $messages = "/api/v1/conversations/{$channel->id}/messages";

        $this->getJson($messages)->assertUnauthorized();
        $id = $this->actingAs($member, 'sanctum')->postJson($messages, [
            'body' => 'Backend notes',
            'attachment' => UploadedFile::fake()->image('notes.png', 1, 1),
        ])->assertCreated()->json('data.id');
        $message = "/api/v1/messages/{$id}";
        $attachment = "/api/v1/messages/{$id}/attachment";
        $this->getJson($messages)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->get($attachment)->assertDownload('notes.png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->patchJson($message, ['body' => 'Updated backend notes'])->assertOk();

        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(2, 'data');
        foreach ([$frontendMember, $owner, $outsider] as $denied) {
            $this->assertChannelForbidden($denied, $messages, $attachment);
        }

        $membership = "/api/v1/projects/{$project->id}/members/{$member->id}";
        $this->actingAs($owner, 'sanctum')->putJson($membership, ['team_id' => $frontend->id])->assertOk();
        $this->assertChannelForbidden($member, $messages, $attachment);
        $this->patchJson($message, ['body' => 'Update after moving team'])->assertForbidden();
        $this->actingAs($owner, 'sanctum')->putJson($membership, ['team_id' => $backend->id])->assertOk();
        $this->actingAs($member, 'sanctum')->getJson($messages)->assertOk();

        $this->actingAs($owner, 'sanctum')->deleteJson($membership)->assertOk();
        $this->assertChannelForbidden($member, $messages, $attachment);
        $this->assertDatabaseHas('conversations', ['id' => $channel->id]);
        $this->assertDatabaseHas('messages', ['id' => $id, 'body' => 'Updated backend notes']);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_inbox_shows_only_permitted_team_channels_with_their_team_and_latest_activity(): void
    {
        $project = Project::factory()->create(['name' => 'Alpha']);
        $backend = Team::query()->create(['name' => 'Backend']);
        $frontend = Team::query()->create(['name' => 'Frontend']);
        $member = User::factory()->create();
        $teamChannel = $this->assignTeam($project, $backend, $member);
        $hiddenChannel = $this->assignTeam($project, $frontend, User::factory()->create());
        $projectChannel = Conversation::query()->where('project_id', $project->id)->where('type', 'project')->sole();
        Message::factory()->for($projectChannel)->create(['created_at' => now()->subHour()]);
        $message = Message::factory()->for($teamChannel)->for($member, 'user')->create();
        Message::factory()->for($hiddenChannel)->create();
        $this->assignTeam(Project::factory()->create(), $backend, User::factory()->create());

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $teamChannel->id)
            ->assertJsonPath('data.0.type', 'team')
            ->assertJsonPath('data.0.project_id', $project->id)
            ->assertJsonPath('data.0.name', 'Alpha · Backend')
            ->assertJsonPath('data.0.team', ['id' => $backend->id, 'name' => 'Backend'])
            ->assertJsonPath('data.0.last_message.id', $message->id)
            ->assertJsonMissingPath('data.0.user')
            ->assertJsonPath('data.1.id', $projectChannel->id)
            ->assertJsonPath('data.1.type', 'project')
            ->assertJsonPath('data.1.name', 'Alpha')
            ->assertJsonMissingPath('data.1.team')
            ->assertJsonMissingPath('data.1.user');
    }

    public function test_inbox_query_count_is_constant_from_one_to_ten_rows_with_team_channels(): void
    {
        $member = User::factory()->create();
        $team = Team::query()->create(['name' => 'Backend']);
        for ($i = 0; $i < 5; $i++) {
            $channel = $this->assignTeam(Project::factory()->create(), $team, $member);
            Message::factory()->for($channel)->for($member, 'user')->create();
        }
        $this->actingAs($member, 'sanctum');
        $queryCounts = [];

        foreach ([1, 10] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson('/api/v1/conversations?per_page='.$size)
                    ->assertOk()->assertJsonCount($size, 'data')
                    ->assertJsonPath('data.0.type', 'team')
                    ->assertJsonPath('meta.total', 10);
                $queryCounts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }

        $this->assertSame($queryCounts[0], $queryCounts[1]);
    }

    public function test_the_functional_index_rejects_a_second_project_channel(): void
    {
        $project = Project::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('conversations_project_team_unique');
        Conversation::createProjectChannel($project);
    }

    public function test_the_functional_index_allows_distinct_teams_and_rejects_a_duplicate(): void
    {
        $project = Project::factory()->create();
        $first = Team::query()->create(['name' => 'Backend']);
        $second = Team::query()->create(['name' => 'Frontend']);
        Conversation::ensureTeamChannel($project->id, $first->id);
        Conversation::ensureTeamChannel($project->id, $second->id);
        $this->assertSame(2, Conversation::query()->where('project_id', $project->id)->where('type', 'team')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('conversations_project_team_unique');
        Conversation::query()->create([
            'project_id' => $project->id,
            'team_id' => $first->id,
            'type' => ConversationType::Team,
        ]);
    }

    public function test_deleting_a_project_removes_project_and_team_chat_attachments(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $team = Team::query()->create(['name' => 'Backend']);
        $teamChannel = $this->assignTeam($project, $team, $member);
        $projectChannel = Conversation::query()->where('project_id', $project->id)->where('type', 'project')->sole();
        $this->actingAs($member, 'sanctum');
        foreach ([$projectChannel, $teamChannel] as $channel) {
            $this->postJson("/api/v1/conversations/{$channel->id}/messages", [
                'attachment' => UploadedFile::fake()->image('notes.png', 1, 1),
            ])->assertCreated();
            Storage::disk('local')->assertExists('chat/'.$channel->id);
        }

        $this->actingAs($project->creator, 'sanctum')->deleteJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertDatabaseMissing('conversations', ['project_id' => $project->id]);
        Storage::disk('local')->assertMissing(['chat/'.$projectChannel->id, 'chat/'.$teamChannel->id]);
    }

    private function assignTeam(Project $project, Team $team, User ...$members): Conversation
    {
        foreach ($members as $member) {
            $project->members()->create(['user_id' => $member->id, 'team_id' => $team->id, 'role' => 'member']);
        }
        Conversation::ensureTeamChannel($project->id, $team->id);

        return $this->teamChannel($project, $team);
    }

    private function teamChannel(Project $project, Team $team): Conversation
    {
        return Conversation::query()->where('project_id', $project->id)->where('team_id', $team->id)->where('type', 'team')->sole();
    }

    private function assertChannelForbidden(User $user, string $messages, string $attachment): void
    {
        $this->actingAs($user, 'sanctum')->getJson($messages)->assertForbidden();
        $this->postJson($messages, ['body' => 'Not a current team member'])->assertForbidden();
        $this->getJson($attachment)->assertForbidden();
    }
}
