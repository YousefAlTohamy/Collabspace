<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * The workspace teams from the SPEC (§4).
     *
     * @var list<string>
     */
    private const TEAMS = ['UI/UX', 'Frontend', 'Backend', 'Marketing'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TEAMS as $name) {
            Team::query()->firstOrCreate(['name' => $name]);
        }
    }
}
