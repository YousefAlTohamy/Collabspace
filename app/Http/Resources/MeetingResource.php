<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'ends_at' => $this->starts_at->addMinutes($this->duration_minutes)->toISOString(),
            'meeting_url' => $this->meeting_url,
            'note' => $this->note,
            'summary' => $this->summary,
            'organizer' => $this->whenLoaded('organizer', fn () => new UserSummaryResource($this->organizer)),
            'participants' => UserSummaryResource::collection($this->whenLoaded('participants')),
            'project' => $this->whenLoaded('project', fn () => ['id' => $this->project->id, 'name' => $this->project->name]),
        ];
    }
}
