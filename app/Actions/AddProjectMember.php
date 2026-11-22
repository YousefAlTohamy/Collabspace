<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Notifications\AddedToProject;
use Illuminate\Support\Facades\DB;

class AddProjectMember
{
    public function execute(User $actor, Project $project, array $attributes): ProjectMember
    {
        return DB::transaction(function () use ($actor, $project, $attributes): ProjectMember {
            $member = $project->members()->create([
                'user_id' => $attributes['user_id'],
                'role' => $attributes['role'] ?? ProjectRole::Member,
                'team_id' => $attributes['team_id'] ?? null,
                'job_title' => $attributes['job_title'] ?? null,
            ])->load(['user', 'team']);

            if ($member->user->id !== $actor->id) {
                $member->user->notify(new AddedToProject($project->id, $actor));
            }

            return $member;
        });
    }
}
