<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteDirectory;

class DeleteProject
{
    public function execute(Project $project): void
    {
        $channelIds = DB::transaction(function () use ($project): array {
            $channelIds = Conversation::query()->where('project_id', $project->id)->pluck('id')->all();

            abort_unless($project->delete(), 500);

            return $channelIds;
        });

        // R7: once the rows are gone, the stored files have no owner anymore.
        $disk = Storage::disk('local');
        $directories = ['projects/'.$project->id];
        foreach ($channelIds as $channelId) {
            $directories[] = 'chat/'.$channelId;
        }

        foreach ($directories as $directory) {
            try {
                $removed = $disk->deleteDirectory($directory);
            } catch (UnableToDeleteDirectory) {
                $removed = false;
            }

            if (! $removed) {
                Log::warning('Project storage cleanup failed.', ['project_id' => $project->id]);
            }
        }
    }
}
