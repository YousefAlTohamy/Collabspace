<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateProject
{
    public function execute(User $actor, Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($actor, $project, $attributes): Project {
            // R5: task creation and status changes also lock the project first.
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($actor)->authorize('update', $current);
            $current->fill($attributes);

            if ($current->status === ProjectStatus::Done && $current->tasks()->where('status', '!=', TaskStatus::Completed)->exists()) {
                throw ValidationException::withMessages(['status' => __('The project cannot be marked done while it has tasks that are not completed.')]);
            }

            if ($current->isDirty('status')) {
                $current->completed_at = $current->status === ProjectStatus::Done
                    ? ($current->completed_at ?? now())
                    : null;
            }
            $current->save();

            return $current;
        });
    }
}
