<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $leader;

    private Task $task;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->leader = $this->project->creator;
        $this->task = Task::factory()->forProject($this->project->id)->create();

        $this->author = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($this->author)->create();
    }

    public function test_requires_authentication_and_blocks_cross_project_idor(): void
    {
        $comment = TaskComment::factory()->for($this->task)->for($this->author)->create();
        $stranger = Project::factory()->create()->creator;

        $strangerRoutes = [
            ['get', "/api/v1/tasks/{$this->task->id}/comments"],
            ['post', "/api/v1/tasks/{$this->task->id}/comments"],
            ['put', "/api/v1/comments/{$comment->id}"],
            ['delete', "/api/v1/comments/{$comment->id}"],
        ];
        foreach ($strangerRoutes as [$verb, $uri]) {
            $this->{$verb.'Json'}($uri, ['body' => 'x'])->assertUnauthorized();
        }
        foreach ($strangerRoutes as [$verb, $uri]) {
            $this->actingAs($stranger, 'sanctum')->{$verb.'Json'}($uri, ['body' => 'x'])->assertForbidden();
        }
    }

    public function test_member_can_comment_and_comments_are_oldest_first(): void
    {
        $newer = TaskComment::factory()->for($this->task)->for($this->author)
            ->create(['created_at' => now()->subHour()]);
        $older = TaskComment::factory()->for($this->task)->for($this->author)
            ->create(['created_at' => now()->subHours(2)]);

        $response = $this->actingAs($this->author, 'sanctum')
            ->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'Third comment']);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Third comment')
            ->assertJsonPath('data.user.id', $this->author->id);

        $list = $this->actingAs($this->author, 'sanctum')->getJson("/api/v1/tasks/{$this->task->id}/comments");
        $list->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$older->id, $newer->id, $response->json('data.id')], array_column($list->json('data'), 'id'));

        $this->assertSame(
            ['id', 'body', 'user', 'created_at', 'updated_at'],
            array_keys($list->json('data.0')),
        );
    }

    public function test_comments_pagination_is_validated(): void
    {
        TaskComment::factory()->for($this->task)->for($this->author)->count(2)->create();
        $uri = "/api/v1/tasks/{$this->task->id}/comments";

        $this->actingAs($this->author, 'sanctum')->getJson($uri.'?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($uri.'?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_body_validation(): void
    {
        $this->actingAs($this->author, 'sanctum')
            ->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => str_repeat('x', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_edit_is_author_only(): void
    {
        $comment = TaskComment::factory()->for($this->task)->for($this->author)->create();
        $otherMember = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($otherMember)->create();

        $this->actingAs($otherMember, 'sanctum')
            ->putJson('/api/v1/comments/'.$comment->id, ['body' => 'Hijacked'])
            ->assertForbidden();

        // Even the leader cannot edit someone else's comment.
        $this->actingAs($this->leader, 'sanctum')
            ->putJson('/api/v1/comments/'.$comment->id, ['body' => 'Leader edit'])
            ->assertForbidden();

        $this->actingAs($this->author, 'sanctum')
            ->putJson('/api/v1/comments/'.$comment->id, ['body' => 'Fixed'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Fixed');
    }

    public function test_delete_is_author_or_leader(): void
    {
        $otherMember = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($otherMember)->create();

        $comment = TaskComment::factory()->for($this->task)->for($this->author)->create();

        $this->actingAs($otherMember, 'sanctum')
            ->deleteJson('/api/v1/comments/'.$comment->id)
            ->assertForbidden();

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson('/api/v1/comments/'.$comment->id)
            ->assertOk();

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);

        $own = TaskComment::factory()->for($this->task)->for($this->author)->create();
        $this->actingAs($this->author, 'sanctum')
            ->deleteJson('/api/v1/comments/'.$own->id)
            ->assertOk();
    }
}
