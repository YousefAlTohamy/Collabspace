<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\Mime\MimeTypes;

class StoreFileRequest extends FormRequest
{
    /**
     * R7 whitelist: extensions (and matching MIME) only — anything else
     * (php, html, svg, exe, sh, js, ...) is rejected.
     */
    private const ALLOWED_TYPES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg',
        'jpeg', 'webp', 'txt', 'csv', 'md', 'json', 'sql', 'zip',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /**
     * @return array<string, array<int, string|Rule|Closure>>
     */
    public function rules(): array
    {
        return [
            // 10 MB (max() is in kilobytes).
            'file' => [
                'bail',
                'required',
                Rule::file()->types(self::ALLOWED_TYPES)->extensions(self::ALLOWED_TYPES)->max(10 * 1024),
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $extension = strtolower($file->getClientOriginalExtension());
                    $mime = $file->getMimeType();

                    // Plain Markdown, CSV and SQL sometimes have no distinct MIME signature.
                    if ($mime === 'text/plain' && in_array($extension, ['md', 'csv', 'sql'], true)) {
                        return;
                    }

                    if (! in_array($mime, MimeTypes::getDefault()->getMimeTypes($extension), true)) {
                        $fail('The file content must match its extension.');
                    }
                },
            ],
            // R7: the task, if given, must belong to this same project.
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where('project_id', $this->route('project')->id),
            ],
        ];
    }
}
