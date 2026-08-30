<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ImportRowCodedErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_outcome_bucket_and_coded_error_columns_have_typed_vocabularies(): void
    {
        $this->assertTrue(Schema::hasColumn('import_rows', 'outcome'));
        $this->assertTrue(Schema::hasColumn('import_rows', 'duplicate_bucket'));
        $this->assertTrue(Schema::hasColumn('import_rows', 'import_error_code'));
        $this->assertTrue(Schema::hasColumn('import_rows', 'import_error_detail'));
        $this->assertTrue(Schema::hasIndex('import_rows', ['import_job_id', 'outcome']));
        $this->assertTrue(Schema::hasIndex('import_rows', ['import_error_code']));

        $this->assertTrue(ImportRowOutcome::Imported->isTerminal());
        $this->assertFalse(ImportRowOutcome::Pending->isTerminal());
        $this->assertSame('in_file', DuplicateBucket::InFile->value);
        $this->assertFalse(ImportErrorCode::ValidationFailed->isJobLevel());
        $this->assertFalse(ImportErrorCode::UnitUnknown->isJobLevel());
    }
}
