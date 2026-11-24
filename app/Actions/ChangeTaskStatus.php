<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * R4 side effects of a task status transition, in one transaction:
 * entering `completed` forces progress=100 and stamps completed_at;
 * leaving `completed` clears completed_at.
 */
class ChangeTaskStatus
{
    public function execute(User $actor, Task $task, TaskStatus $to, ?int $progress = null): Task
    {
        return DB::transaction(function () use ($actor, $task, $to, $progress): Task {
            $project = Project::query()->lockForUpdate()->findOrFail($task->project_id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $task->setRelation('project', $project);
            Gate::forUser($actor)->authorize('changeStatus', [$task, $to]);

            if ($to !== TaskStatus::Completed && $project->status === ProjectStatus::Done) {
                throw ValidationException::withMessages(['status' => __('A leader must reactivate the project before reopening tasks.')]);
            }

            if ($to === TaskStatus::Completed) {
                $task->forceFill([
                    'status' => $to,
                    'progress' => 100,
                    'completed_at' => $task->completed_at ?? now(),
                    'overdue_notified_at' => null,
                ])->save();

                return $task;
            }

            $task->forceFill([
                'status' => $to,
                'progress' => $to === TaskStatus::InProgress ? ($progress ?? $task->progress) : $task->progress,
                'completed_at' => null,
            ])->save();

            return $task;
        });
    }
}
