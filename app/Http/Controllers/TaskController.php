<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ChangeTaskStatus;
use App\Actions\CreateTask;
use App\Actions\UpdateTask;
use App\Enums\TaskStatus;
use App\Http\Requests\ChangeTaskStatusRequest;
use App\Http\Requests\ListTasksRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
    public function store(StoreTaskRequest $request, Project $project, CreateTask $action): JsonResponse
    {
        $task = $action->execute($request->user(), $project, $request->validated());

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
    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $action): TaskResource
    {
        $task = $action->execute($request->user(), $task, $request->validated());

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
