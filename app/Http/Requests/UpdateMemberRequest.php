<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::enum(ProjectRole::class)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'job_title' => ['nullable', 'string', 'max:100'],
        ];
    }
}
