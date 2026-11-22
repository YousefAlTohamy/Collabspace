<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', ...UploadRules::rules()],
            // R7: the task, if given, must belong to this same project.
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where('project_id', $this->route('project')->id),
            ],
        ];
    }
}
