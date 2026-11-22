<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isProject = $this->type === ConversationType::Project;
        $other = $isProject ? null : ($this->user_one_id === $request->user()->id ? $this->userTwo : $this->userOne);
        $message = $this->lastMessage;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $isProject ? $this->project->name : $other->name,
            'project_id' => $this->when($isProject, $this->project_id),
            'user' => $this->when(! $isProject, fn () => new UserSummaryResource($other)),
            'last_message' => $message === null ? null : [
                'id' => $message->id,
                'body' => $message->body === null ? null : mb_substr($message->body, 0, 80),
                'sender_name' => $message->user->name,
                'has_attachment' => $message->attachment_path !== null,
                'created_at' => $message->created_at->toISOString(),
            ],
        ];
    }
}
