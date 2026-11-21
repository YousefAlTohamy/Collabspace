<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * R2: only members can see a project and its contents.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->isMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->isLeader($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->isLeader($user);
    }

    /**
     * SPEC §2: add/remove/change members is leader-only.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        return $project->isLeader($user);
    }
}
