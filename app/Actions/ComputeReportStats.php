<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ComputeReportStats
{
    public function execute(
        User $user,
        ?Project $project,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $calendarDay = false,
    ): array {
        // Database timestamps have second precision. The two inclusive windows must not overlap.
        $from = $from->startOfSecond();
        $to = $to->startOfSecond();
        $projects = Project::query()->visibleTo($user);
        if ($project !== null) {
            $projects->whereKey($project->id);
        }

        $projectIds = (clone $projects)->select('projects.id');
        $tasks = Task::query()->whereIn('project_id', $projectIds);
        $previousEnd = $calendarDay ? $to->subDay() : $from->subSecond();
        $previousStart = $previousEnd->subSeconds($from->diffInSeconds($to));

        return [
            'task_completion_rate' => $this->completionRate(clone $tasks, $to, $previousEnd),
            'projects_completion_rate' => $this->completionRate($projects, $to, $previousEnd),
            'teams_activity_rate' => $this->activityRate($projectIds, $previousStart, $previousEnd, $from, $to),
            ...$this->monthlySeries($tasks, $from, $to, $calendarDay ? $from : $from->startOfMonth()),
        ];
    }

    private function completionRate(Builder $query, CarbonImmutable $end, CarbonImmutable $previousEnd): array
    {
        $counts = $query->where('created_at', '<=', $end)->toBase()->selectRaw(
            'COUNT(*) AS total,
             COUNT(CASE WHEN completed_at <= ? THEN 1 END) AS completed,
             COUNT(CASE WHEN created_at <= ? THEN 1 END) AS previous_total,
             COUNT(CASE WHEN created_at <= ? AND completed_at <= ? THEN 1 END) AS previous_completed',
            [$end, $previousEnd, $previousEnd, $previousEnd]
        )->first();

        return $this->rate($counts->completed, $counts->total, $counts->previous_completed, $counts->previous_total);
    }

    private function activityRate(
        Builder $projectIds,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $members = DB::table('project_members')->whereIn('project_id', $projectIds)
            ->select('user_id')->distinct();
        $total = DB::query()->fromSub($members, 'current_members')->count();

        $completed = DB::table('task_assignees')->join('tasks', 'tasks.id', '=', 'task_assignees.task_id')
            ->whereIn('tasks.project_id', $projectIds)
            ->whereBetween('tasks.completed_at', [$previousStart, $to])
            ->select('task_assignees.user_id', 'tasks.completed_at as occurred_at');
        $commented = DB::table('task_comments')->join('tasks', 'tasks.id', '=', 'task_comments.task_id')
            ->whereIn('tasks.project_id', $projectIds)
            ->whereBetween('task_comments.created_at', [$previousStart, $to])
            ->select('task_comments.user_id', 'task_comments.created_at as occurred_at');

        $counts = DB::query()->fromSub($completed->unionAll($commented), 'activities')
            ->whereIn('user_id', $members)->selectRaw(
                'COUNT(DISTINCT CASE WHEN occurred_at >= ? THEN user_id END) AS active,
                 COUNT(DISTINCT CASE WHEN occurred_at <= ? THEN user_id END) AS previous_active',
                [$from, $previousEnd]
            )->first();

        return $this->rate($counts->active, $total, $counts->previous_active, $total);
    }

    private function monthlySeries(Builder $tasks, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $activityStart): array
    {
        $firstMonth = $from->startOfMonth();
        $before = (clone $tasks)->toBase()->selectRaw(
            'COUNT(CASE WHEN created_at < ? THEN 1 END) AS created,
             COUNT(CASE WHEN completed_at < ? THEN 1 END) AS completed',
            [$firstMonth, $firstMonth]
        )->first();
        $created = (clone $tasks)->toBase()
            ->whereBetween('created_at', [$firstMonth, $to])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')->pluck('total', 'month');
        $completed = (clone $tasks)->toBase()
            ->whereBetween('completed_at', [$firstMonth, $to])
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') AS month, COUNT(*) AS total,
                COUNT(CASE WHEN completed_at >= ? THEN 1 END) AS activity,
                COUNT(CASE WHEN completed_at >= ? THEN due_date END) AS with_deadline,
                COUNT(CASE WHEN completed_at >= ? AND DATE(completed_at) <= due_date THEN 1 END) AS on_time",
                [$activityStart, $activityStart, $activityStart])
            ->groupBy('month')->get()->keyBy('month');

        $deadline = $progress = $activity = [];
        $createdTotal = (int) $before->created;
        $completedTotal = (int) $before->completed;
        for ($month = $firstMonth; $month <= $to; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $counts = $completed->get($key);
            $closed = (int) ($counts->total ?? 0);
            $due = (int) ($counts->with_deadline ?? 0);
            $createdTotal += (int) $created->get($key, 0);
            $completedTotal += $closed;

            $deadline[] = ['month' => $key, 'value' => $due === 0 ? null : $this->percent((int) $counts->on_time, $due)];
            $progress[] = ['month' => $key, 'value' => $createdTotal === 0 ? null : $this->percent($completedTotal, $createdTotal)];
            $activity[] = ['month' => $key, 'value' => (int) ($counts->activity ?? 0)];
        }

        return ['tasks_vs_deadline' => $deadline, 'projects_progress' => $progress, 'worker_activity' => $activity];
    }

    private function rate(int $count, int $total, int $previousCount, int $previousTotal): array
    {
        $value = $this->percent($count, $total);

        return [
            'value' => $value,
            'change_vs_last_period' => round($value - $this->percent($previousCount, $previousTotal), 1),
        ];
    }

    private function percent(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round(100 * $count / $total, 1);
    }
}
