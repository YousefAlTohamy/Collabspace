<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AddedToProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_notifications_are_emitted_only_for_new_recipients(): void
    {
        [$actor, $first, $second] = User::factory()->count(3)->create()->all();
        $this->actingAs($actor, 'sanctum');

        $projectId = $this->postJson('/api/v1/projects', [
            'name' => 'Project with notifications',
            'priority' => 'medium',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'members' => [['user_id' => $first->id]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/projects/{$projectId}/members", ['user_id' => $second->id])->assertCreated();

        $task = ['title' => 'A task', 'priority' => 'medium', 'assignee_ids' => [$actor->id, $first->id]];
        $taskId = $this->postJson("/api/v1/projects/{$projectId}/tasks", $task)->assertCreated()->json('data.id');
        $task['assignee_ids'][] = $second->id;
        $this->putJson("/api/v1/tasks/{$taskId}", $task)->assertOk();
        $this->putJson("/api/v1/tasks/{$taskId}", $task)->assertOk();

        $meeting = [
            'title' => 'A meeting',
            'starts_at' => today()->addDay()->setTime(10, 0)->toISOString(),
            'duration_minutes' => 30,
            'project_id' => $projectId,
            'participant_ids' => [$first->id],
        ];
        $meetingId = $this->postJson('/api/v1/meetings', $meeting)->assertCreated()->json('data.id');
        $meeting['participant_ids'][] = $second->id;
        $this->putJson("/api/v1/meetings/{$meetingId}", $meeting)->assertOk();
        $this->putJson("/api/v1/meetings/{$meetingId}", $meeting)->assertOk();

        foreach ([$first, $second] as $recipient) {
            $byType = $recipient->notifications()->get()->groupBy('type');
            $this->assertCount(3, $byType);
            $this->assertCount(1, $byType['project.member_added']);
            $this->assertCount(1, $byType['task.assigned']);
            $this->assertCount(1, $byType['meeting.invited']);
            $this->assertSame($projectId, $byType['project.member_added']->first()->data['project_id']);
            $this->assertSame($taskId, $byType['task.assigned']->first()->data['task_id']);
            $this->assertSame($meetingId, $byType['meeting.invited']->first()->data['meeting_id']);
            $this->assertSame(['id' => $actor->id, 'name' => $actor->name], $byType['meeting.invited']->first()->data['actor']);
        }
        $this->assertFalse($actor->notifications()->exists());
    }

    public function test_notifications_are_private_and_can_be_marked_read(): void
    {
        [$owner, $other] = User::factory()->count(2)->create()->all();
        $olderId = '00000000-0000-4000-8000-000000000003';
        $lowerTiedId = '00000000-0000-4000-8000-000000000001';
        $higherTiedId = '00000000-0000-4000-8000-000000000002';
        $notification = new AddedToProject(1, $other);
        foreach ([
            $lowerTiedId => '2026-11-02 10:00:00',
            $higherTiedId => '2026-11-02 10:00:00',
            $olderId => '2026-11-01 10:00:00',
        ] as $id => $createdAt) {
            $owner->notifications()->create([
                'id' => $id,
                'type' => $notification->databaseType($owner),
                'data' => $notification->toDatabase($owner),
                'created_at' => $createdAt,
            ]);
        }

        $other->notify(new AddedToProject(1, $owner));
        $mine = $owner->notifications()->findOrFail($higherTiedId);
        $foreign = $other->notifications()->firstOrFail();
        $uri = '/api/v1/notifications';

        $this->getJson($uri)->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson($uri.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('unread_count', 3)
            ->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.type', 'project.member_added');
        $response = $this->getJson($uri)->assertOk();
        $this->assertSame([$higherTiedId, $lowerTiedId, $olderId], array_column($response->json('data'), 'id'));
        $this->postJson("{$uri}/{$foreign->id}/read")->assertNotFound();
        $this->postJson("{$uri}/{$mine->id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->getJson($uri)->assertOk()->assertJsonPath('unread_count', 2);
        $this->postJson($uri.'/read-all')->assertOk();
        $this->getJson($uri)->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNull($foreign->fresh()->read_at);
    }
}
