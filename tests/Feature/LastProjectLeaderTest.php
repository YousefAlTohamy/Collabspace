<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LastProjectLeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_to_last_leader_can_be_demoted_or_removed(): void
    {
        $project = Project::factory()->create();
        $leader = $project->creator;
        $otherLeader = User::factory()->create();
        $project->members()->create(['user_id' => $otherLeader->id, 'role' => ProjectRole::Leader]);

        $this->actingAs($leader, 'sanctum');
        $path = "/api/v1/projects/{$project->id}/members";
        $this->putJson("{$path}/{$otherLeader->id}", ['role' => 'member'])
            ->assertOk()->assertJsonPath('data.role', 'member');
        $this->putJson("{$path}/{$leader->id}", ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'A project must always keep at least one leader.');

        $this->putJson("{$path}/{$otherLeader->id}", ['role' => 'leader'])->assertOk();
        $this->deleteJson("{$path}/{$otherLeader->id}")->assertOk();
        $this->deleteJson("{$path}/{$leader->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'A project must always keep at least one leader.');

        $this->assertSame([$leader->id], $project->members()->where('role', ProjectRole::Leader->value)->pluck('user_id')->all());
    }
}
