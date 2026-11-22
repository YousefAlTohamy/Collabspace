<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'attachment' => $this->attachment_path === null ? null : [
                'name' => $this->attachment_name,
                'size' => $this->attachment_size,
                'mime_type' => $this->attachment_mime,
                'download_url' => route('messages.attachment', ['message' => $this->id]),
            ],
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
