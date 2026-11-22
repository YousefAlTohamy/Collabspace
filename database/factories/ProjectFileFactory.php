<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFile>
 */
class ProjectFileFactory extends Factory
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
            'task_id' => null,
            'uploaded_by' => fn (array $attributes): int => Project::query()->findOrFail($attributes['project_id'])->created_by,
            'original_name' => fake()->word().'.pdf',
            'path' => fn (array $attributes): string => 'projects/'.$attributes['project_id'].'/'.fake()->sha1().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 500000),
        ];
    }
}
