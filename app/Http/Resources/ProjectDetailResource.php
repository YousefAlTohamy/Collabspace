<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectDetailResource extends JsonResource
{
    /**
     * Task counts by status, computed by the controller with one grouped
     * query (R5 progress is never stored).
     *
     * @param  array<string, int>  $taskCounts  status => count
     */
    public function __construct($resource, private readonly array $taskCounts = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = array_sum($this->taskCounts);
        $completed = $this->taskCounts[TaskStatus::Completed->value] ?? 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'members_count' => $this->whenCounted('members'),
            'members' => $this->whenLoaded('members', fn () => ProjectMemberResource::collection($this->members)),
            'progress_percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'tasks' => [
                'todo' => $this->taskCounts[TaskStatus::Todo->value] ?? 0,
                'in_progress' => $this->taskCounts[TaskStatus::InProgress->value] ?? 0,
                'in_review' => $this->taskCounts[TaskStatus::InReview->value] ?? 0,
                'completed' => $completed,
                'total' => $total,
            ],
        ];
    }
}
