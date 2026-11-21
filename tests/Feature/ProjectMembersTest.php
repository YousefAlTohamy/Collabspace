<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMembersTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->leader = $this->project->creator;
    }

    private function addMember(User $user, array $attributes = []): ProjectMember
    {
        return ProjectMember::factory()
            ->for($this->project)
            ->for($user)
            ->create($attributes);
    }

    public function test_index_requires_authentication_and_membership(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->id}/members")->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/members")
            ->assertForbidden();
    }

    public function test_index_is_available_to_any_member(): void
    {
        $member = $this->addMember(User::factory()->create(['job_title' => 'Backend Developer']));

        $response = $this->actingAs($member->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/members");

        $response->assertOk()->assertJsonCount(2, 'data');

        $entry = collect($response->json('data'))->firstWhere('id', $member->id);
        $this->assertSame('member', $entry['role']);
        $this->assertSame('Backend Developer', $entry['user']['job_title']);
        $this->assertArrayNotHasKey('email', $entry['user']);
    }

    public function test_store_adds_a_member_as_leader(): void
    {
        $newUser = User::factory()->create();
        $team = Team::create(['name' => 'Frontend']);

        $response = $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
                'team_id' => $team->id,
                'job_title' => 'Developer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.user.id', $newUser->id)
            ->assertJsonPath('data.team.id', $team->id);

        $this->assertDatabaseHas('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $newUser->id,
            'role' => 'member',
        ]);
    }

    public function test_store_rejects_duplicates_and_unknown_ids(): void
    {
        $existing = $this->addMember(User::factory()->create());

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/members", ['user_id' => $existing->user_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/members", ['user_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/members", [
                'user_id' => User::factory()->create()->id,
                'team_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('team_id');
    }

    public function test_store_is_leader_only(): void
    {
        $payload = ['user_id' => User::factory()->create()->id];

        $this->actingAs(User::factory()->create(), 'sanctum') // non-member
            ->postJson("/api/v1/projects/{$this->project->id}/members", $payload)
            ->assertForbidden();

        $member = $this->addMember(User::factory()->create());
        $this->actingAs($member->user, 'sanctum') // member, not leader
            ->postJson("/api/v1/projects/{$this->project->id}/members", $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('project_members', 2);
    }

    public function test_update_changes_role_team_and_job_title(): void
    {
        $member = $this->addMember(User::factory()->create());
        $team = Team::create(['name' => 'Marketing']);

        $response = $this->actingAs($this->leader, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}", [
                'role' => 'leader',
                'team_id' => $team->id,
                'job_title' => 'Tech Lead',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'leader')
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.job_title', 'Tech Lead');
    }

    public function test_update_returns_404_for_non_member_user(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->leader, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$stranger->id}", ['role' => 'member'])
            ->assertNotFound();
    }

    public function test_update_is_leader_only(): void
    {
        $member = $this->addMember(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}", ['role' => 'member'])
            ->assertForbidden();

        $this->actingAs($member->user, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}", ['job_title' => 'Hacker'])
            ->assertForbidden();

        $this->assertDatabaseHas('project_members', ['id' => $member->id, 'job_title' => $member->job_title]);
    }

    public function test_r1_last_leader_cannot_be_demoted_or_removed(): void
    {
        // Single leader (the creator).
        $this->actingAs($this->leader, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$this->leader->id}", ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->leader->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $this->project->id,
            'user_id' => $this->leader->id,
            'role' => 'leader',
        ]);
    }

    public function test_r1_with_two_leaders_one_can_be_demoted_and_removed(): void
    {
        $secondLeader = $this->addMember(User::factory()->create(), ['role' => 'leader']);
        $plainMember = $this->addMember(User::factory()->create());

        // Demote one of the two leaders: allowed.
        $this->actingAs($this->leader, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$secondLeader->user_id}", ['role' => 'member'])
            ->assertOk()
            ->assertJsonPath('data.role', 'member');

        // Restore the second leader, then remove them: allowed.
        $this->actingAs($this->leader, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/members/{$secondLeader->user_id}", ['role' => 'leader'])
            ->assertOk();

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$secondLeader->user_id}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        // Now the plain member can be removed too.
        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$plainMember->user_id}")
            ->assertOk();

        $this->assertDatabaseMissing('project_members', ['user_id' => $secondLeader->user_id]);
        $this->assertDatabaseMissing('project_members', ['user_id' => $plainMember->user_id]);
        $this->assertSame(1, $this->project->leaderCount());
    }

    public function test_destroy_is_leader_only(): void
    {
        $member = $this->addMember(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}")
            ->assertForbidden();

        $this->actingAs($member->user, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}")
            ->assertForbidden();

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->project->id}/members/{$member->user_id}")
            ->assertOk();

        $this->assertDatabaseMissing('project_members', ['user_id' => $member->user_id]);
    }
}
