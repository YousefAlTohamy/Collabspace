<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProjectTeamsOverview;
use App\Enums\ProjectRole;
use App\Enums\TaskStatus;
use App\Http\Requests\ListProjectsRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectDetailResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTeamsResource;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AddedToProject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    use AuthorizesRequests;

    public function index(ListProjectsRequest $request): AnonymousResourceCollection
    {
        $query = Project::query()
            // R2: lists never include projects the user is not a member of.
            ->whereHas('members', fn (Builder $members) => $members->where('user_id', $request->user()->id))
            ->with(['members.user', 'members.team'])
            ->withCount(['members', 'files', 'tasks', 'tasks as tasks_completed_count' => fn (Builder $tasks) => $tasks->where('status', TaskStatus::Completed->value)]);

        if ($search = $request->validated('search')) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where('name', 'like', '%'.$escaped.'%');
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
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = DB::transaction(function () use ($request): Project {
            // Attribute assignment (not fill) keeps created_by out of mass
            // assignment while still setting it explicitly from the session.
            $project = new Project($request->safe()->except('members'));
            $project->created_by = $request->user()->id;
            $project->save();
            Conversation::createProjectChannel($project);
            // Pick up DB defaults (status = active) that are absent from the payload.
            $project->refresh();

            // R1: the creator becomes the leader.
            $project->members()->create([
                'user_id' => $request->user()->id,
                'role' => ProjectRole::Leader,
            ]);

            $members = $request->validated('members') ?? [];
            foreach ($members as $member) {
                $project->members()->create([
                    'user_id' => $member['user_id'],
                    'role' => $member['role'] ?? ProjectRole::Member,
                    'team_id' => $member['team_id'] ?? null,
                    'job_title' => $member['job_title'] ?? null,
                ]);
            }

            $actor = $request->user();
            $added = User::query()->whereKey(array_column($members, 'user_id'))->whereKeyNot($actor->id)->get();
            Notification::send($added, new AddedToProject($project->id, $actor));

            return $project;
        });

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
        $project->update($request->validated());

        return $this->detailResponse($project);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        DB::transaction(fn () => $project->delete());

        // R7: once the rows are gone, the stored files have no owner anymore.
        abort_unless(Storage::disk('local')->deleteDirectory('projects/'.$project->id), 500);

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
