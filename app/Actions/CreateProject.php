<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AddedToProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CreateProject
{
    public function execute(User $actor, array $attributes, array $members): Project
    {
        return DB::transaction(function () use ($actor, $attributes, $members): Project {
            $project = new Project($attributes);
            $project->created_by = $actor->id;
            $project->save();
            Conversation::createProjectChannel($project);
            $project->refresh(); // Load the default active status.

            // R1: the creator becomes the leader.
            $project->members()->create([
                'user_id' => $actor->id,
                'role' => ProjectRole::Leader,
            ]);

            foreach ($members as $member) {
                $membership = $project->members()->create([
                    'user_id' => $member['user_id'],
                    'role' => $member['role'] ?? ProjectRole::Member,
                    'team_id' => $member['team_id'] ?? null,
                    'job_title' => $member['job_title'] ?? null,
                ]);

                if ($membership->team_id !== null) {
                    Conversation::ensureTeamChannel($project->id, (int) $membership->team_id);
                }
            }

            $added = User::query()->whereKey(array_column($members, 'user_id'))->whereKeyNot($actor->id)->get();
            Notification::send($added, new AddedToProject($project->id, $actor));

            return $project;
        });
    }
}
