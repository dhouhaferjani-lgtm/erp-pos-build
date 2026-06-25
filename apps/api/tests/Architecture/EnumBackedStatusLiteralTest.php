<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class EnumBackedStatusLiteralTest extends TestCase
{
    /**
     * @return array<string, array{file: string, literal: string}>
     */
    public static function enumBackedStatusLiteralProvider(): array
    {
        return [
            'aged_receivables_posted_documents' => [
                'file' => 'app/Modules/Document/Application/Services/AgedReceivablesService.php',
                'literal' => "->where('status', 'posted')",
            ],
            'payment_allocation_posted_documents' => [
                'file' => 'app/Modules/Treasury/Application/Services/PaymentAllocationService.php',
                'literal' => "->where('status', 'posted')",
            ],
            'payment_allocation_confirmed_documents' => [
                'file' => 'app/Modules/Treasury/Application/Services/PaymentAllocationService.php',
                'literal' => "->where('status', 'confirmed')",
            ],
            'opening_balance_valid_rows' => [
                'file' => 'app/Modules/Accounting/Domain/OpeningBalanceBatch.php',
                'literal' => "->where('status', 'VALID')",
            ],
            'opening_balance_invalid_rows' => [
                'file' => 'app/Modules/Accounting/Domain/OpeningBalanceBatch.php',
                'literal' => "->where('status', 'INVALID')",
            ],
            'fiscal_period_open_status' => [
                'file' => 'app/Modules/Accounting/Application/Services/FiscalPeriodResolverService.php',
                'literal' => "->where('status', 'open')",
            ],
        ];
    }

    /**
     * @dataProvider enumBackedStatusLiteralProvider
     */
    public function test_enum_backed_status_queries_do_not_use_string_literals(string $file, string $literal): void
    {
        $source = file_get_contents(base_path($file));

        $this->assertIsString($source);
        $this->assertStringNotContainsString($literal, $source);
    }
}
