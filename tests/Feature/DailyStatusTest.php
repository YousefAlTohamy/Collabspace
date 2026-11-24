<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_status_upserts_the_callers_utc_entry_and_keeps_prior_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 01:30:00+02:00'));
        $project = Project::factory()->create();
        $member = $this->memberOf($project);
        $uri = '/api/v1/projects/'.$project->id.'/daily-statuses';
        $this->actingAs($member, 'sanctum');

        $first = $this->putJson($uri.'/today', [
            'progress' => 'Reviewed the task board',
            'next_steps' => 'Update the project notes',
            'blockers' => 'Waiting for feedback',
            'user_id' => $project->created_by,
            'project_id' => $project->id + 1,
            'date' => '2026-11-23',
        ])->assertOk()->assertJsonPath('data.date', '2026-11-24')
            ->assertJsonPath('data.project_id', $project->id)->assertJsonPath('data.author.id', $member->id)
            ->assertJsonPath('data.blockers', 'Waiting for feedback')->assertJsonMissingPath('data.author.email');

        $update = ['progress' => 'Feedback received', 'next_steps' => 'Finish the review'];
        $this->putJson($uri.'/today', $update)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.progress', 'Feedback received')->assertJsonPath('data.blockers', null);
        $this->assertDatabaseCount('daily_statuses', 1);

        $this->travelTo(CarbonImmutable::parse('2026-11-25 00:01:00', 'UTC'));
        $next = $this->putJson($uri.'/today', $update)->assertOk()->assertJsonPath('data.date', '2026-11-25');
        $this->assertDatabaseCount('daily_statuses', 2);
        $this->getJson($uri)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $next->json('data.id'));
        $this->getJson($uri.'?date=2026-11-24')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->json('data.id'));
    }

    public function test_daily_status_access_follows_current_membership_and_leadership(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));
        $project = Project::factory()->create();
        $leader = $project->creator;
        $first = $this->memberOf($project);
        $second = $this->memberOf($project);
        $uri = '/api/v1/projects/'.$project->id.'/daily-statuses';
        $update = ['progress' => 'Updated the dashboard', 'next_steps' => 'Review the metrics'];

        $this->getJson($uri)->assertUnauthorized();
        $this->putJson($uri.'/today', $update)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson($uri)->assertForbidden();
        $this->putJson($uri.'/today', $update)->assertForbidden();

        $this->actingAs($first, 'sanctum')->putJson($uri.'/today', $update)->assertOk();
        $this->actingAs($second, 'sanctum')->putJson($uri.'/today', ['blockers' => 'Needs existing files'] + $update)->assertOk();
        $this->actingAs($first, 'sanctum')->getJson($uri)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author.id', $first->id)->assertJsonPath('data.0.blockers', null);

        $page = $this->actingAs($leader, 'sanctum')->getJson($uri.'?date=2026-11-24&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.author.id', $second->id)->assertJsonPath('meta.total', 2);
        $this->getJson($page->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.author.id', $first->id);
        $this->deleteJson('/api/v1/projects/'.$project->id.'/members/'.$first->id)->assertOk();
        $this->actingAs($first, 'sanctum')->getJson($uri)->assertForbidden();
        $this->putJson($uri.'/today', $update)->assertForbidden();
    }

    public function test_daily_status_validates_text_date_and_page_size(): void
    {
        $project = Project::factory()->create();
        $uri = '/api/v1/projects/'.$project->id.'/daily-statuses';
        $this->actingAs($project->creator, 'sanctum')->putJson($uri.'/today', ['blockers' => str_repeat('x', 2001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['progress', 'next_steps', 'blockers']);
        $this->getJson($uri.'?date=2026-02-30&per_page=51')->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'per_page']);
        $this->assertDatabaseCount('daily_statuses', 0);
    }

    private function memberOf(Project $project): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($project)->for($user)->create();

        return $user;
    }
}
