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

    public function test_include_training_without_explicit_codes_defaults_to_sale_and_training(): void
    {
        foreach (['SALE', 'TRAINING', 'REFUND', 'VOID'] as $code) {
            $this->createReceipt($code, $code === 'TRAINING');
        }

        $response = $this->getJson('/api/v1/pos/receipts?include_training=true');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['SALE', 'TRAINING'],
            array_column($response->json('data.data'), 'invoice_type_code'),
        );
    }

    /** @param list<string> $codes */
    #[DataProvider('trainingCodeSets')]
    public function test_every_training_code_set_requires_the_training_switch(array $codes): void
    {
        foreach ([null, false] as $includeTraining) {
            $query = http_build_query(array_filter([
                'invoice_type_codes' => $codes,
                'include_training' => $includeTraining === false ? 'false' : null,
            ], static fn (mixed $value): bool => $value !== null));

            $this->assertApiValidationErrors(
                $this->getJson('/api/v1/pos/receipts?'.$query),
                ['invoice_type_codes'],
            );
        }
    }

    /** @param list<string> $codes */
    #[DataProvider('trainingCodeSets')]
    public function test_every_training_code_set_is_honoured_when_the_switch_is_true(array $codes): void
    {
        foreach (['SALE', 'TRAINING', 'REFUND', 'VOID'] as $code) {
            $this->createReceipt($code, $code === 'TRAINING');
        }

        $query = http_build_query([
            'invoice_type_codes' => $codes,
            'include_training' => 'true',
        ]);
        $response = $this->getJson('/api/v1/pos/receipts?'.$query);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            $codes,
            array_column($response->json('data.data'), 'invoice_type_code'),
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function trainingCodeSets(): iterable
    {
        yield 'training' => [['TRAINING']];
        yield 'sale and training' => [['SALE', 'TRAINING']];
        yield 'refund and training' => [['REFUND', 'TRAINING']];
        yield 'void and training' => [['VOID', 'TRAINING']];
        yield 'sale refund and training' => [['SALE', 'REFUND', 'TRAINING']];
        yield 'sale void and training' => [['SALE', 'VOID', 'TRAINING']];
        yield 'refund void and training' => [['REFUND', 'VOID', 'TRAINING']];
        yield 'all codes' => [['SALE', 'REFUND', 'VOID', 'TRAINING']];
    }

    /**
     * @param  list<string>  $codes
     */
    #[DataProvider('legalNonTrainingCodeSets')]
    public function test_include_training_is_a_byte_identical_no_op_for_non_training_code_sets(array $codes): void
    {
        foreach (['SALE', 'REFUND', 'VOID', 'TRAINING'] as $code) {
            $this->createReceipt($code, $code === 'TRAINING');
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
        yield 'refund' => [['REFUND']];
        yield 'void' => [['VOID']];
        yield 'refund and void' => [['REFUND', 'VOID']];
        yield 'sale and refund' => [['SALE', 'REFUND']];
        yield 'sale and void' => [['SALE', 'VOID']];
        yield 'all production codes' => [['SALE', 'REFUND', 'VOID']];
    }

    public function test_blank_type_entry_is_rejected(): void
    {
        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]='),
            ['invoice_type_codes.0'],
        );
    }

    public function test_empty_type_array_is_rejected(): void
    {
        $this->assertApiValidationErrors(
            $this->json('GET', '/api/v1/pos/receipts', ['invoice_type_codes' => []]),
            ['invoice_type_codes'],
        );
    }

    public function test_out_of_domain_type_code_is_rejected(): void
    {
        $this->assertApiValidationErrors(
            $this->getJson('/api/v1/pos/receipts?invoice_type_codes[]=CREDIT'),
            ['invoice_type_codes.0'],
        );
    }
}
