<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * List responses show the first 4 members only; the detail endpoint shows
     * all of them by setting this flag.
     */
    public bool $allMembers = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'members' => $this->whenLoaded('members', function () {
                $members = $this->allMembers
                    ? $this->members
                    : $this->members->take(4);

                return ProjectMemberResource::collection($members);
            }),
        ];
    }
}
