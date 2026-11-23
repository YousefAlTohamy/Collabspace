<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_rates_and_series_follow_the_utc_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00.123456', 'UTC'));
        $owner = User::factory()->create();
        $first = Project::factory()->for($owner, 'creator')->done()->create([
            'created_at' => '2026-02-01', 'completed_at' => '2026-04-02',
        ]);
        $second = Project::factory()->for($owner, 'creator')->done()->create([
            'created_at' => '2026-01-01', 'completed_at' => '2026-03-01',
        ]);
        Project::factory()->for($owner, 'creator')->create(['created_at' => '2026-04-04']);
        Project::factory()->for($owner, 'creator')->done()->create([
            'created_at' => '2026-04-20', 'completed_at' => '2026-04-22',
        ]);
        $foreign = Project::factory()->create(['created_at' => '2026-03-01']);

        $contributor = $this->member($first);
        ProjectMember::factory()->for($second)->for($contributor)->create();
        $commenter = $this->member($first);
        $former = User::factory()->create();

        $this->completed($second, '2026-01-10', '2026-02-20')->assignees()->attach($owner);
        $onTime = $this->completed($first, '2026-02-19', '2026-03-25 23:59:59', '2026-03-25');
        $onTime->assignees()->attach($contributor);
        $open = Task::factory()->for($first)->create(['created_at' => '2026-03-20']);
        $this->completed($first, '2026-04-01', '2026-04-05', '2026-04-04')->assignees()->attach([$owner->id, $contributor->id]);
        $this->completed($second, '2026-04-01', '2026-04-03', '2026-04-03')->assignees()->attach($contributor);
        Task::factory()->for($second)->create(['created_at' => '2026-04-04']);
        $this->completed($first, '2026-04-20', '2026-04-21')->assignees()->attach($commenter);
        $this->completed($foreign, '2026-04-01', '2026-04-13', '2026-04-15');
        TaskComment::factory()->for($onTime, 'task')->for($commenter, 'user')->create(['created_at' => '2026-03-16 12:00:00']);
        TaskComment::factory()->for($open, 'task')->for($former, 'user')->create(['created_at' => '2026-04-06']);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports/overview?period=month')->assertOk()->assertExactJson(['data' => [
            'task_completion_rate' => ['value' => 66.7, 'change_vs_last_period' => 16.7],
            'projects_completion_rate' => ['value' => 66.7, 'change_vs_last_period' => 16.7],
            'teams_activity_rate' => ['value' => 100, 'change_vs_last_period' => 66.7],
            'tasks_vs_deadline' => [['month' => '2026-03', 'value' => 100], ['month' => '2026-04', 'value' => 50]],
            'projects_progress' => [['month' => '2026-03', 'value' => 66.7], ['month' => '2026-04', 'value' => 66.7]],
            'worker_activity' => [['month' => '2026-03', 'value' => 1], ['month' => '2026-04', 'value' => 2]],
        ]]);

        $this->getJson('/api/v1/reports/overview?period=week&project_id='.$first->id)->assertOk()
            ->assertJsonPath('data.task_completion_rate.value', 66.7);
    }

    public function test_deadline_series_excludes_undated_tasks_and_returns_null_for_an_undated_only_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));
        $project = Project::factory()->create();
        $this->completed($project, '2026-03-17', '2026-03-20 23:59:59', '2026-03-20');
        $this->completed($project, '2026-03-17', '2026-03-21', '2026-03-20');
        $this->completed($project, '2026-03-17', '2026-03-22');
        $this->completed($project, '2026-04-01', '2026-04-02');

        $this->actingAs($project->creator, 'sanctum')->getJson('/api/v1/reports/overview?period=month')->assertOk()
            ->assertJsonPath('data.tasks_vs_deadline', [['month' => '2026-03', 'value' => 50], ['month' => '2026-04', 'value' => null]])
            ->assertJsonPath('data.worker_activity', [['month' => '2026-03', 'value' => 3], ['month' => '2026-04', 'value' => 1]]);
    }

    public function test_today_uses_utc_midnight_and_compares_to_the_same_time_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 15:00:00.123456', '+03:00'));
        $project = Project::factory()->create(['created_at' => '2026-04-01']);
        $owner = $project->creator;

        $this->completed($project, '2026-04-01', '2026-04-14 12:00:00', '2026-04-14');
        $this->completed($project, '2026-04-01', '2026-04-14 12:00:01', '2026-04-14')->assignees()->attach($owner);
        $this->completed($project, '2026-04-01', '2026-04-15 00:00:00', '2026-04-15')->assignees()->attach($owner);
        $this->completed($project, '2026-04-01', '2026-04-15 12:00:00', '2026-04-14');
        $this->completed($project, '2026-04-01', '2026-04-15 12:00:01', '2026-04-15');

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/reports/overview?period=today')->assertOk()
            ->assertJsonPath('data.task_completion_rate', ['value' => 80, 'change_vs_last_period' => 60])
            ->assertJsonPath('data.teams_activity_rate', ['value' => 100, 'change_vs_last_period' => 100])
            ->assertJsonPath('data.tasks_vs_deadline', [['month' => '2026-04', 'value' => 50]])
            ->assertJsonPath('data.projects_progress', [['month' => '2026-04', 'value' => 80]])
            ->assertJsonPath('data.worker_activity', [['month' => '2026-04', 'value' => 2]]);
    }

    public function test_report_requests_validate_period_dates_and_project_access(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $missingId = $project->id + 1;
        $forbidden = ['message' => 'This action is unauthorized.'];
        $uri = '/api/v1/reports/overview';
        $this->getJson($uri)->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->getJson($uri.'?period=quarter')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->assertDatabaseMissing('projects', ['id' => $missingId]);
        foreach ([$project->id, $missingId] as $projectId) {
            $this->getJson($uri.'?project_id='.$projectId)->assertForbidden()->assertExactJson($forbidden);
            $this->postJson('/api/v1/reports', $this->reportPayload() + ['project_id' => $projectId])
                ->assertForbidden()->assertExactJson($forbidden);
        }

        $this->postJson('/api/v1/reports', ['end_date' => '2026-02-01'] + $this->reportPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/reports', ['end_date' => '2027-03-03'] + $this->reportPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/reports', ['start_date' => '2026-02-30', 'type' => 'unknown'] + $this->reportPayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'type']);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_submitted_report_stores_a_snapshot_and_is_only_visible_to_its_author(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));
        $project = Project::factory()->create(['created_at' => '2026-02-01']);
        $this->completed($project, '2026-03-01', '2026-03-05');
        $owner = $project->creator;
        $other = $this->member($project);
        $uri = '/api/v1/reports';

        $report = $this->actingAs($owner, 'sanctum')->postJson($uri, $this->reportPayload() + [
            'project_id' => $project->id,
            'user_id' => $other->id,
            'snapshot' => ['forged' => true],
        ])->assertCreated()->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.snapshot.task_completion_rate.value', 100)->json('data');
        $id = $report['id'];
        $snapshot = $report['snapshot'];
        $stored = Report::query()->findOrFail($id);
        $this->assertSame($owner->id, $stored->user_id);
        $this->assertEquals($snapshot, $stored->snapshot);

        Task::factory()->for($project)->create(['created_at' => '2026-03-07']);
        $this->getJson($uri.'/'.$id)->assertOk()
            ->assertJsonPath('data.snapshot.task_completion_rate.value', 100);
        $this->actingAs($other, 'sanctum')->getJson($uri)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($uri.'/'.$id)->assertForbidden();
        $this->postJson($uri, $this->reportPayload())->assertCreated()->assertJsonPath('data.project', null);
        $this->actingAs($owner, 'sanctum')->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_project_completion_timestamp_is_set_preserved_and_cleared(): void
    {
        $firstCompletion = CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC');
        $this->travelTo($firstCompletion);
        $project = Project::factory()->create();
        $uri = '/api/v1/projects/'.$project->id;
        $payload = [
            'name' => $project->name,
            'priority' => $project->priority->value,
            'start_date' => $project->start_date->toDateString(),
            'end_date' => $project->end_date->toDateString(),
        ];

        $this->actingAs($project->creator, 'sanctum')->putJson($uri, $payload + ['status' => 'done'])->assertOk();
        $this->assertSame($firstCompletion->toISOString(), $project->fresh()->completed_at->toISOString());
        $this->travelTo($firstCompletion->addDay());
        $this->putJson($uri, $payload + ['description' => 'Edited while done', 'status' => 'done'])->assertOk();
        $this->assertSame($firstCompletion->toISOString(), $project->fresh()->completed_at->toISOString());
        $this->putJson($uri, $payload + ['status' => 'active'])->assertOk();
        $this->assertNull($project->fresh()->completed_at);
    }

    private function reportPayload(): array
    {
        return ['type' => 'task_performance', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31'];
    }

    private function member(Project $project): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($project)->for($user)->create();

        return $user;
    }

    private function completed(Project $project, string $created, string $completed, ?string $due = null): Task
    {
        return Task::factory()->for($project)->completed()->create([
            'created_at' => $created, 'completed_at' => $completed, 'due_date' => $due,
        ]);
    }
}
