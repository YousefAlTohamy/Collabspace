<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SendScheduledNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Send upcoming meeting reminders and overdue task notifications';

    public function handle(SendScheduledNotifications $action): int
    {
        $counts = $action->execute(CarbonImmutable::now('UTC'));
        $this->line("Meeting reminders sent: {$counts['meeting_reminders']}");
        $this->line("Overdue task notifications sent: {$counts['overdue_task_notifications']}");

        return self::SUCCESS;
    }
}
