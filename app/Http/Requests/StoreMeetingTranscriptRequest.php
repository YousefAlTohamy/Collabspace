<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingTranscriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addTranscript', $this->route('meeting'));
    }

    public function rules(): array
    {
        $meeting = $this->route('meeting');

        return [
            'entries' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'entries.*' => ['required', 'array:speaker_id,offset_seconds,text'],
            'entries.*.speaker_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('meeting_participants', 'user_id')->where('meeting_id', $meeting->id),
            ],
            'entries.*.offset_seconds' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'entries.*.text' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
