<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Validation\ValidationException;

class UpdateProjectMember
{
    public function execute(Project $project, ProjectMember $member, array $attributes): void
    {
        $newRole = (string) ($attributes['role'] ?? '');
        $demotesLastLeader = $newRole === ProjectRole::Member->value
            && $member->role === ProjectRole::Leader
            && $project->leaderCount() === 1;
        if ($demotesLastLeader) {
            // R1: a project must always keep at least one leader.
            throw ValidationException::withMessages([
                'role' => __('A project must always keep at least one leader.'),
            ]);
        }

        $member->update($attributes);
    }
}
