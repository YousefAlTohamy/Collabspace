<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function view(User $user, Meeting $meeting): bool
    {
        if ($meeting->project_id !== null && ! $meeting->project->isMember($user)) {
            return false;
        }

        return $this->update($user, $meeting) || $meeting->participants()->whereKey($user->id)->exists();
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->created_by === $user->id;
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function addTranscript(User $user, Meeting $meeting): bool
    {
        return $this->view($user, $meeting);
    }
}
