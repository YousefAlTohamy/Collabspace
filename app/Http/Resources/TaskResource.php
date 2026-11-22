<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn (): array => [
                'id' => $this->project_id,
                'name' => $this->project->name,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'progress' => $this->progress,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Overdue: the due day is fully past and the task is not completed.
            'is_overdue' => $this->due_date !== null
                && $this->due_date->lt(today())
                && $this->status !== TaskStatus::Completed,
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?UserSummaryResource => $this->reviewer !== null ? new UserSummaryResource($this->reviewer) : null),
            'assignees' => UserSummaryResource::collection($this->whenLoaded('assignees')),
            'comments_count' => $this->whenCounted('comments'),
        ];
    }
}
