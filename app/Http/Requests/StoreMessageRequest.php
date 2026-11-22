<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('conversation'));
    }

    public function rules(): array
    {
        return [
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'attachment' => ['required_without:body', 'nullable', ...UploadRules::rules()],
        ];
    }
}
