<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project === null || $this->user()->can('view', $project);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'project_id' => [$this->route('project') === null ? 'nullable' : 'exclude', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
