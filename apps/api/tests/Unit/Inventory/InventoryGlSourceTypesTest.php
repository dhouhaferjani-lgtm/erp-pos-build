<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Inventory\Domain\InventoryGlSourceTypes;
use PHPUnit\Framework\TestCase;

final class InventoryGlSourceTypesTest extends TestCase
{
    public function test_every_inventory_source_type_has_an_explicit_misc_journal_mapping(): void
    {
        foreach (InventoryGlSourceTypes::ALL as $sourceType) {
            $this->assertSame(JournalCode::Misc, JournalCode::fromSourceType($sourceType), $sourceType);
        }

        $this->assertSame(JournalCode::Cash, JournalCode::fromSourceType('pos_receipt'));
    }
}
