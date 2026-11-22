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
        $isDirect = $this->type === ConversationType::Direct;
        $other = $isDirect ? ($this->user_one_id === $request->user()->id ? $this->userTwo : $this->userOne) : null;
        $message = $this->lastMessage;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => match ($this->type) {
                ConversationType::Project => $this->project->name,
                ConversationType::Team => $this->project->name.' · '.$this->team->name,
                ConversationType::Direct => $other->name,
            },
            'project_id' => $this->when(! $isDirect, $this->project_id),
            'team' => $this->when($this->type === ConversationType::Team, fn () => new TeamResource($this->team)),
            'user' => $this->when($isDirect, fn () => new UserSummaryResource($other)),
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
