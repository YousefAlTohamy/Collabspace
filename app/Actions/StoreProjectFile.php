<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use App\Support\UploadStorage;
use Illuminate\Http\UploadedFile;

class StoreProjectFile
{
    public function __construct(private readonly UploadStorage $storage) {}

    public function execute(User $actor, Project $project, UploadedFile $upload, int|string|null $taskId): ProjectFile
    {
        $file = new ProjectFile(['task_id' => $taskId]);
        $file->project_id = $project->id;
        $file->uploaded_by = $actor->id;

        $this->storage->store($upload, "projects/{$project->id}", function (array $stored) use ($file): void {
            $file->original_name = $stored['name'];
            $file->mime_type = (string) $stored['mime'];
            $file->size = (int) $stored['size'];
            $file->path = $stored['path'];
            $file->save();
        });

        return $file;
    }
}
