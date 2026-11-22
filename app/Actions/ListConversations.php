<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListConversations
{
    public function execute(User $user, int $perPage): LengthAwarePaginator
    {
        $last = Message::query()->whereColumn('conversation_id', 'conversations.id')->latest('id')->limit(1);

        return Conversation::query()
            ->where(function (Builder $mine) use ($user): void {
                $mine->whereHas('project', fn (Builder $project) => $project->visibleTo($user))
                    ->orWhere('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->addSelect([
                'last_message_id' => (clone $last)->select('id'),
                'last_message_at' => (clone $last)->select('created_at'),
            ])
            ->with(['project', 'userOne', 'userTwo', 'lastMessage.user'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
