<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\SendScheduledNotifications;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScheduledNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-22 23:50:00', 'UTC');
        $this->travelTo($this->now);
    }

    public function test_meeting_reminders_are_sent_once_to_current_project_participants(): void
    {
        $project = Project::factory()->create();
        $organizer = $project->creator;
        [$member, $removed] = User::factory()->count(2)->create()->all();
        $project->members()->createMany([
            ['user_id' => $member->id, 'role' => 'member'],
            ['user_id' => $removed->id, 'role' => 'member'],
        ]);
        $meeting = Meeting::factory()->for($project)->for($organizer, 'organizer')->create([
            'title' => 'Planning',
            'starts_at' => $this->now->addMinutes(30),
        ]);
        $meeting->participants()->attach([$member->id, $removed->id]);
        $this->actingAs($organizer, 'sanctum')
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$removed->id}")
            ->assertOk();
        $this->assertTrue($meeting->participants()->whereKey($removed->id)->exists());

        foreach ([$this->now->subMinute(), $this->now, $this->now->addMinutes(31)] as $startsAt) {
            Meeting::factory()->for($organizer, 'organizer')->create(['starts_at' => $startsAt]);
        }

        $this->artisan('notifications:send-reminders')
            ->expectsOutput('Meeting reminders sent: 2')
            ->expectsOutput('Overdue task notifications sent: 0')
            ->assertSuccessful();
        $this->artisan('notifications:send-reminders')
            ->expectsOutput('Meeting reminders sent: 0')
            ->expectsOutput('Overdue task notifications sent: 0')
            ->assertSuccessful();

        foreach ([$organizer, $member] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame('meeting.reminder', $notification->type);
            $this->assertSame([
                'type' => 'meeting.reminder',
                'message' => 'Your meeting starts within 30 minutes.',
                'meeting_id' => $meeting->id,
                'project_id' => $project->id,
                'meeting_title' => 'Planning',
                'starts_at' => $this->now->addMinutes(30)->toISOString(),
            ], $notification->data);
        }
        $this->assertDatabaseCount('notifications', 2);
        $this->assertEquals($this->now, $meeting->fresh()->reminder_sent_at);
    }

    public function test_rescheduling_a_meeting_clears_the_flag_and_allows_another_reminder(): void
    {
        $meeting = Meeting::factory()->create(['starts_at' => $this->now->addMinutes(15)]);
        $action = app(SendScheduledNotifications::class);
        $this->assertSame(1, $action->execute($this->now)['meeting_reminders']);

        $this->actingAs($meeting->organizer, 'sanctum');
        $path = "/api/v1/meetings/{$meeting->id}";
        $payload = ['title' => $meeting->title, 'duration_minutes' => 30];
        $this->putJson($path, $payload + ['starts_at' => $meeting->starts_at->toISOString()])->assertOk();
        $this->assertEquals($this->now, $meeting->fresh()->reminder_sent_at);

        $newStart = $this->now->addMinutes(25);
        $this->putJson($path, $payload + ['starts_at' => $newStart->toISOString()])->assertOk();
        $this->assertNull($meeting->fresh()->reminder_sent_at);
        $this->assertSame(1, $action->execute($this->now)['meeting_reminders']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['type' => 'meeting.reminder', 'data->starts_at' => $newStart->toISOString()]);
    }

    public function test_overdue_open_tasks_notify_their_assignees_once_with_whole_utc_days(): void
    {
        $project = Project::factory()->create();
        $assignee = User::factory()->create();
        $project->members()->create(['user_id' => $assignee->id, 'role' => 'member']);
        $task = Task::factory()->for($project)->create([
            'title' => 'Finish the review',
            'due_date' => $this->now->subDays(2)->toDateString(),
        ]);
        $task->assignees()->attach([$project->created_by, $assignee->id]);
        $completed = Task::factory()->for($project)->completed()->create(['due_date' => $task->due_date]);
        $withoutDueDate = Task::factory()->for($project)->create(['due_date' => null]);
        $dueToday = Task::factory()->for($project)->create(['due_date' => $this->now->toDateString()]);
        foreach ([$completed, $withoutDueDate, $dueToday] as $ignored) {
            $ignored->assignees()->attach($assignee);
        }

        $action = app(SendScheduledNotifications::class);
        $this->assertSame(2, $action->execute($this->now)['overdue_task_notifications']);
        $this->assertSame(0, $action->execute($this->now)['overdue_task_notifications']);
        foreach ([$project->creator, $assignee] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame('task.overdue', $notification->type);
            $this->assertSame([
                'type' => 'task.overdue',
                'message' => 'A task assigned to you is overdue.',
                'task_id' => $task->id,
                'project_id' => $project->id,
                'task_title' => 'Finish the review',
                'days_overdue' => 2,
            ], $notification->data);
        }
        $this->assertEquals($this->now, $task->fresh()->overdue_notified_at);
    }

    public function test_moving_a_due_date_to_the_future_starts_a_new_overdue_episode(): void
    {
        $task = Task::factory()->create(['due_date' => $this->now->subDay()->toDateString(), 'start_date' => null]);
        $task->assignees()->attach($task->project->created_by);
        $action = app(SendScheduledNotifications::class);
        $this->assertSame(1, $action->execute($this->now)['overdue_task_notifications']);

        $newDue = $this->now->addDay();
        $this->actingAs($task->project->creator, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => $task->title,
                'priority' => $task->priority->value,
                'due_date' => $newDue->toDateString(),
            ])->assertOk();

        $this->assertNull($task->fresh()->overdue_notified_at);
        $this->assertSame(0, $action->execute($this->now)['overdue_task_notifications']);
        $this->assertSame(1, $action->execute($newDue->addDay())['overdue_task_notifications']);
    }

    public function test_completing_a_task_clears_its_overdue_flag(): void
    {
        $task = Task::factory()->create(['due_date' => $this->now->subDay()->toDateString()]);
        $task->assignees()->attach($task->project->created_by);
        $action = app(SendScheduledNotifications::class);
        $this->assertSame(1, $action->execute($this->now)['overdue_task_notifications']);

        $this->actingAs($task->project->creator, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertNull($task->fresh()->overdue_notified_at);
        $this->assertSame(0, $action->execute($this->now)['overdue_task_notifications']);
    }

    public function test_reminder_command_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->sole(
            fn (Event $event): bool => str_contains($event->command, 'notifications:send-reminders'),
        );

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_recipient_lookup_and_flag_updates_use_a_constant_number_of_queries(): void
    {
        // Fake delivery to count scheduling queries independently of each recipient's notification insert.
        Notification::fake();
        $project = Project::factory()->create();
        $action = app(SendScheduledNotifications::class);
        $queryCounts = [];

        foreach ([1, 10] as $count) {
            Meeting::factory()->count($count)->for($project)->for($project->creator, 'organizer')
                ->create(['starts_at' => $this->now->addMinutes(20)]);
            Task::factory()->count($count)->for($project)->create(['due_date' => $this->now->subDay()->toDateString()])
                ->each(fn (Task $task) => $task->assignees()->attach($project->created_by));

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $sent = $action->execute($this->now);
                $queryCounts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertSame(['meeting_reminders' => $count, 'overdue_task_notifications' => $count], $sent);
        }

        $this->assertSame($queryCounts[0], $queryCounts[1]);
    }
}
