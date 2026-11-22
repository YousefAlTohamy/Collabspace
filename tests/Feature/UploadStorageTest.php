<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\UploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class UploadStorageTest extends TestCase
{
    public function test_it_removes_the_private_file_when_saving_throws(): void
    {
        $disk = Storage::fake('local');
        $disk->put('projects/1/previous.txt', 'keep');
        $failure = new RuntimeException('Database save failed.');

        try {
            (new UploadStorage)->store(
                UploadedFile::fake()->createWithContent('report.txt', 'Report'),
                'projects/1',
                function (array $stored) use ($disk, $failure): void {
                    $disk->assertExists($stored['path']);
                    throw $failure;
                },
            );
            $this->fail('The original save exception should propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame(['projects/1/previous.txt'], $disk->allFiles('projects/1'));
    }
}
