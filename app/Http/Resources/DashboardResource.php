<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tasks' => $this->resource['tasks'],
            'active_projects' => $this->resource['active_projects']->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'progress_percent' => $project->tasks_count === 0 ? 0 : (int) round(100 * $project->tasks_completed / $project->tasks_count),
                'tasks_completed' => $project->tasks_completed,
                'tasks_total' => $project->tasks_count,
            ]),
            'recent_files' => FileResource::collection($this->resource['recent_files']),
            'my_tasks' => TaskResource::collection($this->resource['my_tasks']),
            'upcoming_meetings' => MeetingResource::collection($this->resource['upcoming_meetings']),
            'project_overview' => $this->resource['project_overview'],
        ];
    }
}
