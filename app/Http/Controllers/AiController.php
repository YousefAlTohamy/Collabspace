<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SendAiMessage;
use App\Http\Requests\ListAiMessagesRequest;
use App\Http\Requests\StoreAiMessageRequest;
use App\Http\Resources\AiMessageResource;
use App\Models\AiMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AiController extends Controller
{
    public function index(ListAiMessagesRequest $request): AnonymousResourceCollection
    {
        $messages = AiMessage::query()->where('user_id', $request->user()->id)->latest('id')
            ->cursorPaginate((int) ($request->validated('per_page') ?? 30));

        return AiMessageResource::collection($messages)->preserveQuery();
    }

    public function store(StoreAiMessageRequest $request, SendAiMessage $action): JsonResponse
    {
        $messages = $action->execute($request->user()->id, $request->validated('content'));

        return response()->json(['data' => [
            'user_message' => new AiMessageResource($messages['user_message']),
            'assistant_message' => new AiMessageResource($messages['assistant_message']),
        ]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        AiMessage::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => __('AI history cleared.')]);
    }
}
