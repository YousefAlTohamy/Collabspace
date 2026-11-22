<?php

declare(strict_types=1);

namespace App\Enums;

enum ConversationType: string
{
    case Project = 'project';
    case Direct = 'direct';
}
