<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_user_visibility_and_organizer_only_writes(): void
    {
        $project = Project::factory()->create();
        $guest = $this->member($project);
        $uninvited = $this->member($project);
        $payload = $this->payload() + ['project_id' => $project->id, 'participant_ids' => [$guest->id], 'created_by' => $guest->id];

        $this->getJson('/api/v1/meetings')->assertUnauthorized();
        $id = $this->actingAs($project->creator, 'sanctum')->postJson('/api/v1/meetings', $payload)
            ->assertCreated()->assertJsonPath('data.organizer.id', $project->creator->id)
            ->assertJsonPath('data.project.id', $project->id)->assertJsonCount(2, 'data.participants')
            ->assertJsonPath('data.summary', null)
            ->assertJsonPath('data.ends_at', today()->addDay()->setTime(10, 30)->toISOString())->json('data.id');

        $uri = "/api/v1/meetings/{$id}";
        $entry = ['entries' => [['speaker_id' => $guest->id, 'offset_seconds' => 1, 'text' => 'Hello']]];
        $this->actingAs($guest, 'sanctum')->getJson($uri)->assertOk();
        $this->putJson($uri, $payload)->assertForbidden();
        $this->deleteJson($uri)->assertForbidden();
        $this->postJson($uri.'/transcript', $entry)->assertCreated();
        $this->getJson($uri.'/transcript')->assertOk()->assertJsonPath('data.0.text', 'Hello');

        $this->actingAs($uninvited, 'sanctum')->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($uri)->assertForbidden();
        $this->getJson($uri.'/transcript')->assertForbidden();
        $this->postJson($uri.'/transcript', $entry)->assertForbidden();

        $this->actingAs($project->creator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$guest->id}")->assertOk();
        $this->actingAs($guest, 'sanctum')->getJson($uri)->assertForbidden();
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_removed_organizer_cannot_update_or_delete_a_project_meeting(): void
    {
        $project = Project::factory()->create();
        $organizer = $this->member($project);
        $id = $this->actingAs($organizer, 'sanctum')
            ->postJson('/api/v1/meetings', $this->payload() + ['project_id' => $project->id])
            ->assertCreated()->json('data.id');

        $this->actingAs($project->creator, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$organizer->id}")->assertOk();

        $uri = "/api/v1/meetings/{$id}";
        $this->actingAs($organizer, 'sanctum')
            ->putJson($uri, $this->payload() + ['project_id' => null])->assertForbidden();
        $this->deleteJson($uri)->assertForbidden();
        $this->assertDatabaseHas('meetings', ['id' => $id, 'project_id' => $project->id]);
    }

    public function test_meeting_validation_and_project_participant_rules(): void
    {
        $project = Project::factory()->create();
        $stranger = User::factory()->create();
        $uri = '/api/v1/meetings';

        $this->actingAs($project->creator, 'sanctum');
        foreach ([
            ['starts_at', now()->subMinute()->toISOString()],
            ['duration_minutes', 14],
            ['duration_minutes', 481],
            ['meeting_url', 'http://example.test'],
            ['meeting_url', 'javascript:example'],
            ['meeting_url', 'data:text/plain,example'],
            ['participant_ids', [$project->creator->id]],
        ] as [$field, $value]) {
            $error = $field === 'participant_ids' ? 'participant_ids.0' : $field;
            $this->postJson($uri, [$field => $value] + $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        $this->postJson($uri, $this->payload() + ['project_id' => $project->id, 'participant_ids' => [$stranger->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('participant_ids.0');
        $this->actingAs($stranger, 'sanctum')->postJson($uri, $this->payload() + ['project_id' => $project->id])
            ->assertForbidden();
    }

    public function test_meeting_create_and_update_return_the_same_403_for_missing_and_foreign_projects(): void
    {
        config(['app.debug' => false]);
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $missingId = $foreign->id + 1;
        $forbidden = ['message' => 'This action is unauthorized.'];
        $uri = '/api/v1/meetings';
        $id = $this->actingAs($project->creator, 'sanctum')
            ->postJson($uri, $this->payload() + ['project_id' => $project->id])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseMissing('projects', ['id' => $missingId]);
        foreach ([$foreign->id, $missingId] as $projectId) {
            $payload = $this->payload() + ['project_id' => $projectId];
            $this->postJson($uri, $payload)->assertForbidden()->assertExactJson($forbidden);
            $this->putJson($uri.'/'.$id, $payload)->assertForbidden()->assertExactJson($forbidden);
        }

        $this->assertDatabaseCount('meetings', 1);
        $this->assertDatabaseHas('meetings', ['id' => $id, 'project_id' => $project->id]);
    }

    public function test_unlinked_meeting_requires_participants_to_share_a_project(): void
    {
        $project = Project::factory()->create();
        $guest = $this->member($project);
        $outsider = User::factory()->create();
        $uri = '/api/v1/meetings';

        $this->actingAs($project->creator, 'sanctum')
            ->postJson($uri, $this->payload() + ['participant_ids' => [$outsider->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('participant_ids.0');
        $this->postJson($uri, $this->payload() + ['participant_ids' => [$guest->id]])
            ->assertCreated()->assertJsonPath('data.project', null)->assertJsonCount(2, 'data.participants');
    }

    public function test_organizer_can_edit_with_unchanged_past_start_and_delete(): void
    {
        $meeting = Meeting::factory()->create(['starts_at' => today()->subDay()->setTime(10, 0)]);
        $uri = "/api/v1/meetings/{$meeting->id}";
        $this->actingAs($meeting->organizer, 'sanctum')
            ->putJson($uri, ['starts_at' => $meeting->starts_at->setTimezone('+02:00')->toIso8601String()] + $this->payload())
            ->assertOk()->assertJsonPath('data.starts_at', $meeting->starts_at->toISOString());
        $this->putJson($uri, ['starts_at' => $meeting->starts_at->subDay()->toISOString()] + $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->deleteJson($uri)->assertOk();
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_calendar_filters_inclusive_dates_and_orders_by_start(): void
    {
        $owner = User::factory()->create();
        $later = Meeting::factory()->for($owner, 'organizer')->create(['starts_at' => today()->addDays(8)->setTime(23, 59)]);
        $earlier = Meeting::factory()->for($owner, 'organizer')->create(['starts_at' => today()->addDays(7)]);
        Meeting::factory()->for($owner, 'organizer')->create(['starts_at' => today()->addDays(9)]);
        Meeting::factory()->for($owner, 'organizer')->create(['starts_at' => today()->addDays(40)]);
        Meeting::factory()->create(['starts_at' => today()->addDays(7)]);
        $from = today()->addDays(7)->toDateString();
        $to = today()->addDays(8)->toDateString();

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/meetings?from={$from}&to={$to}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $earlier->id)->assertJsonPath('data.1.id', $later->id);
        $this->getJson('/api/v1/meetings')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/meetings?from={$to}&to={$from}")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_transcript_validates_speakers_and_escapes_like_wildcards(): void
    {
        $meeting = Meeting::factory()->create();
        $stranger = User::factory()->create();
        $uri = "/api/v1/meetings/{$meeting->id}/transcript";
        $this->actingAs($meeting->organizer, 'sanctum')
            ->postJson($uri, ['entries' => [
                ['speaker_id' => $stranger->id, 'offset_seconds' => 0, 'text' => 'Not a guest'],
            ]])->assertUnprocessable()->assertJsonValidationErrors('entries.0.speaker_id');

        $this->postJson($uri, ['entries' => [
            ['speaker_id' => $meeting->created_by, 'offset_seconds' => 10, 'text' => '100%_real'],
            ['offset_seconds' => 1, 'text' => '100XYreal'],
        ]])->assertCreated();

        $this->getJson($uri)->assertOk()->assertJsonPath('data.0.offset_seconds', 1);
        $this->getJson($uri.'?q='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.text', '100%_real');
    }

    private function payload(): array
    {
        return [
            'title' => 'Design review',
            'starts_at' => today()->addDay()->setTime(10, 0)->toISOString(),
            'duration_minutes' => 30,
            'meeting_url' => 'https://example.test/design-review',
        ];
    }

    private function member(Project $project): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($project)->for($user)->create();

        return $user;
    }
}
