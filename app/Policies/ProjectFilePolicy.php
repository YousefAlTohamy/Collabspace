<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProjectFile;
use App\Models\User;

class ProjectFilePolicy
{
    /**
     * R2: file access follows project membership (download included).
     */
    public function view(User $user, ProjectFile $file): bool
    {
        return $file->project->isMember($user);
    }

    /**
     * SPEC §2: delete file is leader or uploader.
     */
    public function delete(User $user, ProjectFile $file): bool
    {
        return $file->project->isLeader($user)
            || ($file->uploaded_by === $user->id && $this->view($user, $file));
    }
}
