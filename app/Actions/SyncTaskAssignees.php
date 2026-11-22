<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\Notification;

class SyncTaskAssignees
{
    /** @param list<int|string> $assigneeIds */
    public function execute(Task $task, User $actor, array $assigneeIds): void
    {
        $changes = $task->assignees()->sync($assigneeIds);
        $assignees = User::query()->whereKey($changes['attached'])->whereKeyNot($actor->id)->get();
        Notification::send($assignees, new TaskAssigned($task->id, $task->project_id, $actor));
    }
}
