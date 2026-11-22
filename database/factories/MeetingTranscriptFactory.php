<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MeetingTranscript;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MeetingTranscript> */
class MeetingTranscriptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meeting_id' => MeetingFactory::new(),
            'offset_seconds' => 0,
            'text' => fake()->sentence(),
        ];
    }
}
