<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ProjectFileContentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->project = Project::factory()->create();
        $this->actingAs($this->project->creator, 'sanctum');
    }

    public function test_accepts_minimal_word_and_excel_documents(): void
    {
        $word = $this->zipBytes([
            '[Content_Types].xml' => <<<'XML'
                <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
                </Types>
                XML,
            '_rels/.rels' => $this->officeRootRelationship('word/document.xml'),
            'word/document.xml' => <<<'XML'
                <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                    <w:body><w:p><w:r><w:t>Collabspace</w:t></w:r></w:p></w:body>
                </w:document>
                XML,
        ]);
        $excel = $this->zipBytes([
            '[Content_Types].xml' => <<<'XML'
                <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                </Types>
                XML,
            '_rels/.rels' => $this->officeRootRelationship('xl/workbook.xml'),
            'xl/workbook.xml' => <<<'XML'
                <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                    <sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
                </workbook>
                XML,
            'xl/_rels/workbook.xml.rels' => <<<'XML'
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
                </Relationships>
                XML,
            'xl/worksheets/sheet1.xml' => <<<'XML'
                <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>
                XML,
        ]);

        $this->uploadBytes('report.docx', $word)->assertCreated();
        $this->uploadBytes('sheet.xlsx', $excel)->assertCreated();
    }

    public function test_uses_real_png_content_to_check_the_extension(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', true);
        $this->assertIsString($png);

        $this->uploadBytes('photo.png', $png)
            ->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $this->uploadBytes('photo.pdf', $png)
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_rejects_php_content_with_a_png_extension(): void
    {
        $this->uploadBytes('shell.png', '<?php echo 1;')
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_accepts_real_json_zip_and_plain_text_files(): void
    {
        $files = [
            'data.json' => '{"a":1}',
            'archive.zip' => $this->zipBytes(['readme.txt' => "Collabspace\n"]),
            'notes.txt' => "Collabspace notes\n",
            'data.csv' => "name,role\nYousef,leader\n",
            'readme.md' => "# Collabspace\n",
        ];

        foreach ($files as $name => $bytes) {
            $this->uploadBytes($name, $bytes)
                ->assertCreated()->assertJsonPath('data.name', $name);
        }

        $this->uploadBytes('archive.txt', $files['archive.zip'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    private function officeRootRelationship(string $target): string
    {
        return <<<XML
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="{$target}"/>
            </Relationships>
            XML;
    }

    /** @param array<string, string> $entries */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'collabspace-zip-');
        $this->assertIsString($path);

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            foreach ($entries as $name => $contents) {
                $this->assertTrue($zip->addFromString($name, $contents));
            }
            $this->assertTrue($zip->close());
            $bytes = file_get_contents($path);
            $this->assertIsString($bytes);

            return $bytes;
        } finally {
            unlink($path);
        }
    }

    private function uploadBytes(string $name, string $bytes): TestResponse
    {
        $file = tmpfile();
        $this->assertIsResource($file);

        try {
            $this->assertSame(strlen($bytes), fwrite($file, $bytes));

            return $this->post("/api/v1/projects/{$this->project->id}/files", [
                'file' => new UploadedFile(stream_get_meta_data($file)['uri'], $name, test: true),
            ], ['Accept' => 'application/json']);
        } finally {
            fclose($file);
        }
    }
}
