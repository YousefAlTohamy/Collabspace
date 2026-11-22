<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportType: string
{
    case ProjectProgress = 'project_progress';
    case TaskPerformance = 'task_performance';
    case TeamActivity = 'team_activity';
}
