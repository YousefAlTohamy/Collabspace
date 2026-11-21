<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectRole;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ProjectMemberController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project); // R2

        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $perPage = (int) ($validated['per_page'] ?? 15);

        $members = $project->members()
            ->with(['user', 'team'])
            ->orderBy('id')
            ->paginate($perPage);

        return ProjectMemberResource::collection($members);
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreMemberRequest $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $member = $project->members()->create([
            'user_id' => $request->validated('user_id'),
            'role' => $request->validated('role', ProjectRole::Member),
            'team_id' => $request->validated('team_id'),
            'job_title' => $request->validated('job_title'),
        ]);

        return (new ProjectMemberResource($member->load(['user', 'team'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateMemberRequest $request, Project $project, User $user): ProjectMemberResource
    {
        $this->authorize('manageMembers', $project);

        $member = $this->memberOf($project, $user);

        $newRole = (string) $request->validated('role', '');
        $demotesLastLeader = $newRole === ProjectRole::Member->value
            && $member->role === ProjectRole::Leader
            && $project->leaderCount() === 1;
        if ($demotesLastLeader) {
            // R1: a project must always keep at least one leader.
            throw ValidationException::withMessages([
                'role' => __('A project must always keep at least one leader.'),
            ]);
        }

        $member->update($request->validated());

        return new ProjectMemberResource($member->load(['user', 'team']));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $member = $this->memberOf($project, $user);

        if ($member->role === ProjectRole::Leader && $project->leaderCount() === 1) {
            // R1: a project must always keep at least one leader.
            throw ValidationException::withMessages([
                'role' => __('A project must always keep at least one leader.'),
            ]);
        }

        $member->delete();

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
