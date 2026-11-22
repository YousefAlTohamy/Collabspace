<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_data_obeys_project_task_and_meeting_rules(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->freezeTime();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $projects = Project::query()->with(['members', 'tasks.assignees'])->get()->keyBy('name');
        $this->assertCount(2, $projects);
        foreach ($projects as $project) {
            $members = $project->members->keyBy('user_id');
            $this->assertSame(ProjectRole::Leader, $members->get($project->created_by)->role);
            $this->assertSame(1, Conversation::query()->where('project_id', $project->id)->count());
            foreach ($project->tasks as $task) {
                foreach ($task->assignees as $assignee) {
                    $this->assertTrue($members->has($assignee->id));
                }
                if ($task->reviewer_id !== null) {
                    $this->assertTrue($members->has($task->reviewer_id));
                }
            }
        }

        $alpha = $projects->get('Alpha');
        $this->assertCount(6, $alpha->members);
        $this->assertCount(4, $alpha->members->pluck('team_id')->unique());
        $this->assertEqualsCanonicalizing(TaskStatus::cases(), $alpha->tasks->pluck('status')->unique()->values()->all());
        $this->assertCount(4, $alpha->files);
        Storage::disk('local')->assertExists($alpha->files->pluck('path')->all());

        $sepetGo = $projects->get('SepetGo');
        $this->assertSame(ProjectStatus::Done, $sepetGo->status);
        $this->assertNotNull($sepetGo->completed_at);
        $this->assertCount(3, $sepetGo->members);
        $this->assertCount(6, $sepetGo->tasks);
        foreach ($sepetGo->tasks as $task) {
            $this->assertSame(TaskStatus::Completed, $task->status);
        }

        $startsAt = Meeting::query()->where('project_id', $alpha->id)->pluck('starts_at');
        $this->assertCount(4, $startsAt);
        $this->assertEqualsCanonicalizing(
            [now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), now()->addWeek()->toDateString()],
            $startsAt->filter(fn ($start) => $start->isFuture())->map->toDateString()->values()->all(),
        );
        $this->assertCount(1, $startsAt->filter(fn ($start) => $start->isPast()));
    }

    public function test_database_seeder_only_adds_demo_accounts_in_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        (new DatabaseSeeder)->setContainer($this->app)->run();

        $this->assertSame(4, Team::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo data cannot be seeded in production.');

        (new DemoSeeder)->run();
    }
}
