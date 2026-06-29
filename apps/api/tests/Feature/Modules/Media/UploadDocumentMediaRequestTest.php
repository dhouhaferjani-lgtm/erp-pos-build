<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Presentation\Requests\UploadDocumentMediaRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 2.1 — Document-media config + UploadDocumentMediaRequest.
 *
 * Uses the same Validator::make idiom as IngressPrecisionTest — no HTTP stack
 * needed, just bind the rules() straight from the FormRequest.
 */
final class UploadDocumentMediaRequestTest extends TestCase
{
    // ── Config assertions ────────────────────────────────────────────────────

    #[Test]
    public function config_max_file_size_is_10_mib(): void
    {
        $this->assertSame(10485760, config('media.documents.max_file_size'));
    }

    #[Test]
    public function config_allowed_mime_types_has_11_entries(): void
    {
        $mimes = config('media.documents.allowed_mime_types');
        $this->assertIsArray($mimes);
        $this->assertCount(11, $mimes);
    }

    #[Test]
    public function config_allowed_extensions_has_12_entries(): void
    {
        $exts = config('media.documents.allowed_extensions');
        $this->assertIsArray($exts);
        $this->assertCount(12, $exts);
    }

    // ── FormRequest validation — happy path ──────────────────────────────────

    #[Test]
    public function valid_pdf_file_passes_validation(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file], $rules);

        $this->assertFalse($v->fails(), 'Expected a 500 KB PDF to pass: '.$v->errors()->toJson());
    }

    #[Test]
    public function valid_jpeg_image_passes_validation(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file], $rules);

        $this->assertFalse($v->fails(), 'Expected a JPEG image to pass: '.$v->errors()->toJson());
    }

    #[Test]
    public function description_is_optional_and_accepts_500_chars(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();

        $v = Validator::make(['file' => $file, 'description' => str_repeat('a', 500)], $rules);
        $this->assertFalse($v->fails(), 'Expected 500-char description to pass.');

        $vNoDesc = Validator::make(['file' => $file], $rules);
        $this->assertFalse($vNoDesc->fails(), 'Expected missing description to pass (nullable).');
    }

    // ── FormRequest validation — rejection cases ─────────────────────────────

    #[Test]
    public function missing_file_fails_validation(): void
    {
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make([], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('file', $v->errors()->toArray());
    }

    #[Test]
    public function oversize_file_fails_validation(): void
    {
        // 11 MB — exceeds the 10 MB limit
        $file = UploadedFile::fake()->create('big.pdf', 11264, 'application/pdf');

        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('file', $v->errors()->toArray());
    }

    #[Test]
    public function disallowed_mime_type_fails_validation(): void
    {
        // Windows executable — not in allow-list
        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('file', $v->errors()->toArray());
    }

    #[Test]
    public function description_exceeding_500_chars_fails_validation(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();

        $v = Validator::make(['file' => $file, 'description' => str_repeat('a', 501)], $rules);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('description', $v->errors()->toArray());
    }

    // ── Messages contract ────────────────────────────────────────────────────

    #[Test]
    public function request_exposes_legacy_message_keys(): void
    {
        $request = new UploadDocumentMediaRequest;
        $messages = $request->messages();

        $this->assertArrayHasKey('file.required', $messages);
        $this->assertArrayHasKey('file.file', $messages);
        $this->assertArrayHasKey('file.max', $messages);
        $this->assertArrayHasKey('file.mimetypes', $messages);
    }

    // ── Stage E: role field validation ───────────────────────────────────────

    #[Test]
    public function role_source_document_passes_validation(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'SOURCE_DOCUMENT'], $rules);
        $this->assertFalse($v->fails(), 'SOURCE_DOCUMENT role must pass: '.$v->errors()->toJson());
    }

    #[Test]
    public function role_datasheet_passes_validation(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'DATASHEET'], $rules);
        $this->assertFalse($v->fails(), 'DATASHEET role must pass: '.$v->errors()->toJson());
    }

    #[Test]
    public function role_manual_passes_validation(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'MANUAL'], $rules);
        $this->assertFalse($v->fails(), 'MANUAL role must pass: '.$v->errors()->toJson());
    }

    #[Test]
    public function absent_role_passes_validation_nullable(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file], $rules);
        $this->assertFalse($v->fails(), 'Absent role must pass (nullable): '.$v->errors()->toJson());
    }

    #[Test]
    public function null_role_passes_validation(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => null], $rules);
        $this->assertFalse($v->fails(), 'Null role must pass (nullable): '.$v->errors()->toJson());
    }

    #[Test]
    public function role_primary_rejected_as_image_gallery_role(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'PRIMARY'], $rules);
        $this->assertTrue($v->fails(), 'PRIMARY must be rejected (image role not allowed on documents)');
        $this->assertArrayHasKey('role', $v->errors()->toArray());
    }

    #[Test]
    public function role_gallery_rejected_as_image_gallery_role(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'GALLERY'], $rules);
        $this->assertTrue($v->fails(), 'GALLERY must be rejected (image role not allowed on documents)');
        $this->assertArrayHasKey('role', $v->errors()->toArray());
    }

    #[Test]
    public function unknown_role_string_rejected(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $rules = (new UploadDocumentMediaRequest)->rules();
        $v = Validator::make(['file' => $file, 'role' => 'BOGUS_ROLE'], $rules);
        $this->assertTrue($v->fails(), 'Unknown role string must be rejected');
        $this->assertArrayHasKey('role', $v->errors()->toArray());
    }
}
