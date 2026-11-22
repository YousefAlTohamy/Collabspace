<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

class MeetingReminder extends Notification
{
    public function __construct(
        private readonly int $meetingId,
        private readonly ?int $projectId,
        private readonly string $meetingTitle,
        private readonly CarbonImmutable $startsAt,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(User $notifiable): string
    {
        return 'meeting.reminder';
    }

    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->databaseType($notifiable),
            'message' => __('Your meeting starts within 30 minutes.'),
            'meeting_id' => $this->meetingId,
            'project_id' => $this->projectId,
            'meeting_title' => $this->meetingTitle,
            'starts_at' => $this->startsAt->toISOString(),
        ];
    }
}
