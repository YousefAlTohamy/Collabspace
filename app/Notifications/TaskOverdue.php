<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class TaskOverdue extends Notification
{
    public function __construct(
        private readonly int $taskId,
        private readonly int $projectId,
        private readonly string $taskTitle,
        private readonly int $daysOverdue,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(User $notifiable): string
    {
        return 'task.overdue';
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->databaseType($notifiable),
            'message' => __('A task assigned to you is overdue.'),
            'task_id' => $this->taskId,
            'project_id' => $this->projectId,
            'task_title' => $this->taskTitle,
            'days_overdue' => $this->daysOverdue,
        ];
    }
}
