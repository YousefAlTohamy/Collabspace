<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListMeetingTranscriptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
