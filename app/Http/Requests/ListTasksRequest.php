<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project === null || $this->user()->can('view', $project);
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        $isProjectBoard = $this->route('project') !== null;

        return [
            'project_id' => [$isProjectBoard ? 'exclude' : 'nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assignee_id' => [$isProjectBoard ? 'nullable' : 'exclude', 'integer', Rule::exists('project_members', 'user_id')->where('project_id', $this->route('project')?->id)],
            'team_id' => ['nullable', 'integer', 'min:1', Rule::exists('teams', 'id')],
            'assigned_to_me' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', $isProjectBoard ? 'max:100' : 'max:50'],
        ];
    }
}
