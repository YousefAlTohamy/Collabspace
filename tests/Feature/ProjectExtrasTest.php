<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectExtrasTest extends TestCase
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

    private function memberIn(?Team $team): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($user)->create(['team_id' => $team?->id]);

        return $user;
    }

    private function assignedTask(User $assignee, bool $completed = false): Task
    {
        $task = Task::factory()
            ->forProject($this->project->id)
            ->when($completed, fn ($factory) => $factory->completed())
            ->create();
        $task->assignees()->syncWithoutDetaching([$assignee->id]);

        return $task;
    }

    public function test_teams_tab_counts_unassigned_group_and_constant_queries(): void
    {
        $backend = Team::create(['name' => 'Backend']);
        $frontend = Team::create(['name' => 'Frontend']);

        $dev1 = $this->memberIn($backend);
        $dev2 = $this->memberIn($backend);
        $designer = $this->memberIn($frontend);
        $free = $this->memberIn(null);

        // The completed task is shared by two Backend members and one Frontend member.
        $shared = $this->assignedTask($dev1, completed: true);
        $shared->assignees()->syncWithoutDetaching([$dev2->id, $designer->id]);
        $this->assignedTask($dev1);
        $this->assignedTask($dev2);
        $this->assignedTask($free, completed: true);

        $me = $this->memberIn(null);

        DB::enableQueryLog();
        $response = $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/projects/'.$this->project->id.'/teams')->assertOk();
        $first = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        // More members and tasks must not change the query count.
        for ($i = 0; $i < 3; $i++) {
            $this->assignedTask($this->memberIn($backend));
        }

        DB::enableQueryLog();
        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/projects/'.$this->project->id.'/teams')
            ->assertOk();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second, "Teams tab query count grew: {$first} vs {$second}");

        $groups = collect($response->json('data'))->keyBy(fn ($group) => $group['id'] ?? 'unassigned');

        $this->assertTrue($groups->has($backend->id));
        $this->assertTrue($groups->has($frontend->id));
        $this->assertTrue($groups->has('unassigned'));

        $backendGroup = $groups->get($backend->id);
        $backendMembers = collect($backendGroup['members'])->keyBy('user.id');
        $this->assertSame(1, $backendMembers->get($dev1->id)['tasks_done']);
        $this->assertSame(2, $backendMembers->get($dev1->id)['tasks_total']);
        $this->assertSame(1, $backendMembers->get($dev2->id)['tasks_done']);
        $this->assertSame(2, $backendMembers->get($dev2->id)['tasks_total']);

        // Backend at first response: 3 distinct tasks (2 dev1 + 1 dev2), 1 completed => 33.
        $this->assertSame(33, $backendGroup['completed_percent']);

        // Frontend: only the shared task => 100%.
        $this->assertSame(100, $groups->get($frontend->id)['completed_percent']);

        // Unassigned member's task counted in the unassigned group.
        $unassignedMembers = collect($groups->get('unassigned')['members'])->keyBy('user.id');
        $this->assertSame(100, $groups->get('unassigned')['completed_percent']);
        $this->assertSame(1, $unassignedMembers->get($free->id)['tasks_done']);
        $this->assertSame(1, $unassignedMembers->get($free->id)['tasks_total']);
    }

    public function test_teams_tab_requires_membership(): void
    {
        $this->getJson('/api/v1/projects/'.$this->project->id.'/teams')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/projects/'.$this->project->id.'/teams')
            ->assertForbidden();
    }

    public function test_current_project_members_can_see_a_teammates_profile_availability(): void
    {
        $team = Team::create(['name' => 'Backend']);
        $member = $this->memberIn($team);
        $this->actingAs($member, 'sanctum')->putJson('/api/v1/me', [
            'name' => $member->name,
            'availability_status' => 'busy',
        ])->assertOk();

        $uri = '/api/v1/projects/'.$this->project->id;
        $this->actingAs($this->leader, 'sanctum');
        $this->getJson($uri.'/teams')->assertOk()
            ->assertJsonPath('data.0.members.0.user.id', $member->id)
            ->assertJsonPath('data.0.members.0.availability_status', 'busy')
            ->assertJsonMissingPath('data.0.members.0.user.email')
            ->assertJsonMissingPath('data.0.members.0.user.phone');

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($uri.'/teams')->assertForbidden();
    }

    public function test_me_stats_counts_completed_assigned_tasks(): void
    {
        $me = $this->memberIn(null);

        $this->assignedTask($me, completed: true);
        $this->assignedTask($me, completed: true);
        $this->assignedTask($me);
        // Other people's tasks do not count.
        $this->assignedTask($this->memberIn(null), completed: true);

        $this->getJson('/api/v1/me/stats')->assertUnauthorized();

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('data.completed_tasks', 2);
    }

    public function test_r5_project_cannot_be_done_with_open_tasks(): void
    {
        $this->assignedTask($this->leader, completed: true);
        $open = $this->assignedTask($this->leader);

        $payload = [
            'name' => $this->project->name,
            'priority' => 'low',
            'status' => 'done',
            'start_date' => $this->project->start_date->toDateString(),
            'end_date' => $this->project->end_date->toDateString(),
        ];

        $this->actingAs($this->leader, 'sanctum')
            ->putJson('/api/v1/projects/'.$this->project->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // All completed: allowed.
        $this->patchJson("/api/v1/tasks/{$open->id}/status", ['status' => 'completed'])->assertOk();
        $this->actingAs($this->leader, 'sanctum')
            ->putJson('/api/v1/projects/'.$this->project->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        // No tasks at all: allowed too.
        $empty = Project::factory()->create();
        $this->actingAs($empty->creator, 'sanctum')
            ->putJson('/api/v1/projects/'.$empty->id, [
                ...$payload,
                'name' => $empty->name,
                'start_date' => $empty->start_date->toDateString(),
                'end_date' => $empty->end_date->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_done_projects_require_a_leader_to_reactivate_before_tasks_can_open(): void
    {
        $reviewer = $this->memberIn(null);
        $task = Task::factory()->for($this->project)->completed()->create(['reviewer_id' => $reviewer->id]);
        $projectUri = '/api/v1/projects/'.$this->project->id;
        $statusUri = '/api/v1/tasks/'.$task->id.'/status';
        $details = [
            'name' => $this->project->name,
            'priority' => $this->project->priority->value,
            'start_date' => $this->project->start_date->toDateString(),
            'end_date' => $this->project->end_date->toDateString(),
        ];

        $this->actingAs($this->leader, 'sanctum')->putJson($projectUri, $details + ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.progress_percent', 100);
        $this->actingAs($reviewer, 'sanctum')->patchJson($statusUri, ['status' => 'in_progress'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($projectUri.'/tasks', ['title' => 'Follow-up'])
            ->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->putJson($projectUri, $details + ['status' => 'active'])->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->assertDatabaseCount('tasks', 1);

        $this->actingAs($this->leader, 'sanctum')->putJson($projectUri, $details + ['status' => 'active'])->assertOk();
        $this->actingAs($reviewer, 'sanctum')->patchJson($statusUri, ['status' => 'in_progress'])->assertOk();
        $this->postJson($projectUri.'/tasks', ['title' => 'Follow-up'])->assertCreated();
    }

    public function test_progress_percent_in_list_and_detail(): void
    {
        $me = $this->memberIn(null);

        $this->assignedTask($me, completed: true);
        $this->assignedTask($me);
        $this->assignedTask($me);

        // 1 of 3 => 33.
        $list = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects');
        $list->assertOk()
            ->assertJsonPath('data.0.progress_percent', 33)
            ->assertJsonPath('data.0.files_count', 0);

        $detail = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects/'.$this->project->id);
        $detail->assertOk()
            ->assertJsonPath('data.progress_percent', 33)
            ->assertJsonPath('data.tasks.todo', 2)
            ->assertJsonPath('data.tasks.completed', 1)
            ->assertJsonPath('data.tasks.in_progress', 0)
            ->assertJsonPath('data.tasks.in_review', 0)
            ->assertJsonPath('data.tasks.total', 3);

        // No tasks: 0.
        $empty = Project::factory()->create();
        ProjectMember::factory()->for($empty)->for($me)->create();
        $projects = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?status=active')->assertOk();
        $this->assertSame(0, collect($projects->json('data'))->firstWhere('id', $empty->id)['progress_percent']);

        $emptyDetail = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects/'.$empty->id);
        $emptyDetail->assertOk()
            ->assertJsonPath('data.progress_percent', 0)
            ->assertJsonPath('data.tasks.total', 0);
    }

    public function test_r3_removing_a_member_clears_assignments_and_reviewer(): void
    {
        $member = $this->memberIn(null);
        $taskWithAssignment = $this->assignedTask($member);
        $taskReviewed = Task::factory()->forProject($this->project->id)->create(['reviewer_id' => $member->id]);

        // Task of ANOTHER project must be untouched.
        $foreignTask = Task::factory()->create();
        ProjectMember::factory()->for($foreignTask->project)->for($member)->create();
        $foreignTask->assignees()->sync([$member->id]);
        $foreignTask->update(['reviewer_id' => $member->id]);

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->id}")
            ->assertOk();

        $this->assertDatabaseMissing('task_assignees', ['task_id' => $taskWithAssignment->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('tasks', ['id' => $taskReviewed->id, 'reviewer_id' => null]);

        // The foreign project's assignment survives.
        $this->assertDatabaseHas('task_assignees', ['task_id' => $foreignTask->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('tasks', ['id' => $foreignTask->id, 'reviewer_id' => $member->id]);

        // And the removed member lost access.
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/tasks/'.$taskWithAssignment->id)
            ->assertForbidden();
    }

    public function test_removed_member_cannot_mutate_their_previous_contributions(): void
    {
        $member = $this->memberIn(null);
        $task = Task::factory()->forProject($this->project->id)->create(['created_by' => $member->id]);
        $comment = TaskComment::factory()->for($task)->for($member)->create();
        $file = ProjectFile::factory()->for($this->project)->for($member, 'uploader')->create();

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->id}")->assertOk();

        $this->actingAs($member, 'sanctum')->putJson("/api/v1/tasks/{$task->id}", [
            'title' => 'Changed',
            'priority' => 'low',
        ])->assertForbidden();
        $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$task->id}")->assertForbidden();
        $this->putJson("/api/v1/comments/{$comment->id}", ['body' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/comments/{$comment->id}")->assertForbidden();
        $this->deleteJson("/api/v1/files/{$file->id}")->assertForbidden();
    }
}
