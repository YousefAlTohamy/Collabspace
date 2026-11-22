<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->optional()->dateTimeBetween('-1 week', 'now');

        return [
            'project_id' => ProjectFactory::new(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'progress' => 0,
            'start_date' => $startDate,
            'due_date' => $startDate !== null ? fake()->dateTimeBetween($startDate, '+1 month') : fake()->dateTimeBetween('now', '+1 month'),
            'reviewer_id' => null,
            'created_by' => fn (array $attributes): int => Project::query()->findOrFail($attributes['project_id'])->created_by,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => TaskStatus::InProgress]);
    }

    public function inReview(): static
    {
        return $this->state(['status' => TaskStatus::InReview]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Completed,
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function forProject(int $projectId): static
    {
        return $this->state(fn (): array => ['project_id' => $projectId]);
    }
}
