<?php

declare(strict_types=1);

namespace App\Enums;

enum AiRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
