<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadStorage
{
    /** @param Closure(array{name: string, mime: ?string, size: int|false, path: string}): void $save */
    public function store(UploadedFile $file, string $directory, Closure $save): void
    {
        $name = UploadRules::sanitizeName($file->getClientOriginalName());
        $mime = $file->getMimeType();
        $size = $file->getSize();
        $path = $file->store($directory, 'local');
        abort_if($path === false, 500);

        try {
            $save(['name' => $name, 'mime' => $mime, 'size' => $size, 'path' => $path]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }
}
