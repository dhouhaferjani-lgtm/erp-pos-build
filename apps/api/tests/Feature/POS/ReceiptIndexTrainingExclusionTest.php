<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\POS\Support\ReceiptReportingTestCase;

final class ReceiptIndexTrainingExclusionTest extends ReceiptReportingTestCase
{
    public function test_training_is_excluded_by_default_and_requires_both_switch_and_code(): void
    {
        $this->createReceipt('SALE');
        $this->createReceipt('TRAINING', true);

        $this->getJson('/api/v1/pos/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.invoice_type_code', 'SALE');

        $this->getJson('/api/v1/pos/receipts?include_training=true&invoice_type_codes[]=SALE&invoice_type_codes[]=TRAINING')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=TRAINING'),
            ['invoice_type_codes'],
        );
    }

    /**
     * @param  list<string>  $codes
     */
    #[DataProvider('legalNonTrainingCodeSets')]
    public function test_include_training_is_a_byte_identical_no_op_for_non_training_code_sets(array $codes): void
    {
        foreach (['SALE', 'REFUND', 'VOID'] as $code) {
            $this->createReceipt($code);
        }

        $query = implode('&', array_map(
            static fn (string $code): string => 'invoice_type_codes[]='.$code,
            $codes,
        ));

        $withoutToggle = $this->getJson('/api/v1/pos/receipts?'.$query);
        $withToggle = $this->getJson('/api/v1/pos/receipts?'.$query.'&include_training=true');

        $withoutToggle->assertOk();
        $withToggle->assertOk();
        $this->assertSame($withoutToggle->getContent(), $withToggle->getContent());
        $this->assertEqualsCanonicalizing($codes, array_column($withoutToggle->json('data.data'), 'invoice_type_code'));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function legalNonTrainingCodeSets(): iterable
    {
        yield 'sale' => [['SALE']];
        yield 'refund and void' => [['REFUND', 'VOID']];
        yield 'sale and refund' => [['SALE', 'REFUND']];
        yield 'sale and void' => [['SALE', 'VOID']];
        yield 'all production codes' => [['SALE', 'REFUND', 'VOID']];
    }

    public function test_empty_type_array_is_rejected(): void
    {
        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]='),
            ['invoice_type_codes.0'],
        );
    }
}
