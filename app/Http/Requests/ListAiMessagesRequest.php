<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

class ListAiMessagesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['bail', 'nullable', 'string', 'max:256', function (string $attribute, string $value, Closure $fail): void {
                $cursor = Cursor::fromEncoded($value)?->toArray() ?? [];
                if (! is_int($cursor['id'] ?? null) || $cursor['id'] < 1 || ! is_bool($cursor['_pointsToNextItems'] ?? null)) {
                    $fail('The cursor is invalid.');
                }
            }],
        ];
    }
}
