<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Domain\ExpenseMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExpenseVatSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_metadata_has_vat_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('expense_metadata', 'vat_rate'));
        $this->assertTrue(Schema::hasColumn('expense_metadata', 'vat_deductible_percent'));
    }

    public function test_vat_fields_survive_mass_assignment(): void
    {
        $document = Document::factory()->create();

        $metadata = ExpenseMetadata::create([
            'document_id' => $document->id,
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);
        $metadata->refresh();

        $this->assertSame('19.00', (string) $metadata->vat_rate);
        $this->assertSame('80.00', (string) $metadata->vat_deductible_percent);
    }
}
