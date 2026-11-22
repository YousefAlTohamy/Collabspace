<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

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
            // R5: marking done requires every task to be completed (or none).
            'status' => [
                'required',
                Rule::enum(ProjectStatus::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === ProjectStatus::Done->value
                        && $this->route('project')->tasks()
                            ->where('status', '!=', TaskStatus::Completed->value)
                            ->exists()) {
                        $fail('The project cannot be marked done while it has tasks that are not completed.');
                    }
                },
            ],
            'start_date' => ['required', 'date'],
            // R6
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
