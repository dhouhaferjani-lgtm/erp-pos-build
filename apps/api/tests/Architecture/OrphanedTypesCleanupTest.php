<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrphanedTypesCleanupTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function orphanedTypeProvider(): array
    {
        return [
            'invoice consolidation service' => ['app/Modules/Partner/Application/Services/InvoiceConsolidationService.php'],
            'expense data DTO' => ['app/Modules/Expense/Application/DTOs/ExpenseData.php'],
            'login data DTO' => ['app/Modules/Identity/Application/DTOs/LoginData.php'],
            'part need data DTO' => ['app/Modules/Workshop/WorkOrder/Application/DTOs/PartNeedData.php'],
        ];
    }

    #[DataProvider('orphanedTypeProvider')]
    public function test_confirmed_orphaned_types_are_removed(string $path): void
    {
        $this->assertFileDoesNotExist(base_path($path));
    }
}
