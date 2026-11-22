<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiRole;
use App\Http\Requests\ListAiMessagesRequest;
use App\Http\Requests\StoreAiMessageRequest;
use App\Http\Resources\AiMessageResource;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AiController extends Controller
{
    private const SYSTEM_PROMPT = 'You are the Collabspace assistant. Give concise answers. You have no access to user projects or data.';

    public function index(ListAiMessagesRequest $request): AnonymousResourceCollection
    {
        $messages = AiMessage::query()->where('user_id', $request->user()->id)->latest('id')
            ->cursorPaginate((int) ($request->validated('per_page') ?? 30));

        return AiMessageResource::collection($messages)->preserveQuery();
    }

    public function store(StoreAiMessageRequest $request, AiClient $ai): JsonResponse
    {
        $userId = $request->user()->id;
        $content = $request->validated('content');
        $history = AiMessage::query()->where('user_id', $userId)->latest('id')->limit(20)->get()
            ->reverse()->map(fn (AiMessage $message): array => ['role' => $message->role->value, 'content' => $message->content])->values()->all();

        $answer = $ai->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ...$history,
            ['role' => AiRole::User->value, 'content' => $content],
        ]);

        $messages = DB::transaction(function () use ($userId, $content, $answer): array {
            // Serialize the insert of each pair for a single user's history.
            User::query()->lockForUpdate()->findOrFail($userId);
            $userMessage = new AiMessage(['role' => AiRole::User, 'content' => $content]);
            $userMessage->user_id = $userId;
            $userMessage->save();

            // MySQL TEXT also limits the byte length of UTF-8 replies.
            $reply = mb_strcut(mb_substr($answer, 0, 20000), 0, 65535);
            $assistantMessage = new AiMessage(['role' => AiRole::Assistant, 'content' => $reply]);
            $assistantMessage->user_id = $userId;
            $assistantMessage->save();

            return ['user_message' => new AiMessageResource($userMessage), 'assistant_message' => new AiMessageResource($assistantMessage)];
        });

        return response()->json(['data' => $messages], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        AiMessage::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => __('AI history cleared.')]);
    }
}
