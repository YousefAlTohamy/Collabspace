<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-2 months', 'now');

        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['SaaS', 'Mobile app', 'Website']),
            'priority' => fake()->randomElement(ProjectPriority::cases()),
            'status' => ProjectStatus::Active,
            'start_date' => $startDate,
            'end_date' => fake()->dateTimeBetween($startDate, '+6 months'),
            'created_by' => UserFactory::new(),
        ];
    }

    /**
     * R1: the creator is always the project's leader, so every project created
     * through this factory is consistent with that rule.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $project->members()->create([
                'user_id' => $project->created_by,
                'role' => ProjectRole::Leader,
            ]);
        });
    }

    public function done(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Done]);
    }
}
