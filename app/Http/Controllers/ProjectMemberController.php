<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddProjectMember;
use App\Actions\RemoveProjectMember;
use App\Actions\UpdateProjectMember;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectMemberController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(PaginationRequest $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project); // R2

        $perPage = (int) ($request->validated('per_page') ?? 15);

        $members = $project->members()
            ->with(['user', 'team'])
            ->orderBy('id')
            ->paginate($perPage);

        return ProjectMemberResource::collection($members)->withQuery($request->validated());
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreMemberRequest $request, Project $project, AddProjectMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $member = $action->execute($request->user(), $project, $request->validated());

        return (new ProjectMemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateMemberRequest $request, Project $project, User $user, UpdateProjectMember $action): ProjectMemberResource
    {
        $this->authorize('manageMembers', $project);

        $member = $this->memberOf($project, $user);

        $action->execute($project, $member, $request->validated());

        return new ProjectMemberResource($member->load(['user', 'team']));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Project $project, User $user, RemoveProjectMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $action->execute($project, $this->memberOf($project, $user));

        return response()->json(['message' => __('Member removed.')]);
    }

    /**
     * The {user} route parameter must be a member of {project}, otherwise 404.
     */
    private function memberOf(Project $project, User $user): ProjectMember
    {
        return $project->members()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
