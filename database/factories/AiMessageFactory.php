<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiRole;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiMessage> */
class AiMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'role' => AiRole::User,
            'content' => fake()->sentence(),
        ];
    }
}
