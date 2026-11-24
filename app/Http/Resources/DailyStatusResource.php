<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DailyStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyStatus */
class DailyStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'date' => $this->status_date->toDateString(),
            'progress' => $this->progress,
            'next_steps' => $this->next_steps,
            'blockers' => $this->blockers,
            'author' => new UserSummaryResource($this->whenLoaded('author')),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
