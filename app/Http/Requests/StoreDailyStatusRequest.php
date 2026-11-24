<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDailyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'progress' => ['required', 'string', 'max:2000'],
            'next_steps' => ['required', 'string', 'max:2000'],
            'blockers' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
