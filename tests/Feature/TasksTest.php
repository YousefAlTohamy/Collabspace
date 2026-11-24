<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->leader = $this->project->creator;
    }

    private function member(): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($user)->create();

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/projects/1/tasks')->assertUnauthorized();
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->patchJson('/api/v1/tasks/1/status', ['status' => 'todo'])->assertUnauthorized();
    }

    public function test_cross_project_idor_on_every_task_route(): void
    {
        config(['app.debug' => false]);
        $task = Task::factory()->forProject($this->project->id)->create();
        $stranger = Project::factory()->create()->creator;
        $payload = ['title' => 'X', 'priority' => 'low'];

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/tasks", $payload)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/tasks/'.$task->id)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->putJson('/api/v1/tasks/'.$task->id, $payload)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson('/api/v1/tasks/'.$task->id)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->patchJson('/api/v1/tasks/'.$task->id.'/status', ['status' => 'in_progress'])->assertForbidden();
        foreach ([$this->project->id, 999999999] as $projectId) {
            $this->getJson('/api/v1/tasks?project_id='.$projectId)
                ->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
        }
    }

    public function test_member_can_create_a_task(): void
    {
        $assignee = $this->member();

        $response = $this->actingAs($assignee, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'Build login',
            'description' => 'Email + password',
            'priority' => 'high',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-30',
            'reviewer_id' => $this->leader->id,
            'assignee_ids' => [$assignee->id],
            'created_by' => $this->leader->id,
            'project_id' => Project::factory()->create()->id,
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Build login')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonCount(1, 'data.assignees');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Build login',
            'created_by' => $assignee->id,
            'project_id' => $this->project->id,
            'status' => 'todo',
            'completed_at' => null,
        ]);
    }

    public function test_create_rejects_completed_status(): void
    {
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/tasks", [
                'title' => 'Shortcut',
                'status' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_r3_assignees_and_reviewer_must_be_project_members(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/tasks", [
                'title' => 'Bad people',
                'assignee_ids' => [$outsider->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_ids.0');

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/tasks", [
                'title' => 'Bad reviewer',
                'reviewer_id' => $outsider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');

        // R6: due before start.
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/tasks", [
                'title' => 'Bad dates',
                'start_date' => '2026-11-10',
                'due_date' => '2026-11-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_project_task_list_filters_and_shape(): void
    {
        $me = $this->member();

        $todo = Task::factory()->forProject($this->project->id)->create(['title' => 'Alpha todo']);
        $todo->assignees()->sync([$me->id]);

        Task::factory()->forProject($this->project->id)->inProgress()->create(['title' => 'Beta high', 'priority' => 'high']);
        Task::factory()->forProject($this->project->id)->completed()->create(['title' => 'Gamma done']);
        Task::factory()->count(2)->create(); // another project's tasks: never visible

        $response = $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks");
        $response->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame(
            ['id', 'project_id', 'title', 'description', 'status', 'priority', 'progress', 'start_date',
                'due_date', 'completed_at', 'is_overdue', 'reviewer', 'assignees', 'comments_count'],
            array_keys($response->json('data.0')),
        );

        // status filter
        $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks?status=todo")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $todo->id);

        // priority filter
        $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks?priority=high")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Beta high');

        // assignee filter
        $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks?assignee_id={$me->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $todo->id);

        // assigned_to_me
        $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks?assigned_to_me=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $todo->id);

        // search with wildcard escaping
        Task::factory()->forProject($this->project->id)->create(['title' => '100% done']);
        Task::factory()->forProject($this->project->id)->create(['title' => '100X done']);
        $this->actingAs($me, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/tasks?search=100%25")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', '100% done');
    }

    public function test_global_task_list_only_mine_and_project_scope(): void
    {
        $me = $this->member();
        $other = $this->member();

        $mine = Task::factory()->forProject($this->project->id)->create();
        $mine->assignees()->sync([$me->id]);

        Task::factory()->count(2)->create(); // other projects

        // All: only my project's tasks appear.
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project.id', $this->project->id)
            ->assertJsonPath('data.0.project.name', $this->project->name);

        // assigned_to_me filters within scope.
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/tasks?assigned_to_me=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/tasks?assigned_to_me=1')
            ->assertOk()->assertJsonCount(0, 'data');

        // project_id of a project I am not a member of => 403.
        $foreign = Project::factory()->create();
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/tasks?project_id='.$foreign->id)->assertForbidden();
    }

    public function test_team_filter_matches_assignees_in_the_tasks_own_project(): void
    {
        $backend = Team::query()->create(['name' => 'Backend']);
        $frontend = Team::query()->create(['name' => 'Frontend']);
        $firstAssignee = User::factory()->create();
        $secondAssignee = User::factory()->create();
        foreach ([$firstAssignee, $secondAssignee] as $assignee) {
            ProjectMember::factory()->for($this->project)->for($assignee)->for($backend)->create();
        }

        $matching = Task::factory()->for($this->project)->create();
        $matching->assignees()->attach([$firstAssignee->id, $secondAssignee->id]);
        Task::factory()->for($this->project)->create();

        $otherProject = Project::factory()->for($this->leader, 'creator')->create();
        ProjectMember::factory()->for($otherProject)->for($firstAssignee)->for($frontend)->create();
        Task::factory()->for($otherProject)->create()->assignees()->attach($firstAssignee);

        $foreignProject = Project::factory()->create();
        ProjectMember::factory()->for($foreignProject)->for($firstAssignee)->for($backend)->create();
        Task::factory()->for($foreignProject)->create()->assignees()->attach($firstAssignee);

        $this->actingAs($this->leader, 'sanctum');
        foreach (['/api/v1/tasks', '/api/v1/projects/'.$this->project->id.'/tasks'] as $uri) {
            $this->getJson($uri.'?team_id='.$backend->id)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id)
                ->assertJsonPath('meta.total', 1);
        }

        $this->getJson('/api/v1/tasks?team_id='.$backend->id.'&project_id='.$foreignProject->id)->assertForbidden();
        $this->getJson('/api/v1/projects/'.$foreignProject->id.'/tasks?team_id='.$backend->id)->assertForbidden();
        $this->getJson('/api/v1/tasks?team_id=unknown')->assertUnprocessable()->assertJsonValidationErrors('team_id');
    }

    public function test_priority_and_overdue_filters_combine_on_both_task_lists(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 00:00:01', 'UTC'));
        $pastDue = ['due_date' => '2026-04-14'];
        $highPriority = ['priority' => 'high'];
        $highOverdue = Task::factory()->for($this->project)->create($highPriority + $pastDue);
        $lowOverdue = Task::factory()->for($this->project)->create(['priority' => 'low'] + $pastDue);
        Task::factory()->for($this->project)->create($highPriority + ['due_date' => '2026-04-15']);
        Task::factory()->for($this->project)->create($highPriority + ['due_date' => null]);
        Task::factory()->for($this->project)->completed()->create($highPriority + $pastDue);
        $foreign = Task::factory()->create($highPriority + $pastDue);

        $this->actingAs($this->leader, 'sanctum');
        foreach (['/api/v1/tasks', '/api/v1/projects/'.$this->project->id.'/tasks'] as $uri) {
            $overdue = $this->getJson($uri.'?overdue=1')->assertOk()->assertJsonCount(2, 'data');
            $this->assertEqualsCanonicalizing([$highOverdue->id, $lowOverdue->id], array_column($overdue->json('data'), 'id'));

            $this->getJson($uri.'?priority=high&overdue=1')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $highOverdue->id)->assertJsonPath('data.0.is_overdue', true);
            $this->getJson($uri.'?priority=high&overdue=0')->assertOk()->assertJsonCount(4, 'data');
            $this->getJson($uri.'?priority=urgent&overdue=maybe')->assertUnprocessable()
                ->assertJsonValidationErrors(['priority', 'overdue']);
        }

        $this->getJson('/api/v1/tasks?priority=high&overdue=1&project_id='.$foreign->project_id)->assertForbidden();
    }

    public function test_show_update_delete_permissions(): void
    {
        $creator = $this->member();
        $assignee = $this->member();
        $plainMember = $this->member();

        $task = Task::factory()->forProject($this->project->id)->create(['created_by' => $creator->id]);
        $task->assignees()->sync([$assignee->id]);

        // Show: any member.
        $this->actingAs($plainMember, 'sanctum')->getJson('/api/v1/tasks/'.$task->id)->assertOk();

        // Update: creator, assignee and leader ok; plain member forbidden.
        $payload = ['title' => 'Renamed', 'priority' => 'low'];

        $this->actingAs($plainMember, 'sanctum')->putJson('/api/v1/tasks/'.$task->id, $payload)->assertForbidden();

        foreach ([$creator, $assignee, $this->leader] as $allowed) {
            $this->actingAs($allowed, 'sanctum')
                ->putJson('/api/v1/tasks/'.$task->id, $payload)
                ->assertOk()
                ->assertJsonPath('data.title', 'Renamed');
        }

        // Delete: creator or leader only.
        $this->actingAs($assignee, 'sanctum')->deleteJson('/api/v1/tasks/'.$task->id)->assertForbidden();
        $this->actingAs($creator, 'sanctum')->deleteJson('/api/v1/tasks/'.$task->id)
            ->assertOk()->assertJsonStructure(['message']);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_put_updates_assignees_via_sync_and_never_touches_status(): void
    {
        $task = Task::factory()->forProject($this->project->id)->create();
        $old = $this->member();
        $new = $this->member();
        $task->assignees()->sync([$old->id]);

        $this->actingAs($this->leader, 'sanctum')
            ->putJson('/api/v1/tasks/'.$task->id, [
                'title' => $task->title,
                'priority' => $task->priority->value,
                'status' => 'completed', // must be ignored: no rule, no fill
                'progress' => 55,        // must be ignored too
                'assignee_ids' => [$new->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonCount(1, 'data.assignees')
            ->assertJsonPath('data.assignees.0.id', $new->id);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'progress' => 0]);
        $this->assertDatabaseMissing('task_assignees', ['task_id' => $task->id, 'user_id' => $old->id]);
        $this->assertDatabaseHas('task_assignees', ['task_id' => $task->id, 'user_id' => $new->id]);
    }

    public function test_cross_project_idor_on_nested_payload_ids(): void
    {
        $task = Task::factory()->forProject($this->project->id)->create();

        // Reviewer from another project.
        $foreignMember = User::factory()->create();
        $foreign = Project::factory()->create();
        ProjectMember::factory()->for($foreign)->for($foreignMember)->create();

        $this->actingAs($this->leader, 'sanctum')
            ->putJson('/api/v1/tasks/'.$task->id, [
                'title' => $task->title,
                'priority' => 'low',
                'reviewer_id' => $foreignMember->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');

        $this->putJson('/api/v1/tasks/'.$task->id, [
            'title' => $task->title,
            'priority' => 'low',
            'assignee_ids' => [$foreignMember->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_ids.0');
    }

    public function test_r6_task_update_checks_both_effective_dates(): void
    {
        $task = Task::factory()->forProject($this->project->id)->create([
            'start_date' => '2026-11-10',
            'due_date' => '2026-11-20',
        ]);
        $uri = '/api/v1/tasks/'.$task->id;
        $fields = ['title' => $task->title, 'priority' => $task->priority->value];

        $this->actingAs($this->leader, 'sanctum')
            ->putJson($uri, $fields + ['start_date' => '2026-11-21'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->putJson($uri, $fields + ['due_date' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->putJson($uri, $fields + ['start_date' => null, 'due_date' => '2026-11-09'])
            ->assertOk()->assertJsonPath('data.start_date', null)->assertJsonPath('data.due_date', '2026-11-09');
    }

    public function test_list_query_count_constant_for_one_to_ten_tasks(): void
    {
        $me = $this->member();
        $factory = Task::factory()->forProject($this->project->id)->hasAttached($me, [], 'assignees')
            ->state(['reviewer_id' => $me->id]);
        $factory->create();
        $this->actingAs($me, 'sanctum');

        $board = "/api/v1/projects/{$this->project->id}/tasks";
        $global = '/api/v1/tasks';
        $boardCount = $this->countListQueries($board, 1);
        $globalCount = $this->countListQueries($global, 1);

        $factory->count(9)->create();

        $this->assertSame($boardCount, $this->countListQueries($board, 10), 'Project board query count grew.');
        $this->assertSame($globalCount, $this->countListQueries($global, 10), 'Global task list query count grew.');
    }

    private function countListQueries(string $uri, int $expectedTasks): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->getJson($uri)->assertOk()->assertJsonCount($expectedTasks, 'data');

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}
