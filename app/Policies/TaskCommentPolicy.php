<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskComment;
use App\Models\User;

class TaskCommentPolicy
{
    /**
     * R2: comments are visible through their task, i.e. project membership.
     */
    public function view(User $user, TaskComment $comment): bool
    {
        return $comment->task->project->isMember($user);
    }

    /**
     * SPEC §2: edit / delete own comment. The author only.
     */
    public function update(User $user, TaskComment $comment): bool
    {
        return $comment->user_id === $user->id && $this->view($user, $comment);
    }

    /**
     * SPEC §2: delete own comment; the project leader can delete any comment.
     */
    public function delete(User $user, TaskComment $comment): bool
    {
        return $this->update($user, $comment) || $comment->task->project->isLeader($user);
    }
}
