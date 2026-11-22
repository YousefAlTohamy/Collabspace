<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return match ($conversation->type) {
            ConversationType::Project => $conversation->project->isMember($user),
            ConversationType::Direct => in_array($user->id, [$conversation->user_one_id, $conversation->user_two_id], true),
        };
    }

    public function startDirect(User $user, User $other): bool
    {
        return $user->sharesProjectWith($other);
    }
}
