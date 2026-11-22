<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\UploadStorage;
use Illuminate\Http\UploadedFile;

class SendChatMessage
{
    public function __construct(private readonly UploadStorage $storage) {}

    public function execute(User $actor, Conversation $conversation, ?string $body, ?UploadedFile $attachment): Message
    {
        $message = $conversation->messages()->make(['body' => $body]);
        $message->user_id = $actor->id;

        if ($attachment === null) {
            $message->save();
        } else {
            $this->storage->store($attachment, "chat/{$conversation->id}", function (array $stored) use ($message): void {
                $message->attachment_name = $stored['name'];
                $message->attachment_mime = $stored['mime'];
                $message->attachment_size = $stored['size'];
                $message->attachment_path = $stored['path'];
                $message->save();
            });
        }

        return $message;
    }
}
