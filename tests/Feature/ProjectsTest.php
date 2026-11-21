<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Project $project): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($project)->for($user)->create();

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', [])->assertUnauthorized();
    }

    public function test_list_returns_only_my_projects(): void
    {
        $me = User::factory()->create();

        $mine = Project::factory()->count(2)->create()
            ->each(fn (Project $project) => ProjectMember::factory()->for($project)->for($me)->create());

        // Other users' projects must never appear (R2).
        Project::factory()->count(3)->create();

        $response = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects');

        $response->assertOk()->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = $mine->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    public function test_list_item_shape_first_four_members_and_count(): void
    {
        $project = Project::factory()->create();
        $me = $this->memberOf($project);

        // 5 extra members + the creator = 6 total.
        User::factory()->count(5)->create()
            ->each(fn (User $user) => ProjectMember::factory()->for($project)->for($user)->create());

        $response = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonPath('data.0.members_count', 7) // creator + me + 5
            ->assertJsonCount(4, 'data.0.members');

        $this->assertSame(
            ['id', 'name', 'description', 'type', 'priority', 'status', 'start_date', 'end_date', 'members_count', 'members'],
            array_keys($response->json('data.0')),
        );

        // No other user's email/phone anywhere in the list item.
        $this->assertStringNotContainsString('email', $response->getContent());
    }

    public function test_list_filters_by_status_and_search(): void
    {
        $me = User::factory()->create();

        $active = Project::factory()->create(['name' => 'Alpha Platform']);
        ProjectMember::factory()->for($active)->for($me)->create();

        $done = Project::factory()->done()->create(['name' => 'Beta Platform']);
        ProjectMember::factory()->for($done)->for($me)->create();

        $other = Project::factory()->create(['name' => 'Gamma Website']);
        ProjectMember::factory()->for($other)->for($me)->create();

        // status filter
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?status=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);

        // search by name contains
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?search=platform')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // search + status combined
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?search=platform&status=done')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);
    }

    public function test_list_search_escapes_like_wildcards(): void
    {
        $me = User::factory()->create();

        $withPercent = Project::factory()->create(['name' => '100% Dev']);
        ProjectMember::factory()->for($withPercent)->for($me)->create();

        $withX = Project::factory()->create(['name' => '100X Dev']);
        ProjectMember::factory()->for($withX)->for($me)->create();

        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?search=100%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '100% Dev');
    }

    public function test_list_sorts_by_creation_date(): void
    {
        $me = User::factory()->create();

        $this->travelTo(now()->subDays(2));
        $older = Project::factory()->create();
        ProjectMember::factory()->for($older)->for($me)->create();
        $this->travelTo(now()->addDays(1));
        $newer = Project::factory()->create();
        ProjectMember::factory()->for($newer)->for($me)->create();
        $this->travelBack();

        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?sort=newest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);

        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }

    public function test_list_query_count_is_constant_from_one_to_ten_projects(): void
    {
        $me = User::factory()->create();

        $attach = function () use ($me): void {
            $project = Project::factory()->create();
            ProjectMember::factory()->for($project)->for($me)->create();
        };

        $attach();

        DB::enableQueryLog();
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects?per_page=50')->assertOk();
        $withOne = count(DB::getQueryLog());
        DB::flushQueryLog();

        Project::factory()->count(9)->create()
            ->each(fn (Project $project) => ProjectMember::factory()->for($project)->for($me)->create());

        // Factory inserts must not pollute the measured request.
        DB::flushQueryLog();

        $this->getJson('/api/v1/projects?per_page=50')->assertOk();
        $withTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withOne, $withTen, "Query count grew with page size: {$withOne} vs {$withTen}");
    }

    public function test_create_makes_creator_leader_and_adds_members(): void
    {
        $me = User::factory()->create();
        $teammate = User::factory()->create();
        $leaderToo = User::factory()->create();
        $team = Team::create(['name' => 'Backend']);

        $response = $this->actingAs($me, 'sanctum')->postJson('/api/v1/projects', [
            'name' => 'New Platform',
            'description' => 'Collab tool',
            'type' => 'SaaS',
            'priority' => 'high',
            'start_date' => '2026-11-01',
            'end_date' => '2027-01-31',
            'members' => [
                ['user_id' => $teammate->id, 'team_id' => $team->id, 'job_title' => 'Developer'],
                ['user_id' => $leaderToo->id, 'role' => 'leader'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Platform')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.start_date', '2026-11-01');

        // R1: creator is the leader.
        $this->assertDatabaseHas('project_members', [
            'project_id' => $response->json('data.id'),
            'user_id' => $me->id,
            'role' => 'leader',
        ]);

        // Members added in the same call, default role member.
        $this->assertDatabaseHas('project_members', [
            'user_id' => $teammate->id,
            'role' => 'member',
            'team_id' => $team->id,
            'job_title' => 'Developer',
        ]);
        $this->assertDatabaseHas('project_members', [
            'user_id' => $leaderToo->id,
            'role' => 'leader',
        ]);

        $this->assertDatabaseHas('projects', ['name' => 'New Platform', 'created_by' => $me->id]);
    }

    public function test_create_rejects_invalid_member_payloads_and_dates(): void
    {
        $me = User::factory()->create();
        $teammate = User::factory()->create();

        $payload = [
            'name' => 'New Platform',
            'priority' => 'medium',
            'start_date' => '2026-11-01',
            'end_date' => '2027-01-31',
        ];

        // R6: end_date before start_date.
        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/projects', [...$payload, 'end_date' => '2026-10-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        // Duplicate member ids.
        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/projects', [...$payload, 'members' => [
                ['user_id' => $teammate->id],
                ['user_id' => $teammate->id],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('members.0.user_id');

        // The creator cannot be listed as a member.
        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/projects', [...$payload, 'members' => [['user_id' => $me->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('members.0.user_id');

        // Unknown user.
        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/projects', [...$payload, 'members' => [['user_id' => 999999]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('members.0.user_id');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_create_rejects_creator_id_sent_as_string(): void
    {
        $me = User::factory()->create();

        $response = $this->actingAs($me, 'sanctum')->postJson('/api/v1/projects', [
            'name' => 'New Platform',
            'priority' => 'medium',
            'start_date' => '2026-11-01',
            'end_date' => '2027-01-31',
            'members' => [
                // JSON ids can arrive as strings; the creator check must still fire.
                ['user_id' => (string) $me->id],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('members.0.user_id');

        // Nothing half-created.
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_members', 0);
    }

    public function test_create_rolls_back_when_adding_members_fails(): void
    {
        $me = User::factory()->create();
        $teammate = User::factory()->create();

        // Simulate a failure after the project row exists but before all
        // members are attached.
        ProjectMember::creating(function (ProjectMember $member) use ($teammate): void {
            if ($member->user_id === $teammate->id) {
                throw new \RuntimeException('simulated failure');
            }
        });

        $response = $this->actingAs($me, 'sanctum')->postJson('/api/v1/projects', [
            'name' => 'Doomed Platform',
            'priority' => 'low',
            'start_date' => '2026-11-01',
            'end_date' => '2027-01-31',
            'members' => [['user_id' => $teammate->id]],
        ]);

        $response->assertStatus(500);

        $this->assertDatabaseMissing('projects', ['name' => 'Doomed Platform']);
        $this->assertDatabaseMissing('project_members', ['user_id' => $me->id]);

        ProjectMember::flushEventListeners();
    }

    public function test_show_requires_membership(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->id)->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/projects/'.$project->id)
            ->assertForbidden();
    }

    public function test_show_returns_all_members_to_any_member(): void
    {
        $project = Project::factory()->create();
        $me = $this->memberOf($project);

        User::factory()->count(5)->create()
            ->each(fn (User $user) => ProjectMember::factory()->for($project)->for($user)->create());

        $response = $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects/'.$project->id);

        $response->assertOk()
            ->assertJsonCount(7, 'data.members') // detail shows all 7, not the first 4
            ->assertJsonStructure(['data' => [
                'id', 'name', 'description', 'type', 'priority', 'status',
                'start_date', 'end_date', 'members_count', 'members',
            ]]);

        $member = collect($response->json('data.members'))->first();
        $this->assertSame(['id', 'role', 'job_title', 'team', 'user'], array_keys($member));
        $this->assertSame(['id', 'name', 'avatar_url', 'job_title'], array_keys($member['user']));
    }

    public function test_update_is_leader_only(): void
    {
        $project = Project::factory()->create();

        $payload = [
            'name' => 'Renamed',
            'priority' => 'low',
            'status' => 'active',
            'start_date' => '2026-11-01',
            'end_date' => '2027-01-31',
        ];

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/projects/'.$project->id, $payload)
            ->assertForbidden();

        $member = $this->memberOf($project);
        $this->actingAs($member, 'sanctum')
            ->putJson('/api/v1/projects/'.$project->id, $payload)
            ->assertForbidden();

        $creator = $project->creator;
        $this->actingAs($creator, 'sanctum')
            ->putJson('/api/v1/projects/'.$project->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.priority', 'low');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Renamed']);
    }

    public function test_delete_is_leader_only_and_removes_memberships(): void
    {
        $project = Project::factory()->create();
        $member = $this->memberOf($project);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->id)
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->id)
            ->assertForbidden();

        $this->actingAs($project->creator, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id]);
    }

    public function test_leader_of_another_project_gets_forbidden(): void
    {
        $mine = Project::factory()->create();
        $theirs = Project::factory()->create();

        // Leader of $mine, complete stranger to $theirs.
        $me = $mine->creator;
        $targetUser = $theirs->creator;

        $this->actingAs($me, 'sanctum')->getJson('/api/v1/projects/'.$theirs->id)->assertForbidden();
        $this->actingAs($me, 'sanctum')
            ->putJson('/api/v1/projects/'.$theirs->id, [
                'name' => 'Hijacked', 'priority' => 'low', 'status' => 'active',
                'start_date' => '2026-11-01', 'end_date' => '2027-01-31',
            ])
            ->assertForbidden();
        $this->actingAs($me, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$theirs->id)
            ->assertForbidden();

        // Cross-project IDOR on the nested member routes too.
        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/projects/'.$theirs->id.'/members', ['user_id' => $me->id])
            ->assertForbidden();
        $this->actingAs($me, 'sanctum')
            ->putJson('/api/v1/projects/'.$theirs->id.'/members/'.$targetUser->id, ['role' => 'member'])
            ->assertForbidden();
        $this->actingAs($me, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$theirs->id.'/members/'.$targetUser->id)
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $theirs->id, 'name' => $theirs->name]);
    }
}
