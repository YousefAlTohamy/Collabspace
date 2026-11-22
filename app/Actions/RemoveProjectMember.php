<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveProjectMember
{
    public function execute(Project $project, ProjectMember $member): void
    {
        DB::transaction(function () use ($project, $member): void {
            $leaderIds = $project->members()->where('role', ProjectRole::Leader->value)
                ->orderBy('id')->lockForUpdate()->pluck('id');

            // R1: a project must always keep at least one leader.
            if ($leaderIds->count() === 1 && $leaderIds->contains($member->id)) {
                throw ValidationException::withMessages([
                    'role' => __('A project must always keep at least one leader.'),
                ]);
            }

            $userId = $member->user_id;
            $member->delete();

            // R3: leaving the project also drops their task assignments here.
            DB::table('task_assignees')
                ->whereIn('task_id', $project->tasks()->select('id'))
                ->where('user_id', $userId)
                ->delete();

            $project->tasks()
                ->where('reviewer_id', $userId)
                ->update(['reviewer_id' => null]);
        });
    }
}
