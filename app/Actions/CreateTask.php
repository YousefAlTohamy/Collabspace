<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTask
{
    public function __construct(
        private readonly LockTaskMembers $lockMembers,
        private readonly SyncTaskAssignees $syncAssignees,
    ) {}

    public function execute(User $actor, Project $project, array $attributes): Task
    {
        return DB::transaction(function () use ($actor, $project, $attributes): Task {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($actor)->authorize('view', $project);
            if ($project->status === ProjectStatus::Done) {
                throw ValidationException::withMessages(['project' => __('A leader must reactivate the project before adding tasks.')]);
            }

            $this->lockMembers->execute($project->id, $attributes);
            $task = new Task(Arr::except($attributes, ['assignee_ids']));
            $task->project_id = $project->id;
            $task->created_by = $actor->id;
            $task->save();
            $this->syncAssignees->execute($task, $actor, $attributes['assignee_ids'] ?? []);

            return $task->refresh();
        });
    }
}
