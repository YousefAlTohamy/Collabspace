<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskComment>
 */
class TaskCommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => TaskFactory::new(),
            'user_id' => fn (array $attributes): int => Task::query()->findOrFail($attributes['task_id'])->created_by,
            'body' => fake()->sentence(),
        ];
    }
}
