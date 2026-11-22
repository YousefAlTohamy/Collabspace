<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Models\Meeting;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MeetingReminder;
use App\Notifications\TaskOverdue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class SendScheduledNotifications
{
    /** @return array{meeting_reminders: int, overdue_task_notifications: int} */
    public function execute(CarbonImmutable $now): array
    {
        $now = $now->utc();
        $today = $now->startOfDay();

        $meetings = Meeting::query()->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addMinutes(30));
        $tasks = Task::query()->whereNull('overdue_notified_at')
            ->where('status', '!=', TaskStatus::Completed->value)
            ->where('due_date', '<', $today->toDateString());

        return [
            'meeting_reminders' => $this->sendInChunks(
                $meetings, 'participants', 'reminder_sent_at', $now, $this->notifyMeetingParticipants(...),
            ),
            'overdue_task_notifications' => $this->sendInChunks(
                $tasks, 'assignees', 'overdue_notified_at', $now,
                fn (Collection $batch): int => $this->notifyTaskAssignees($batch, $today),
            ),
        ];
    }

    private function sendInChunks(Builder $eligible, string $relation, string $flag, CarbonImmutable $now, Closure $notify): int
    {
        $sent = 0;
        (clone $eligible)->select('id')->chunkById(100, function (Collection $batch) use ($eligible, $relation, $flag, $now, $notify, &$sent): void {
            $sent += DB::transaction(function () use ($batch, $eligible, $relation, $flag, $now, $notify): int {
                // Recheck eligibility under lock so overlapping runs cannot send twice.
                $items = (clone $eligible)->whereKey($batch->modelKeys())
                    ->orderBy('id')->lockForUpdate()->with($relation)->get();
                if ($items->isEmpty()) {
                    return 0;
                }

                $count = $notify($items);
                $eligible->getModel()->newQuery()->whereKey($items->modelKeys())
                    ->toBase()->update([$flag => $now]);

                return $count;
            });
        });

        return $sent;
    }

    /** @param Collection<int, Meeting> $meetings */
    private function notifyMeetingParticipants(Collection $meetings): int
    {
        $projectIds = $meetings->pluck('project_id')->filter()->unique();
        $participantIds = $meetings->flatMap(fn (Meeting $meeting) => $meeting->participants->modelKeys())->unique();
        $members = ProjectMember::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('user_id', $participantIds)
            ->get(['project_id', 'user_id'])
            ->groupBy('project_id')
            ->map(fn (Collection $group) => $group->pluck('user_id')->flip());

        $sent = 0;
        foreach ($meetings as $meeting) {
            $projectMembers = $members->get($meeting->project_id);
            $recipients = $meeting->project_id === null
                ? $meeting->participants
                : $meeting->participants->filter(fn (User $user): bool => $projectMembers?->has($user->id) ?? false);
            Notification::send($recipients, new MeetingReminder($meeting->id, $meeting->project_id, $meeting->title, $meeting->starts_at));
            $sent += $recipients->count();
        }

        return $sent;
    }

    /** @param Collection<int, Task> $tasks */
    private function notifyTaskAssignees(Collection $tasks, CarbonImmutable $today): int
    {
        $sent = 0;
        foreach ($tasks as $task) {
            $daysOverdue = (int) $task->due_date->diffInDays($today);
            Notification::send($task->assignees, new TaskOverdue($task->id, $task->project_id, $task->title, $daysOverdue));
            $sent += $task->assignees->count();
        }

        return $sent;
    }
}
