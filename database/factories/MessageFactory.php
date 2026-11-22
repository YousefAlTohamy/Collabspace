<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => ConversationFactory::new(),
            'user_id' => function (array $attributes): int {
                $conversation = Conversation::query()->findOrFail($attributes['conversation_id']);

                return $conversation->user_one_id ?? $conversation->project->created_by;
            },
            'body' => fake()->sentence(),
        ];
    }
}
