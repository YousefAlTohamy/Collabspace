<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification
{
    public function __construct(
        private readonly int $taskId,
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
        return 'task.assigned';
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->databaseType($notifiable),
            'message' => __('You were assigned a task.'),
            'task_id' => $this->taskId,
            'project_id' => $this->projectId,
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
        ];
    }
}
