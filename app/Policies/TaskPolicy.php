<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * R2: task visibility follows project membership.
     */
    public function view(User $user, Task $task): bool
    {
        return $task->project->isMember($user);
    }

    /**
     * SPEC §2: edit task is leader, creator or assignee.
     */
    public function update(User $user, Task $task): bool
    {
        return $task->project->isLeader($user)
            || ($this->view($user, $task) && ($task->created_by === $user->id || $task->isAssignee($user)));
    }

    /**
     * SPEC §2: delete task is leader or creator.
     */
    public function delete(User $user, Task $task): bool
    {
        return $task->project->isLeader($user)
            || ($task->created_by === $user->id && $this->view($user, $task));
    }

    /**
     * R4 status flow. $to is the target status of the transition.
     */
    public function changeStatus(User $user, Task $task, TaskStatus $to): bool
    {
        if ($task->project->isLeader($user)) {
            return true;
        }

        if (! $this->view($user, $task)) {
            return false;
        }

        $isReviewer = $task->reviewer_id === $user->id;

        // Reopen (out of completed): reviewer or leader only.
        if ($task->status === TaskStatus::Completed) {
            return $isReviewer;
        }

        // To completed: reviewer if set, a leader always, an assignee only
        // when no reviewer is set.
        if ($to === TaskStatus::Completed) {
            return $task->reviewer_id !== null ? $isReviewer : $task->isAssignee($user);
        }

        // Among todo / in_progress / in_review: leader, creator or assignee.
        return $task->created_by === $user->id || $task->isAssignee($user);
    }
}
