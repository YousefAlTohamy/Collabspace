<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        // R3: assignees/reviewer must be members of the task's project.
        $projectMembers = fn () => Rule::exists('project_members', 'user_id')
            ->where('project_id', $this->route('project')->id);

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'start_date' => ['nullable', 'date'],
            // R6: only comparable when both dates are in the payload.
            'due_date' => [
                'nullable',
                'date',
                Rule::when(fn ($input): bool => filled($input['start_date'] ?? null), 'after_or_equal:start_date'),
            ],
            'reviewer_id' => ['nullable', 'integer', $projectMembers()],
            'assignee_ids' => ['nullable', 'array', 'list'],
            'assignee_ids.*' => ['integer', 'distinct', $projectMembers()],
            // R4: a task can never be created as completed.
            'status' => ['sometimes', Rule::enum(TaskStatus::class)->except(TaskStatus::Completed)],
        ];
    }
}
