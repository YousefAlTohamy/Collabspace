<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListMessagesRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MessageController extends Controller
{
    public function index(ListMessagesRequest $request, Conversation $conversation): AnonymousResourceCollection
    {
        $messages = $conversation->messages()->with('user')->latest('id')
            ->cursorPaginate((int) ($request->validated('per_page') ?? 30));

        return MessageResource::collection($messages)->preserveQuery();
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $conversation->messages()->make(['body' => $request->validated('body')]);
        $message->user_id = $request->user()->id;
        $disk = Storage::disk('local');

        if ($attachment = $request->validated('attachment')) {
            $message->attachment_name = UploadRules::sanitizeName($attachment->getClientOriginalName());
            $message->attachment_mime = $attachment->getMimeType();
            $message->attachment_size = $attachment->getSize();
            $message->attachment_path = $attachment->store("chat/{$conversation->id}", 'local');
            abort_if($message->attachment_path === false, 500);
        }

        try {
            $message->save();
        } catch (Throwable $exception) {
            if ($message->attachment_path !== null) {
                $disk->delete($message->attachment_path);
            }

            throw $exception;
        }

        return (new MessageResource($message->load('user')))->response()->setStatusCode(201);
    }

    public function attachment(Message $message): StreamedResponse
    {
        Gate::authorize('view', $message->conversation);
        $disk = Storage::disk('local');

        abort_if($message->attachment_path === null || ! $disk->exists($message->attachment_path), 404);

        return $disk->download($message->attachment_path, $message->attachment_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
