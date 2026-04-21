<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Document\Domain\Document;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard around `Document::$fillable` — fiscal-sensitive columns
 * must never be mass-assignable, and the audit-motivated `work_order_id`
 * addition (🔴-4a) must remain present.
 */
final class DocumentFillableRegressionTest extends TestCase
{
    public function test_work_order_id_is_mass_assignable(): void
    {
        $fillable = (new Document)->getFillable();

        $this->assertContains(
            'work_order_id',
            $fillable,
            'Document::$fillable must include work_order_id so DocumentGenerationAdapter'
            .' can link invoices back to the originating WorkOrder (audit 🔴-4a).',
        );
    }

    public function test_vehicle_id_is_not_mass_assignable(): void
    {
        // The documents table does not have a `vehicle_id` column anymore
        // (moved to document_vehicle_contexts). Guarding against a stray
        // re-introduction that would silently do nothing at the DB level.
        $fillable = (new Document)->getFillable();

        $this->assertNotContains(
            'vehicle_id',
            $fillable,
            'Vehicles link to Documents via document_vehicle_contexts, not a column on documents.'
            .' Adding vehicle_id to fillable would be misleading because the column does not exist.',
        );
    }
}
