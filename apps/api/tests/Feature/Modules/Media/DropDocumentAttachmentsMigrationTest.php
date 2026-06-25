<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Asserts that the drop migration for document_attachments was applied.
 *
 * Task 3.1: confirm table is absent after migrations run.
 */
final class DropDocumentAttachmentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_attachments_table_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::hasTable('document_attachments'),
            'document_attachments table must not exist after migrations — it was dropped in 2026_06_24_120000_drop_document_attachments_table.',
        );
    }
}
