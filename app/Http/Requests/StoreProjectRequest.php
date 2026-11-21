<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectPriority;
use App\Enums\ProjectRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|Rule|Closure>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'max:50'],
            'priority' => ['required', Rule::enum(ProjectPriority::class)],
            'start_date' => ['required', 'date'],
            // R6
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'members' => ['nullable', 'array', 'max:50'],
            'members.*.user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // The creator is always added as leader (R1), so they cannot
                    // also appear in the members payload.
                    if ($value === $this->user()->id) {
                        $fail('The creator is added as leader automatically and cannot be listed as a member.');
                    }
                },
            ],
            'members.*.team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'members.*.job_title' => ['nullable', 'string', 'max:100'],
            'members.*.role' => ['nullable', Rule::enum(ProjectRole::class)],
        ];
    }
}
