<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    public function __construct(
        private readonly LockTaskMembers $lockMembers,
        private readonly SyncTaskAssignees $syncAssignees,
    ) {}

    public function execute(User $actor, Task $task, array $attributes): Task
    {
        return DB::transaction(function () use ($actor, $task, $attributes): Task {
            $this->lockMembers->execute($task->project_id, $attributes);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $task->update(Arr::except($attributes, ['assignee_ids']));

            if (array_key_exists('assignee_ids', $attributes)) {
                $this->syncAssignees->execute($task, $actor, $attributes['assignee_ids']);
            }

            return $task;
        });
    }
}
