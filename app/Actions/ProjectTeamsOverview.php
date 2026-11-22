<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Teams tab: groups the project's members by team (plus an
 * "unassigned" group) and computes per-member and per-team task progress
 * with two grouped aggregate queries — no per-member queries.
 */
class ProjectTeamsOverview
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Project $project): array
    {
        $members = $project->members()->with(['user', 'team'])->orderBy('id')->get();

        // Per-member: completed / total of this project's tasks assigned to them.
        $perMember = DB::table('task_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->where('tasks.project_id', $project->id)
            ->groupBy('task_assignees.user_id')
            ->selectRaw('task_assignees.user_id, count(*) as total, sum(case when tasks.status = ? then 1 else 0 end) as done', [TaskStatus::Completed->value])
            ->get()
            ->keyBy('user_id');

        // Per team: distinct tasks assigned to at least one member of the team
        // (each task counted once, even with several assignees in the team).
        $perTeam = DB::table('task_assignees')
            ->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->join('project_members', function ($join) use ($project): void {
                $join->on('project_members.user_id', '=', 'task_assignees.user_id')
                    ->where('project_members.project_id', '=', $project->id);
            })
            ->where('tasks.project_id', $project->id)
            ->groupBy('project_members.team_id')
            ->selectRaw('project_members.team_id, count(distinct tasks.id) as total, count(distinct case when tasks.status = ? then tasks.id end) as done', [TaskStatus::Completed->value])
            ->get()
            ->keyBy('team_id');

        $grouped = $members->groupBy(fn ($member) => $member->team_id ?? 'unassigned');

        // Teams with members, ordered by name; "unassigned" goes last.
        $teams = $members->map->team->filter()->keyBy('id')->sortBy('name');

        $result = [];
        foreach ($teams as $team) {
            $result[] = $this->buildGroup($team->id, $team->name, $grouped->get($team->id, collect()), $perMember, $perTeam->get($team->id));
        }

        if ($grouped->has('unassigned')) {
            $result[] = $this->buildGroup(null, 'Unassigned', $grouped->get('unassigned'), $perMember, $perTeam->get(null));
        }

        return $result;
    }

    /**
     * @param  Collection<int, ProjectMember>  $members
     * @param  Collection<string|int, object>  $perMember
     */
    private function buildGroup(?int $teamId, ?string $name, $members, $perMember, ?object $teamCounts): array
    {
        $total = (int) ($teamCounts->total ?? 0);
        $done = (int) ($teamCounts->done ?? 0);

        return [
            'id' => $teamId,
            'name' => $name,
            'completed_percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'members' => $members->map(function ($member) use ($perMember): array {
                $counts = $perMember->get($member->user_id);

                $data = ProjectMemberResource::make($member)->resolve();
                $data['tasks_done'] = (int) ($counts->done ?? 0);
                $data['tasks_total'] = (int) ($counts->total ?? 0);

                return $data;
            })->values()->all(),
        ];
    }
}
