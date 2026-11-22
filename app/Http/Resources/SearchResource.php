<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'projects' => $this->resource['projects']->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status->value,
            ]),
            'tasks' => $this->resource['tasks']->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'project_id' => $task->project_id,
                'project_name' => $task->project_name,
            ]),
            'files' => $this->resource['files']->map(fn (ProjectFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'project_id' => $file->project_id,
                'project_name' => $file->project_name,
            ]),
        ];
    }
}
