<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        // PUT never changes status or progress: no rule for them, so
        // validated() can never carry them to the model.
        // R3: assignees/reviewer must be members of the task's project.
        $projectMembers = fn () => Rule::exists('project_members', 'user_id')
            ->where('project_id', $this->route('task')->project_id);

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'reviewer_id' => ['nullable', 'integer', $projectMembers()],
            'assignee_ids' => ['sometimes', 'array', 'list'],
            'assignee_ids.*' => ['integer', 'distinct', $projectMembers()],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'due_date']) || ! $this->hasAny(['start_date', 'due_date'])) {
                return;
            }

            // R6: an omitted date keeps its stored value; an explicit null clears it.
            $task = $this->route('task');
            $start = $this->input('start_date', $task->start_date);
            $due = $this->input('due_date', $task->due_date);
            if ($start !== null && $due !== null && Carbon::parse($due)->startOfDay()->lt(Carbon::parse($start)->startOfDay())) {
                $validator->errors()->add('due_date', 'The due date must be a date after or equal to the start date.');
            }
        }];
    }
}
