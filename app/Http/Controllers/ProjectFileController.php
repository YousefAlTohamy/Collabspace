<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListFilesRequest;
use App\Http\Requests\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Support\Like;
use App\Support\UploadRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProjectFileController extends Controller
{
    use AuthorizesRequests;

    public function index(ListFilesRequest $request, Project $project): AnonymousResourceCollection
    {
        $files = $project->files()
            ->with('uploader')
            ->orderByDesc('created_at');

        return FileResource::collection($this->filtered($files, $request)->paginate((int) ($request->validated('per_page') ?? 15)));
    }

    /**
     * Files across all my projects (GET /files).
     */
    public function indexAll(ListFilesRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = ProjectFile::query()
            // R2: only files of projects I am a member of.
            ->whereHas('project', fn (Builder $project) => $project->visibleTo($user))
            ->with('uploader')
            ->orderByDesc('created_at');

        if (($projectId = $request->validated('project_id')) !== null) {
            $project = Project::findVisibleOrFail($user, $projectId);
            $query->where('project_id', $project->id);
        }

        return FileResource::collection($this->filtered($query, $request)->paginate((int) ($request->validated('per_page') ?? 15)));
    }

    /**
     * R7 upload: whitelist + size validated, stored on the private disk with a
     * random name; only the original name is kept in the database.
     *
     * @throws AuthorizationException
     */
    public function store(StoreFileRequest $request, Project $project): JsonResponse
    {
        $uploaded = $request->validated('file');

        $file = new ProjectFile([
            'task_id' => $request->validated('task_id'),
            'original_name' => UploadRules::sanitizeName($uploaded->getClientOriginalName()),
            'mime_type' => (string) $uploaded->getMimeType(),
            'size' => (int) $uploaded->getSize(),
        ]);
        $file->project_id = $project->id;
        $file->uploaded_by = $request->user()->id;
        $file->path = $uploaded->store("projects/{$project->id}", 'local');
        abort_if($file->path === false, 500);

        try {
            $file->save();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($file->path);

            throw $exception;
        }

        return (new FileResource($file->load('uploader')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * R7: download only through this authorized endpoint, always as an
     * attachment with nosniff.
     *
     * @throws AuthorizationException
     */
    public function download(Request $request, ProjectFile $file): StreamedResponse
    {
        $this->authorize('view', $file);

        if (! Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, ProjectFile $file): JsonResponse
    {
        $this->authorize('delete', $file); // uploader or project leader

        abort_unless(Storage::disk('local')->delete($file->path), 500);
        $file->delete();

        return response()->json(['message' => __('File deleted.')]);
    }

    /**
     * Shared filters for both list endpoints.
     */
    private function filtered(Builder $query, ListFilesRequest $request): Builder
    {
        if (($search = $request->validated('search')) !== null) {
            $query->where('original_name', 'like', Like::contains($search));
        }

        return $query;
    }
}
