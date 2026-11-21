<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMember
 */
class ProjectMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'job_title' => $this->job_title,
            'team' => $this->whenLoaded('team', fn () => $this->team !== null ? new TeamResource($this->team) : null),
            'user' => $this->whenLoaded('user', fn () => new UserSummaryResource($this->user)),
        ];
    }
}
