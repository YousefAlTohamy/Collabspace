<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MeetingTranscript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeetingTranscript */
class MeetingTranscriptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'speaker_id' => $this->speaker_id,
            'speaker' => $this->whenLoaded('speaker', fn () => new UserSummaryResource($this->speaker)),
            'offset_seconds' => $this->offset_seconds,
            'text' => $this->text,
        ];
    }
}
