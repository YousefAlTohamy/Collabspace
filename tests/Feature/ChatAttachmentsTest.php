<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->channel = Conversation::query()->where('project_id', $project->id)->where('type', 'project')->sole();
        $this->actingAs($project->creator, 'sanctum');
    }

    public function test_message_requires_body_or_attachment_and_validates_uploads(): void
    {
        $uri = "/api/v1/conversations/{$this->channel->id}/messages";
        $this->postJson($uri, [])->assertUnprocessable()->assertJsonValidationErrors(['body', 'attachment']);
        $this->postJson($uri, ['body' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($uri, ['body' => 'Text only', 'user_id' => 999, 'conversation_id' => 999])
            ->assertCreated()->assertJsonPath('data.body', 'Text only')
            ->assertJsonPath('data.user.id', $this->channel->project->created_by)
            ->assertJsonPath('data.conversation_id', $this->channel->id)
            ->assertJsonPath('data.attachment', null);

        foreach ([
            'file.php' => 'application/x-php',
            'file.svg' => 'image/svg+xml',
            'file.html' => 'text/html',
            'file.exe' => 'application/x-msdownload',
            'file.pdf' => 'image/png',
        ] as $name => $mime) {
            $this->postJson($uri, ['attachment' => UploadedFile::fake()->create($name, 1, $mime)])
                ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        }
        $this->postJson($uri, ['attachment' => UploadedFile::fake()->create('large.pdf', 10241)])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_valid_attachments_are_private_and_download_as_attachments(): void
    {
        $uri = "/api/v1/conversations/{$this->channel->id}/messages";
        $this->postJson($uri, ['body' => 'Image', 'attachment' => UploadedFile::fake()->image('photo.png', 1, 1)])
            ->assertCreated()->assertJsonPath('data.attachment.mime_type', 'image/png');
        $pdf = "%PDF-1.7\nSample\n%%EOF\n";
        $upload = $this->postJson($uri, ['attachment' => UploadedFile::fake()->createWithContent('report.pdf', $pdf)])
            ->assertCreated()->assertJsonPath('data.attachment.name', 'report.pdf')->assertJsonPath('data.body', null);
        $message = Message::query()->findOrFail($upload->json('data.id'));
        $this->assertStringStartsWith("chat/{$this->channel->id}/", $message->attachment_path);
        $this->assertNotSame('report.pdf', basename($message->attachment_path));
        $this->assertStringNotContainsString($message->attachment_path, $upload->getContent());
        $this->getJson($uri)->assertOk()->assertJsonMissingPath('data.0.attachment_path');
        Storage::disk('local')->assertExists($message->attachment_path);
        $this->assertFileDoesNotExist(public_path('storage/'.$message->attachment_path));

        $download = "/api/v1/messages/{$message->id}/attachment";
        $this->get($download)->assertDownload('report.pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertStreamedContent($pdf);
        Storage::disk('local')->delete($message->attachment_path);
        $this->getJson($download)->assertNotFound();
    }

    public function test_deleting_the_project_removes_its_chat_attachment_directory(): void
    {
        $channelId = $this->channel->id;
        $projectId = $this->channel->project_id;
        $this->postJson("/api/v1/conversations/{$channelId}/messages", [
            'attachment' => UploadedFile::fake()->create('report.pdf'),
        ])->assertCreated();

        $disk = Storage::disk('local');
        $directory = $disk->path('chat/'.$channelId);
        $this->assertDirectoryExists($directory);

        $this->deleteJson("/api/v1/projects/{$projectId}")->assertOk();

        $this->assertDatabaseMissing('conversations', ['id' => $channelId]);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_project_and_chat_uploads_store_only_the_display_basename(): void
    {
        $name = '../../evil.pdf';
        $projectId = $this->channel->project_id;
        $channelId = $this->channel->id;

        $file = $this->postJson("/api/v1/projects/{$projectId}/files", [
            'file' => UploadedFile::fake()->create($name),
        ])->assertCreated()->assertJsonPath('data.name', 'evil.pdf');
        $message = $this->postJson("/api/v1/conversations/{$channelId}/messages", [
            'attachment' => UploadedFile::fake()->create($name),
        ])->assertCreated()->assertJsonPath('data.attachment.name', 'evil.pdf');

        $this->assertDatabaseHas('files', ['id' => $file->json('data.id'), 'original_name' => 'evil.pdf']);
        $this->assertDatabaseHas('messages', ['id' => $message->json('data.id'), 'attachment_name' => 'evil.pdf']);
    }

    public function test_attachment_limiter_preserves_the_text_message_rate(): void
    {
        $uri = "/api/v1/conversations/{$this->channel->id}/messages";
        for ($i = 0; $i < 20; $i++) {
            $this->postJson($uri, ['attachment' => UploadedFile::fake()->create('report.pdf')])->assertCreated();
        }
        $this->postJson($uri, ['attachment' => UploadedFile::fake()->create('report.pdf')])->assertTooManyRequests();
        $this->postJson($uri, ['body' => 'Text is still allowed'])->assertCreated();
    }
}
