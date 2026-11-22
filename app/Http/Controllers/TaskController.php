<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ChangeTaskStatus;
use App\Enums\TaskStatus;
use App\Http\Requests\ChangeTaskStatusRequest;
use App\Http\Requests\ListTasksRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    use AuthorizesRequests;

    public function index(ListTasksRequest $request, Project $project): AnonymousResourceCollection
    {
        $tasks = $project->tasks()
            ->with(['reviewer', 'assignees'])
            ->withCount('comments')
            ->orderByDesc('created_at');

        return TaskResource::collection($this->filtered($tasks, $request)->paginate((int) ($request->validated('per_page') ?? 50)));
    }

    /**
     * Tasks across all my projects (GET /tasks).
     */
    public function indexAll(ListTasksRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Task::query()
            // R2: only tasks of projects I am a member of.
            ->whereHas('project', fn (Builder $project) => $project->visibleTo($user))
            ->with(['reviewer', 'assignees', 'project'])
            ->withCount('comments')
            ->orderByDesc('created_at');

        if (($projectId = $request->validated('project_id')) !== null) {
            $project = Project::findVisibleOrFail($user, $projectId);
            $query->where('project_id', $project->id);
        }

        return TaskResource::collection($this->filtered($query, $request)->paginate((int) ($request->validated('per_page') ?? 15)));
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreTaskRequest $request, Project $project): JsonResponse
    {
        $task = DB::transaction(function () use ($request, $project): Task {
            $this->lockTaskMembers($project->id, $request);
            $task = new Task($request->safe()->except('assignee_ids'));
            $task->project_id = $project->id;
            $task->created_by = $request->user()->id;
            $task->save();
            $changes = $task->assignees()->sync($request->validated('assignee_ids') ?? []);
            $this->notifyAssigned($task, $request->user(), $changes['attached']);

            return $task->refresh();
        });

        return (new TaskResource($task->load(['reviewer', 'assignees'])->loadCount('comments')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @throws AuthorizationException
     */
    public function show(Request $request, Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load(['reviewer', 'assignees'])->loadCount('comments'));
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $task = DB::transaction(function () use ($request, $task): Task {
            $this->lockTaskMembers($task->project_id, $request);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $task->update($request->safe()->except('assignee_ids'));

            if ($request->safe()->has('assignee_ids')) {
                $changes = $task->assignees()->sync($request->validated('assignee_ids'));
                $this->notifyAssigned($task, $request->user(), $changes['attached']);
            }

            return $task;
        });

        return new TaskResource($task->load(['reviewer', 'assignees'])->loadCount('comments'));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->json(['message' => __('Task deleted.')]);
    }

    /**
     * R4: the policy decides who may make this exact transition; the action
     * applies the progress/completed_at side effects.
     *
     * @throws AuthorizationException
     */
    public function changeStatus(ChangeTaskStatusRequest $request, Task $task, ChangeTaskStatus $action): TaskResource
    {
        $to = TaskStatus::from($request->validated('status'));
        $progress = $request->validated('progress');
        $task = $action->execute($request->user(), $task, $to, $progress === null ? null : (int) $progress);

        return new TaskResource($task->load(['reviewer', 'assignees'])->loadCount('comments'));
    }

    /** @param list<int> $attached */
    private function notifyAssigned(Task $task, User $actor, array $attached): void
    {
        $assignees = User::query()->whereKey($attached)->whereKeyNot($actor->id)->get();
        Notification::send($assignees, new TaskAssigned($task->id, $task->project_id, $actor));
    }

    private function lockTaskMembers(int $projectId, StoreTaskRequest|UpdateTaskRequest $request): void
    {
        $memberFields = [];
        foreach ($request->validated('assignee_ids') ?? [] as $index => $userId) {
            $memberFields["assignee_ids.{$index}"] = (int) $userId;
        }
        if (($reviewerId = $request->validated('reviewer_id')) !== null) {
            $memberFields['reviewer_id'] = (int) $reviewerId;
        }
        if ($memberFields === []) {
            return;
        }

        // R3: serialize assignment writes with removal of those project members.
        $members = ProjectMember::query()
            ->where('project_id', $projectId)
            ->whereIn('user_id', $memberFields)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->pluck('user_id')
            ->all();

        $missingFields = array_keys(array_diff($memberFields, $members));
        if ($missingFields !== []) {
            throw ValidationException::withMessages(array_fill_keys($missingFields, __('The selected user must belong to the project.')));
        }
    }

    /**
     * Shared filters for both list endpoints.
     */
    private function filtered(Builder $query, ListTasksRequest $request): Builder
    {
        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->validated('priority')) {
            $query->where('priority', $priority);
        }

        if ($assigneeId = $request->validated('assignee_id')) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $assigneeId));
        }

        if ($request->validated('assigned_to_me')) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $request->user()->id));
        }

        if (($search = $request->validated('search')) !== null) {
            $query->where('title', 'like', Like::contains($search));
        }

        return $query;
    }
}
