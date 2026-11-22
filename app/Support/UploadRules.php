<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\Mime\MimeTypes;

class UploadRules
{
    // R7: the original extension and the detected MIME must agree.
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

    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';

        return mb_substr(trim($name), 0, 255) ?: 'download';
    }

    /** @return list<string|File|Closure> */
    public static function rules(): array
    {
        return [
            'bail',
            Rule::file()->extensions(self::ALLOWED_TYPES)->max(10 * 1024),
            function (string $attribute, UploadedFile $file, Closure $fail): void {
                $extension = strtolower($file->getClientOriginalExtension());
                $mime = $file->getMimeType() ?? '';

                if (in_array(strtolower($mime), self::MIME_FALLBACKS[$extension] ?? [], true)) {
                    return;
                }

                $mimeTypes = MimeTypes::getDefault();
                $guessedExtension = $mimeTypes->getExtensions($mime)[0] ?? null;
                if (! in_array($guessedExtension, self::ALLOWED_TYPES, true)
                    || ! in_array($mime, $mimeTypes->getMimeTypes($extension), true)) {
                    $fail('The file content must match its extension.');
                }
            },
        ];
    }
}
