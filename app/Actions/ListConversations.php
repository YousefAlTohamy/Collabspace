<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListConversations
{
    public function execute(User $user, int $perPage): LengthAwarePaginator
    {
        $last = Message::query()->whereColumn('conversation_id', 'conversations.id')->latest('id')->limit(1);
        $memberships = ProjectMember::query()->select('id')
            ->where('user_id', $user->id)
            ->whereColumn('project_members.project_id', 'conversations.project_id')
            ->where(fn (Builder $channel) => $channel->where('conversations.type', ConversationType::Project->value)
                ->orWhere(fn (Builder $team) => $team->where('conversations.type', ConversationType::Team->value)
                    ->whereColumn('project_members.team_id', 'conversations.team_id')));

        return Conversation::query()
            ->where(fn (Builder $mine) => $mine->whereExists($memberships)
                ->orWhere(fn (Builder $direct) => $direct->where('type', ConversationType::Direct->value)
                    ->where(fn (Builder $users) => $users->where('user_one_id', $user->id)
                        ->orWhere('user_two_id', $user->id))))
            ->addSelect([
                'last_message_id' => (clone $last)->select('id'),
                'last_message_at' => (clone $last)->select('created_at'),
            ])
            ->with(['project', 'team', 'userOne', 'userTwo', 'lastMessage.user'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
