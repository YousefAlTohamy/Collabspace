<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ConversationType::Direct,
            'user_one_id' => UserFactory::new(),
            'user_two_id' => UserFactory::new(),
        ];
    }
}
