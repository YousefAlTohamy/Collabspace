<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectFile
 */
class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'name' => $this->original_name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            // The storage path is never exposed; download goes through the
            // authorized endpoint (R7).
            'download_url' => route('files.download', ['file' => $this->id]),
            'uploaded_by' => $this->whenLoaded('uploader', fn (): UserSummaryResource => new UserSummaryResource($this->uploader)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
