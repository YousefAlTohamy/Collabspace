<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProjectMember
{
    public function execute(Project $project, ProjectMember $member, array $attributes): void
    {
        DB::transaction(function () use ($project, $member, $attributes): void {
            $leaderIds = $project->members()->where('role', ProjectRole::Leader->value)
                ->orderBy('id')->lockForUpdate()->pluck('id');

            // R1: a project must always keep at least one leader.
            if (($attributes['role'] ?? null) === ProjectRole::Member->value
                && $leaderIds->count() === 1 && $leaderIds->contains($member->id)) {
                throw ValidationException::withMessages([
                    'role' => __('A project must always keep at least one leader.'),
                ]);
            }

            $member->refresh()->update($attributes);
        });
    }
}
