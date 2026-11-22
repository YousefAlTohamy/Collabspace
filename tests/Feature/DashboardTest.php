<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_scoped_counts_lists_and_calendar_series(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'creator')->create(['created_at' => '2026-04-14']);
        Project::factory()->for($owner, 'creator')->count(6)->create(['created_at' => '2026-04-01']);
        Project::factory()->for($owner, 'creator')->done()->create(['created_at' => '2026-04-15']);
        $foreign = Project::factory()->create(['created_at' => '2026-04-15']);

        $noDueDate = Task::factory()->for($project)->inReview()->create(['due_date' => null]);
        $first = Task::factory()->for($project)->create(['due_date' => '2026-04-16']);
        $second = Task::factory()->for($project)->inProgress()->create(['due_date' => '2026-04-16']);
        $thisMonth = Task::factory()->for($project)->completed()->create(['completed_at' => '2026-04-06']);
        $lastMonth = Task::factory()->for($project)->completed()->create(['completed_at' => '2026-03-07']);
        Task::factory()->for($project)->completed()->create(['completed_at' => '2026-04-07']);
        $foreignTask = Task::factory()->for($foreign)->completed()->create(['completed_at' => '2026-04-08']);
        foreach ([$noDueDate, $first, $second, $thisMonth, $lastMonth, $foreignTask] as $task) {
            $task->assignees()->attach($owner);
        }

        $files = ProjectFile::factory()->for($project)->count(6)->create(['created_at' => '2026-04-14']);
        ProjectFile::factory()->for($foreign)->create(['created_at' => '2026-04-15']);
        $upcoming = Meeting::factory()->for($owner, 'organizer')->for($project)->create(['starts_at' => '2026-05-20 10:00:00']);
        Meeting::factory()->for($owner, 'organizer')->for($foreign)->create(['starts_at' => '2026-04-16']);
        Meeting::factory()->for($owner, 'organizer')->for($project)->create(['starts_at' => '2026-04-14']);
        Meeting::factory()->for($foreign, 'project')->create(['starts_at' => '2026-04-17']);

        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.tasks', [
                'pending' => 1, 'in_progress' => 1, 'in_review' => 1, 'completed' => 2, 'total' => 5,
                'completion_rate' => 40, 'completed_this_month' => 1, 'completed_last_month' => 1,
            ])
            ->assertJsonCount(5, 'data.active_projects')
            ->assertJsonPath('data.active_projects.0', [
                'id' => $project->id, 'name' => $project->name, 'progress_percent' => 50,
                'tasks_completed' => 3, 'tasks_total' => 6,
            ])
            ->assertJsonCount(5, 'data.recent_files')
            ->assertJsonPath('data.recent_files.0.id', $files->last()->id)
            ->assertJsonMissingPath('data.recent_files.0.path')
            ->assertJsonPath('data.my_tasks.0.project.id', $project->id)
            ->assertJsonCount(1, 'data.upcoming_meetings')
            ->assertJsonPath('data.upcoming_meetings.0.id', $upcoming->id)
            ->assertJsonCount(12, 'data.project_overview')
            ->assertJsonPath('data.project_overview.0', ['month' => '2026-01', 'completed' => 0])
            ->assertJsonPath('data.project_overview.2', ['month' => '2026-03', 'completed' => 1])
            ->assertJsonPath('data.project_overview.3', ['month' => '2026-04', 'completed' => 2])
            ->assertJsonPath('data.project_overview.11', ['month' => '2026-12', 'completed' => 0]);
        $this->assertSame([$first->id, $second->id, $noDueDate->id], array_column($response->json('data.my_tasks'), 'id'));
    }

    public function test_dashboard_query_count_is_constant_when_lists_grow(): void
    {
        $owner = User::factory()->create();
        $addData = function () use ($owner): void {
            $project = Project::factory()->for($owner, 'creator')->create();
            Task::factory()->for($project)->create(['reviewer_id' => $owner->id])->assignees()->attach($owner);
            ProjectFile::factory()->for($project)->create();
            Meeting::factory()->for($owner, 'organizer')->for($project)->create();
        };
        $queryCount = function (int $expected): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount($expected, 'data.my_tasks');

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };

        $this->actingAs($owner, 'sanctum');
        $addData();
        $one = $queryCount(1);
        for ($i = 0; $i < 9; $i++) {
            $addData();
        }
        $this->assertSame($one, $queryCount(5));
    }
}
