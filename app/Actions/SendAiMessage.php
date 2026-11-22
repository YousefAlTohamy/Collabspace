<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AiRole;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AiClient;
use Illuminate\Support\Facades\DB;

class SendAiMessage
{
    private const SYSTEM_PROMPT = 'You are the Collabspace assistant. Give concise answers. You have no access to user projects or data.';

    public function __construct(private readonly AiClient $ai) {}

    /** @return array{user_message: AiMessage, assistant_message: AiMessage} */
    public function execute(int $userId, string $content): array
    {
        $answer = $this->ai->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ...$this->boundedHistory($userId),
            ['role' => AiRole::User->value, 'content' => $content],
        ]);

        return DB::transaction(function () use ($userId, $content, $answer): array {
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

            return ['user_message' => $userMessage, 'assistant_message' => $assistantMessage];
        });
    }

    /** @return list<array{role: string, content: string}> */
    private function boundedHistory(int $userId): array
    {
        $messages = AiMessage::query()->where('user_id', $userId)->latest('id')->limit(20)->get();
        $remaining = 12000;
        $history = [];

        foreach ($messages as $message) {
            $content = mb_substr($message->content, 0, 2000);
            $length = mb_strlen($content);
            if ($length > $remaining) {
                break;
            }

            $history[] = ['role' => $message->role->value, 'content' => $content];
            $remaining -= $length;
        }

        return array_reverse($history);
    }
}
