<?php

declare(strict_types=1);

namespace App\Support;

final class Like
{
    public static function contains(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }
}
