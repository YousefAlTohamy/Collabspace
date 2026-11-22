<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_enforces_membership_escapes_wildcards_and_limits_each_list_to_five(): void
    {
        $owner = User::factory()->create();
        $uri = '/api/v1/search?q='.urlencode('Release%_');
        $addData = function (User $user, string $name): Project {
            $project = Project::factory()->for($user, 'creator')->create(['name' => $name]);
            Task::factory()->for($project)->create(['title' => $name]);
            ProjectFile::factory()->for($project)->create(['original_name' => $name.'.pdf']);

            return $project;
        };
        $this->getJson($uri)->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/search?q=x')->assertUnprocessable()->assertJsonValidationErrors('q');
        $addData(User::factory()->create(), 'Release%_ foreign');
        $addData($owner, 'ReleaseXY wildcard');
        $addData($owner, 'Release%_ first');

        $search = function (int $expected) use ($uri): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->getJson($uri)->assertOk()
                    ->assertJsonCount($expected, 'data.projects')
                    ->assertJsonCount($expected, 'data.tasks')
                    ->assertJsonCount($expected, 'data.files');

                return [$response->json('data'), count(DB::getQueryLog())];
            } finally {
                DB::disableQueryLog();
            }
        };

        [$first, $queryCount] = $search(1);
        $this->assertSame('Release%_ first', $first['projects'][0]['name']);
        $this->assertSame(3, $queryCount);

        for ($i = 0; $i < 6; $i++) {
            $last = $addData($owner, 'Release%_ item '.$i);
        }
        [$many, $manyQueries] = $search(5);
        $this->assertSame($queryCount, $manyQueries);
        $this->assertSame($last->id, $many['projects'][0]['id']);
        $this->assertSame($last->name, $many['tasks'][0]['project_name']);
        $this->assertSame($last->name, $many['files'][0]['project_name']);
    }
}
