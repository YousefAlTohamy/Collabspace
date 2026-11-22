<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskStatusTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(array $attributes = []): Task
    {
        return Task::factory()
            ->forProject($this->project->id)
            ->create($attributes);
    }

    private function tryStatus(User $actor, Task $task, string $to): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => $to]);
    }

    public function test_moving_among_open_statuses_matrix(): void
    {
        $open = [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::InReview];

        foreach ($open as $from) {
            foreach ($open as $to) {
                if ($from !== $to) {
                    $this->assertTransition($from, $to, true, ['leader', 'creator', 'assignee']);
                }
            }
        }
    }

    public function test_completing_without_reviewer(): void
    {
        foreach ([TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::InReview] as $from) {
            $this->assertTransition($from, TaskStatus::Completed, false, ['leader', 'assignee']);
        }
    }

    public function test_completing_with_reviewer_set(): void
    {
        foreach ([TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::InReview] as $from) {
            $this->assertTransition($from, TaskStatus::Completed, true, ['leader', 'reviewer']);
        }
    }

    public function test_reopen_is_reviewer_or_leader_only(): void
    {
        foreach ([TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::InReview] as $to) {
            $this->assertTransition(TaskStatus::Completed, $to, true, ['leader', 'reviewer']);
            $this->assertTransition(TaskStatus::Completed, $to, false, ['leader']);
        }
    }

    /** @param list<string> $allowed */
    private function assertTransition(TaskStatus $from, TaskStatus $to, bool $hasReviewer, array $allowed): void
    {
        $actors = [
            'leader' => $this->leader,
            'creator' => $this->member(),
            'assignee' => $this->member(),
            'reviewer' => $this->member(),
            'member' => $this->member(),
        ];
        $factory = Task::factory()->forProject($this->project->id);
        $factory = match ($from) {
            TaskStatus::Todo => $factory,
            TaskStatus::InProgress => $factory->inProgress(),
            TaskStatus::InReview => $factory->inReview(),
            TaskStatus::Completed => $factory->completed(),
        };

        foreach ($actors as $role => $actor) {
            $task = $factory->create([
                'created_by' => $actors['creator']->id,
                'reviewer_id' => $hasReviewer ? $actors['reviewer']->id : null,
            ]);
            $task->assignees()->attach($actors['assignee']);
            $response = $this->tryStatus($actor, $task, $to->value);

            if (! in_array($role, $allowed, true)) {
                $response->assertForbidden();
                $this->assertSame($from, $task->fresh()->status);

                continue;
            }

            $response->assertOk()->assertJsonPath('data.status', $to->value);
            $task->refresh();
            $this->assertSame($to, $task->status);
            if ($to === TaskStatus::Completed) {
                $response->assertJsonPath('data.progress', 100);
                $this->assertNotNull($task->completed_at);
            } elseif ($from === TaskStatus::Completed) {
                $this->assertNull($task->completed_at);
            }
        }
    }

    public function test_manual_progress_only_changes_in_progress_tasks(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->leader, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in_progress', 'progress' => 42])
            ->assertOk()
            ->assertJsonPath('data.progress', 42)
            ->assertJsonPath('data.status', 'in_progress');

        foreach (['in_review', 'todo'] as $status) {
            $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => $status, 'progress' => 15])
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.progress', 42);
        }

        // Completing forces 100 no matter what progress is sent.
        $this->actingAs($this->leader, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed', 'progress' => 10])
            ->assertOk()
            ->assertJsonPath('data.progress', 100);
    }

    public function test_completing_again_preserves_the_completion_time(): void
    {
        $task = Task::factory()->forProject($this->project->id)->completed()->create([
            'completed_at' => now()->subDay(),
        ]);

        $this->tryStatus($this->leader, $task, 'completed')
            ->assertOk()
            ->assertJsonPath('data.completed_at', $task->completed_at->toIso8601String());
    }

    public function test_status_validation(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->leader, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($this->leader, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'todo', 'progress' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');
    }

    public function test_is_overdue_flag(): void
    {
        $me = $this->member();

        $overdue = $this->makeTask(['start_date' => null, 'due_date' => today()->subDay()]);
        $soon = $this->makeTask(['due_date' => today()->addDays(2)]);
        $completedLate = Task::factory()->forProject($this->project->id)->completed()
            ->create(['start_date' => null, 'due_date' => today()->subDay()]);

        $response = $this->actingAs($me, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/tasks?per_page=100")->assertOk();

        $flags = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($flags->get($overdue->id)['is_overdue']);
        $this->assertFalse($flags->get($soon->id)['is_overdue']);
        $this->assertFalse($flags->get($completedLate->id)['is_overdue']);
    }
}
