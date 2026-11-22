<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteDirectory;
use Tests\TestCase;

class ProjectFilesTest extends TestCase
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

    private function member(): User
    {
        $user = User::factory()->create();
        ProjectMember::factory()->for($this->project)->for($user)->create();

        return $user;
    }

    public function test_requires_authentication_and_blocks_cross_project_idor(): void
    {
        config(['app.debug' => false]);
        Storage::fake('local');
        $file = ProjectFile::factory()->for($this->project, 'project')->for($this->leader, 'uploader')->create();
        $stranger = Project::factory()->create()->creator;

        $this->getJson("/api/v1/projects/{$this->project->id}/files")->assertUnauthorized();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/files")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => UploadedFile::fake()->create('a.pdf')])
            ->assertForbidden();
        // Global lists are scoped, so a non-member sees an empty page, not 403.
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/files')->assertOk()->assertJsonCount(0, 'data');
        foreach ([$this->project->id, 999999999] as $projectId) {
            $this->getJson('/api/v1/files?project_id='.$projectId)
                ->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
        }
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/files/'.$file->id.'/download')->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson('/api/v1/files/'.$file->id)->assertForbidden();
    }

    public function test_member_can_upload_and_file_is_stored_privately(): void
    {
        Storage::fake('local');
        $member = $this->member();
        $task = Task::factory()->forProject($this->project->id)->create();

        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('Report.pdf', 100),
                'task_id' => $task->id,
                'uploaded_by' => $this->leader->id,
                'project_id' => Project::factory()->create()->id,
                'path' => 'ignored.pdf',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Report.pdf')
            ->assertJsonPath('data.task_id', $task->id)
            ->assertJsonPath('data.uploaded_by.id', $member->id)
            ->assertJsonPath('data.download_url', route('files.download', ['file' => $response->json('data.id')]));

        // Stored on the private disk under projects/{id}/ with a random name.
        $this->assertDatabaseHas('files', [
            'project_id' => $this->project->id,
            'original_name' => 'Report.pdf',
            'uploaded_by' => $member->id,
            'task_id' => $task->id,
        ]);

        $stored = ProjectFile::query()->findOrFail($response->json('data.id'));
        $this->assertStringStartsWith("projects/{$this->project->id}/", $stored->path);
        $this->assertStringNotContainsStringIgnoringCase('Report.pdf', $stored->path);
        Storage::disk('local')->assertExists($stored->path);
        $this->assertFileDoesNotExist(public_path('storage/'.$stored->path));
        $this->get('/storage/'.$stored->path)->assertForbidden();

        // The response never exposes the storage path.
        $this->assertArrayNotHasKey('path', $response->json('data'));
    }

    public function test_upload_whitelist_and_size_limit(): void
    {
        Storage::fake('local');

        $accepted = ['document.pdf', 'photo.png'];
        foreach ($accepted as $name) {
            $this->actingAs($this->leader, 'sanctum')
                ->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => UploadedFile::fake()->create($name, 10)])
                ->assertCreated();
        }

        $rejected = [
            'evil.php' => 'application/x-php',
            'page.html' => 'text/html',
            'icon.svg' => 'image/svg+xml',
            'setup.exe' => 'application/x-msdownload',
            'script.js' => 'application/javascript',
            'photo.html' => 'image/png',
            'mismatched.pdf' => 'image/png',
            'photo.png' => 'text/html',
        ];
        foreach ($rejected as $name => $mime) {
            $this->actingAs($this->leader, 'sanctum')
                ->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => UploadedFile::fake()->create($name, 10, $mime)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        }

        // Over 10 MB.
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", ['file' => UploadedFile::fake()->create('big.pdf', 10241)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('files', count($accepted));
    }

    public function test_task_id_must_belong_to_the_same_project(): void
    {
        Storage::fake('local');
        $foreignTask = Task::factory()->create(); // another project

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('a.pdf'),
                'task_id' => $foreignTask->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task_id');

        $this->assertDatabaseCount('files', 0);
    }

    public function test_lists_are_scoped_and_searchable(): void
    {
        $member = $this->member();
        ProjectFile::factory()->for($this->project, 'project')->for($member, 'uploader')->create(['original_name' => 'Alpha spec.pdf']);
        ProjectFile::factory()->for($this->project, 'project')->for($member, 'uploader')->create(['original_name' => 'Beta logo.png']);
        ProjectFile::factory()->count(2)->create(); // other projects: never visible

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/files")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/files?search=spec")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha spec.pdf');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/files')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Wildcard escaping: '%' matches literally.
        ProjectFile::factory()->for($this->project, 'project')->for($member, 'uploader')->create(['original_name' => '100% Report.pdf']);
        ProjectFile::factory()->for($this->project, 'project')->for($member, 'uploader')->create(['original_name' => '100X Report.pdf']);
        $this->actingAs($member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/files?search=100%25")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_download_is_authorized_for_any_member(): void
    {
        Storage::fake('local');
        $member = $this->member();

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->createWithContent('Report.pdf', "%PDF-1.7\nSample document\n%%EOF\n"),
            ])
            ->assertCreated();

        $file = ProjectFile::query()->first();

        // Any member can download, not just the uploader.
        $this->actingAs($member, 'sanctum')
            ->get('/api/v1/files/'.$file->id.'/download')
            ->assertOk()
            ->assertStreamedContent("%PDF-1.7\nSample document\n%%EOF\n");
    }

    public function test_download_headers_and_missing_file(): void
    {
        Storage::fake('local');
        $member = $this->member();

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('Report.pdf', 50),
            ])
            ->assertCreated();

        $file = ProjectFile::query()->first();

        $response = $this->actingAs($member, 'sanctum')->get('/api/v1/files/'.$file->id.'/download');
        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Report.pdf', $disposition);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // 404 when the physical file is gone but the row remains.
        Storage::disk('local')->delete($file->path);
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/files/'.$file->id.'/download')
            ->assertNotFound();
    }

    public function test_delete_removes_row_and_physical_file(): void
    {
        Storage::fake('local');
        $uploader = $this->member();
        $otherMember = $this->member();

        $this->actingAs($uploader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('Doc.pdf', 20),
            ])
            ->assertCreated();

        $file = ProjectFile::query()->first();
        Storage::disk('local')->assertExists($file->path);

        // Another member cannot delete.
        $this->actingAs($otherMember, 'sanctum')
            ->deleteJson('/api/v1/files/'.$file->id)
            ->assertForbidden();

        // The uploader can.
        $this->actingAs($uploader, 'sanctum')
            ->deleteJson('/api/v1/files/'.$file->id)
            ->assertOk();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($file->path);

        // The leader can delete another member's file.
        $this->actingAs($uploader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('Doc2.pdf', 20),
            ])
            ->assertCreated();
        $file2 = ProjectFile::query()->first();
        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson('/api/v1/files/'.$file2->id)
            ->assertOk();
        $this->assertDatabaseMissing('files', ['id' => $file2->id]);
        Storage::disk('local')->assertMissing($file2->path);
    }

    public function test_project_delete_removes_the_storage_directory(): void
    {
        Storage::fake('local');

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('a.pdf', 10),
            ])
            ->assertCreated();
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/files", [
                'file' => UploadedFile::fake()->create('b.pdf', 10),
            ])
            ->assertCreated();

        Storage::disk('local')->assertExists("projects/{$this->project->id}");

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$this->project->id)
            ->assertOk();

        Storage::disk('local')->assertMissing("projects/{$this->project->id}");
    }

    public function test_project_delete_succeeds_when_private_storage_cleanup_fails(): void
    {
        $projectId = $this->project->id;
        $channelId = Conversation::query()->where('project_id', $projectId)->sole()->id;
        $disk = Storage::fake('local');
        Storage::shouldReceive('disk')->with('local')->andReturn(\Mockery::mock($disk, function ($mock) use ($projectId, $channelId): void {
            $mock->shouldReceive('deleteDirectory')->once()->with("projects/{$projectId}")
                ->andThrow(UnableToDeleteDirectory::atLocation('private project storage'));
            $mock->shouldReceive('deleteDirectory')->once()->with("chat/{$channelId}")->andReturnFalse();
        }));
        Log::shouldReceive('warning')->twice()
            ->with('Project storage cleanup failed.', ['project_id' => $projectId]);

        $this->actingAs($this->leader, 'sanctum')
            ->deleteJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertExactJson(['message' => 'Project deleted.']);

        $this->assertDatabaseMissing('projects', ['id' => $projectId]);
        $this->assertDatabaseMissing('conversations', ['id' => $channelId]);
    }
}
