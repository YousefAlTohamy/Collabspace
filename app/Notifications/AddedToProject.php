<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class AddedToProject extends Notification
{
    public function __construct(
        private readonly int $projectId,
        private readonly User $actor,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(User $notifiable): string
    {
        return 'project.member_added';
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->databaseType($notifiable),
            'message' => __('You were added to a project.'),
            'project_id' => $this->projectId,
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
        ];
    }
}
