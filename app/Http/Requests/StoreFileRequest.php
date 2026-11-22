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

    // Libmagic can identify the Office container or plain text without identifying its specific format.
    private const MIME_FALLBACKS = [
        'docx' => ['application/zip'],
        'xlsx' => ['application/zip'],
        'pptx' => ['application/zip'],
        'doc' => ['application/x-ole-storage', 'application/cdfv2'],
        'xls' => ['application/x-ole-storage', 'application/cdfv2'],
        'ppt' => ['application/x-ole-storage', 'application/cdfv2'],
        'json' => ['application/json', 'text/plain'],
        'md' => ['text/plain'],
        'csv' => ['text/plain'],
        'sql' => ['text/plain'],
        'txt' => ['text/plain'],
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
                Rule::file()->extensions(self::ALLOWED_TYPES)->max(10 * 1024),
                function (string $attribute, UploadedFile $file, Closure $fail): void {
                    $extension = strtolower($file->getClientOriginalExtension());
                    $mime = $file->getMimeType() ?? '';

                    if (in_array(strtolower($mime), self::MIME_FALLBACKS[$extension] ?? [], true)) {
                        return;
                    }

                    // Preserve Laravel's guessed-extension whitelist for all other MIME types.
                    $mimeTypes = MimeTypes::getDefault();
                    $guessedExtension = $mimeTypes->getExtensions($mime)[0] ?? null;
                    if (! in_array($guessedExtension, self::ALLOWED_TYPES, true)
                        || ! in_array($mime, $mimeTypes->getMimeTypes($extension), true)) {
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
