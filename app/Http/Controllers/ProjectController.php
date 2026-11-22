<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\ProjectTeamsOverview;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Requests\ListProjectsRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectDetailResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTeamsResource;
use App\Models\Project;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    use AuthorizesRequests;

    public function index(ListProjectsRequest $request): AnonymousResourceCollection
    {
        $query = Project::query()
            // R2: lists never include projects the user is not a member of.
            ->visibleTo($request->user())
            ->with(['members.user', 'members.team'])
            ->withCount(['members', 'files', 'tasks', 'tasks as tasks_completed_count' => fn (Builder $tasks) => $tasks->where('status', TaskStatus::Completed->value)]);

        if ($search = $request->validated('search')) {
            $query->where('name', 'like', Like::contains($search));
        }

        if ($status = $request->validated('status')) {
            // validated() carries the enum value string, matching the column.
            $query->where('status', $status);
        }

        $query->orderBy('created_at', $request->validated('sort') === 'oldest' ? 'asc' : 'desc');

        return ProjectResource::collection($query->paginate($request->validated('per_page', 15)));
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreProjectRequest $request, CreateProject $action): JsonResponse
    {
        $project = $action->execute($request->user(), $request->safe()->except('members'), $request->validated('members') ?? []);

        return $this->detailResponse($project)->response()->setStatusCode(201);
    }

    /**
     * @throws AuthorizationException
     */
    public function show(Request $request, Project $project): ProjectDetailResource
    {
        $this->authorize('view', $project); // R2

        return $this->detailResponse($project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectDetailResource
    {
        $project = DB::transaction(function () use ($request, $project): Project {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            $current->fill($request->validated());

            if ($current->isDirty('status')) {
                $current->completed_at = $current->status === ProjectStatus::Done
                    ? ($current->completed_at ?? now())
                    : null;
            }
            $current->save();

            return $current;
        });

        return $this->detailResponse($project);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Project $project, DeleteProject $action): JsonResponse
    {
        $this->authorize('delete', $project);

        $action->execute($project);

        return response()->json(['message' => __('Project deleted.')]);
    }

    /**
     * Teams tab: members grouped by team with per-member and per-team task
     * progress (aggregates only, see ProjectTeamsOverview).
     *
     * @throws AuthorizationException
     */
    public function teams(Request $request, Project $project, ProjectTeamsOverview $overview): ProjectTeamsResource
    {
        $this->authorize('view', $project); // R2

        return new ProjectTeamsResource($overview->execute($project));
    }

    private function detailResponse(Project $project): ProjectDetailResource
    {
        $project->loadCount('members')->load(['members.user', 'members.team']);

        // R5: one grouped query for the tasks-by-status breakdown.
        $taskCounts = $project->tasks()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        return new ProjectDetailResource($project, $taskCounts);
    }
}
