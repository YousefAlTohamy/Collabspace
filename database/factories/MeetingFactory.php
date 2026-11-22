<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Meeting> */
class MeetingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'created_by' => UserFactory::new(),
            'title' => fake()->words(3, true),
            'starts_at' => now()->addDay(),
            'duration_minutes' => 30,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Meeting $meeting): void {
            $meeting->participants()->syncWithoutDetaching([$meeting->created_by]);
        });
    }
}
