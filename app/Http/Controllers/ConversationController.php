<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListConversations;
use App\Enums\ConversationType;
use App\Http\Requests\ListConversationsRequest;
use App\Http\Requests\StoreDirectConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(ListConversationsRequest $request, ListConversations $inbox): AnonymousResourceCollection
    {
        $conversations = $inbox->execute($request->user(), (int) ($request->validated('per_page') ?? 15), $request->validated('q'));

        return ConversationResource::collection($conversations)->withQuery($request->validated());
    }

    public function storeDirect(StoreDirectConversationRequest $request): JsonResponse
    {
        $actor = $request->user();
        $other = User::query()->findOrFail($request->validated('user_id'));
        Gate::authorize('startDirect', [Conversation::class, $other]);

        $conversation = Conversation::query()->firstOrCreate([
            'user_one_id' => min($actor->id, $other->id),
            'user_two_id' => max($actor->id, $other->id),
        ], ['type' => ConversationType::Direct]);

        // The direct create response uses the inbox resource, including an existing last message.
        $conversation->setRelation('lastMessage', $conversation->messages()->with('user')->latest('id')->first());
        $conversation->setRelation('userOne', $actor->id === $conversation->user_one_id ? $actor : $other);
        $conversation->setRelation('userTwo', $actor->id === $conversation->user_two_id ? $actor : $other);

        return (new ConversationResource($conversation))->response()->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }
}
