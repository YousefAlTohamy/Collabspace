<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List item: first 4 members only. The detail endpoint uses
 * ProjectDetailResource.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // R5: progress is computed from withCount, never stored.
        $total = (int) ($this->tasks_count ?? 0);
        $completed = (int) ($this->tasks_completed_count ?? 0);

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
            'files_count' => $this->whenCounted('files'),
            'progress_percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            'members' => ProjectMemberResource::collection($this->whenLoaded('members')),
        ];
    }
}
