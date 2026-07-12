<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Banking;

use App\Shared\Banking\Domain\BankAccountValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankAccountValidatorTest extends TestCase
{
    private BankAccountValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(class_exists(BankAccountValidator::class), 'BankAccountValidator must exist.');
        $this->validator = new BankAccountValidator;
    }

    public function test_validates_large_tunisian_rib_and_derives_iban_without_integer_coercion(): void
    {
        $rib = '07040005810111129653';

        $expectedKey = str_pad(
            bcsub('97', bcmod(substr($rib, 0, 18).'00', '97', 0), 0),
            2,
            '0',
            STR_PAD_LEFT,
        );
        self::assertSame($expectedKey, substr($rib, -2));

        $countryNumeric = '2923'; // T=29, N=23 according to ISO 13616 letter expansion.
        $expectedIbanCheck = str_pad(
            bcsub('98', bcmod($rib.$countryNumeric.'00', '97', 0), 0),
            2,
            '0',
            STR_PAD_LEFT,
        );

        $result = $this->validator->validateRib($rib, 'tn');

        self::assertTrue($result->valid);
        self::assertSame($rib, $result->normalized);
        self::assertSame('07', $result->bank_code);
        self::assertSame('TN'.$expectedIbanCheck.$rib, $result->iban);
        self::assertSame([], $result->errors);
    }

    public function test_validates_a_derived_rib_that_exceeds_the_64_bit_integer_range(): void
    {
        $body = '999999999999999999';
        $key = str_pad(
            bcsub('97', bcmod($body.'00', '97', 0), 0),
            2,
            '0',
            STR_PAD_LEFT,
        );
        $rib = $body.$key;

        self::assertSame(1, bccomp($rib, (string) PHP_INT_MAX, 0));
        self::assertTrue($this->validator->validateRib($rib, 'TN')->valid);
    }

    #[DataProvider('invalidRibProvider')]
    public function test_rejects_invalid_ribs_cleanly(string $rib, string $expectedError): void
    {
        $result = $this->validator->validateRib($rib, 'TN');

        self::assertFalse($result->valid);
        self::assertContains($expectedError, $result->errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRibProvider(): array
    {
        return [
            'corrupted key' => ['07040005810111129654', 'invalid_checksum'],
            'nineteen digits' => ['0704000581011112965', 'invalid_length'],
            'twenty-one digits' => ['070400058101111296530', 'invalid_length'],
            'non numeric' => ['0704000581011112965A', 'invalid_format'],
        ];
    }

    public function test_validates_normalized_tunisian_iban(): void
    {
        $result = $this->validator->validateIban('TN59 0704 0005 8101 1112 9653');

        self::assertTrue($result->valid);
        self::assertSame('TN5907040005810111129653', $result->normalized);
        self::assertSame('TN', $result->country_code);
        self::assertSame([], $result->errors);
    }

    public function test_rejects_foreign_iban_without_throwing(): void
    {
        $result = $this->validator->validateIban('FR7630004001230000123456725');

        self::assertFalse($result->valid);
        self::assertSame('FR7630004001230000123456725', $result->normalized);
        self::assertContains('unsupported_country', $result->errors);
    }

    #[DataProvider('bicProvider')]
    public function test_validates_bic_format(string $bic, bool $expected): void
    {
        self::assertSame($expected, $this->validator->validateBic($bic));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function bicProvider(): array
    {
        return [
            'eight characters' => ['CFCTTNTT', true],
            'eleven characters' => ['ABCOTNTT001', true],
            'lowercase normalized' => ['cfcttntt', true],
            'invalid country characters' => ['CFCT1NTT', false],
            'invalid length' => ['CFCTTNT', false],
        ];
    }
}
