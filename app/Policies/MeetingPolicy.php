<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function view(User $user, Meeting $meeting): bool
    {
        return $this->hasProjectAccess($user, $meeting)
            && ($meeting->created_by === $user->id || $meeting->participants()->whereKey($user->id)->exists());
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->created_by === $user->id && $this->hasProjectAccess($user, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function addTranscript(User $user, Meeting $meeting): bool
    {
        return $this->view($user, $meeting);
    }

    private function hasProjectAccess(User $user, Meeting $meeting): bool
    {
        return $meeting->project_id === null || $meeting->project?->isMember($user) === true;
    }
}
