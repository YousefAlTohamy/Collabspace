<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\ProjectMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMember>
 */
class ProjectMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => ProjectFactory::new(),
            'user_id' => UserFactory::new(),
            'role' => ProjectRole::Member,
            'team_id' => null,
            'job_title' => fake()->optional()->jobTitle(),
        ];
    }

    public function leader(): static
    {
        return $this->state(fn (): array => ['role' => ProjectRole::Leader]);
    }
}
