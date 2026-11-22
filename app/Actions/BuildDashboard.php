<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BuildDashboard
{
    public function execute(User $user): array
    {
        $now = CarbonImmutable::now('UTC');
        $projects = Project::query()->visibleTo($user);
        $projectIds = (clone $projects)->select('projects.id');
        $projectTasks = Task::query()->whereIn('project_id', $projectIds);
        $assigned = (clone $projectTasks)->whereHas('assignees', fn (Builder $users) => $users->whereKey($user->id));

        return [
            'tasks' => $this->taskCounts(clone $assigned, $now),
            'active_projects' => $projects->where('status', ProjectStatus::Active)
                ->withCount(['tasks', 'tasks as tasks_completed' => fn (Builder $tasks) => $tasks->where('status', TaskStatus::Completed)])
                ->latest()->orderByDesc('id')->limit(5)->get(),
            'recent_files' => ProjectFile::query()->whereIn('project_id', $projectIds)
                ->with('uploader')->latest()->orderByDesc('id')->limit(5)->get(),
            'my_tasks' => $assigned->where('status', '!=', TaskStatus::Completed)
                ->with(['project', 'reviewer', 'assignees'])->withCount('comments')
                ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')->limit(5)->get(),
            'upcoming_meetings' => $this->upcomingMeetings($user, $now),
            'project_overview' => $this->projectOverview($projectTasks, $now),
        ];
    }

    private function taskCounts(Builder $assigned, CarbonImmutable $now): array
    {
        $month = $now->startOfMonth();
        $counts = $assigned->toBase()->selectRaw(
            'status, COUNT(*) AS total,
             COUNT(CASE WHEN completed_at >= ? AND completed_at < ? THEN 1 END) AS this_month,
             COUNT(CASE WHEN completed_at >= ? AND completed_at < ? THEN 1 END) AS last_month',
            [$month, $month->addMonth(), $month->subMonth(), $month]
        )->groupBy('status')->get()->keyBy('status');

        $completed = $counts->get(TaskStatus::Completed->value);
        $done = (int) ($completed->total ?? 0);
        $total = (int) $counts->sum('total');

        return [
            'pending' => (int) ($counts->get(TaskStatus::Todo->value)->total ?? 0),
            'in_progress' => (int) ($counts->get(TaskStatus::InProgress->value)->total ?? 0),
            'in_review' => (int) ($counts->get(TaskStatus::InReview->value)->total ?? 0),
            'completed' => $done,
            'total' => $total,
            'completion_rate' => $total === 0 ? 0.0 : round(100 * $done / $total, 1),
            'completed_this_month' => (int) ($completed->this_month ?? 0),
            'completed_last_month' => (int) ($completed->last_month ?? 0),
        ];
    }

    private function upcomingMeetings(User $user, CarbonImmutable $now): Collection
    {
        return Meeting::query()
            ->where(fn (Builder $mine) => $mine->where('created_by', $user->id)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->whereKey($user->id)))
            ->where(fn (Builder $visible) => $visible->whereNull('project_id')
                ->orWhereHas('project', fn (Builder $project) => $project->visibleTo($user)))
            ->where('starts_at', '>=', $now)
            ->with(['organizer', 'participants', 'project'])
            ->orderBy('starts_at')->orderBy('id')->limit(5)->get();
    }

    private function projectOverview(Builder $tasks, CarbonImmutable $now): array
    {
        $year = $now->startOfYear();
        $counts = $tasks->toBase()->where('completed_at', '>=', $year)->where('completed_at', '<', $year->addYear())
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')->pluck('total', 'month');

        return array_map(function (int $offset) use ($year, $counts): array {
            $month = $year->addMonths($offset)->format('Y-m');

            return ['month' => $month, 'completed' => (int) $counts->get($month, 0)];
        }, range(0, 11));
    }
}
