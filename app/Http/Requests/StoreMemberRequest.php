<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::unique('project_members', 'user_id')
                    ->where('project_id', $this->route('project')->id),
            ],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(ProjectRole::class)],
        ];
    }
}
