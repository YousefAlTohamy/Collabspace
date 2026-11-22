<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListCommentsRequest;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Requests\UpdateCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskCommentController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(ListCommentsRequest $request, Task $task): AnonymousResourceCollection
    {
        $comments = $task->comments()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 15));

        return TaskCommentResource::collection($comments);
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreCommentRequest $request, Task $task): JsonResponse
    {
        $comment = new TaskComment(['body' => $request->validated('body')]);
        $comment->task_id = $task->id;
        $comment->user_id = $request->user()->id;
        $comment->save();

        return (new TaskCommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateCommentRequest $request, TaskComment $comment): TaskCommentResource
    {
        $comment->update($request->validated());

        return new TaskCommentResource($comment->load('user'));
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Request $request, TaskComment $comment): JsonResponse
    {
        $this->authorize('delete', $comment); // author or project leader

        $comment->delete();

        return response()->json(['message' => __('Comment deleted.')]);
    }
}
