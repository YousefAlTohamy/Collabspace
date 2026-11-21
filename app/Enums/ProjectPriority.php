<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
