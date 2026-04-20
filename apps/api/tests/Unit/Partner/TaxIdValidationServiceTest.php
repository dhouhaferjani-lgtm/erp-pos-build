<?php

declare(strict_types=1);

namespace Tests\Unit\Partner;

use App\Modules\Partner\Domain\Services\TaxIdValidationService;
use PHPUnit\Framework\TestCase;

class TaxIdValidationServiceTest extends TestCase
{
    private TaxIdValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TaxIdValidationService;
    }

    // ─── French SIRET ─────────────────────────────────────────────────

    public function test_validates_valid_french_siret(): void
    {
        // 73282932000074 is a well-known valid SIRET (La Poste)
        $result = $this->service->validate('FR', '73282932000074');

        $this->assertTrue($result->isValid);
        $this->assertSame('SIRET', $result->format);
        $this->assertEmpty($result->errors);
    }

    public function test_rejects_french_siret_with_wrong_length(): void
    {
        $result = $this->service->validate('FR', '1234567');

        $this->assertFalse($result->isValid);
        $this->assertSame('SIRET', $result->format);
        $this->assertNotEmpty($result->errors);
    }

    public function test_rejects_french_siret_with_letters(): void
    {
        $result = $this->service->validate('FR', '7328293200AB74');

        $this->assertFalse($result->isValid);
        $this->assertSame('SIRET', $result->format);
    }

    public function test_rejects_french_siret_failing_luhn(): void
    {
        // 14 digits but invalid Luhn
        $result = $this->service->validate('FR', '12345678901234');

        $this->assertFalse($result->isValid);
        $this->assertSame('SIRET', $result->format);
        $this->assertStringContainsString('Luhn', $result->errors[0]);
    }

    public function test_french_siret_strips_whitespace(): void
    {
        // Same valid SIRET with spaces — whitespace is stripped, yielding 14 valid digits
        $result = $this->service->validate('FR', '732 829 320 00074');

        $this->assertTrue($result->isValid);
        $this->assertSame('SIRET', $result->format);
    }

    // ─── Tunisian Matricule Fiscale ────────────────────────────────────

    public function test_validates_valid_tunisian_matricule(): void
    {
        $result = $this->service->validate('TN', '1234567A000');

        $this->assertTrue($result->isValid);
        $this->assertSame('Matricule Fiscale', $result->format);
        $this->assertEmpty($result->errors);
    }

    public function test_validates_tunisian_matricule_with_alphanumeric_suffix(): void
    {
        $result = $this->service->validate('TN', '9876543BABC');

        $this->assertTrue($result->isValid);
        $this->assertSame('Matricule Fiscale', $result->format);
    }

    public function test_rejects_invalid_tunisian_matricule(): void
    {
        $result = $this->service->validate('TN', 'INVALID');

        $this->assertFalse($result->isValid);
        $this->assertSame('Matricule Fiscale', $result->format);
        $this->assertNotEmpty($result->errors);
    }

    public function test_rejects_tunisian_matricule_with_too_few_digits(): void
    {
        $result = $this->service->validate('TN', '123456A000');

        $this->assertFalse($result->isValid);
    }

    // ─── Italian Tax IDs ──────────────────────────────────────────────

    public function test_validates_valid_italian_codice_fiscale(): void
    {
        $result = $this->service->validate('IT', 'RSSMRA85M01H501Z');

        $this->assertTrue($result->isValid);
        $this->assertSame('Codice Fiscale', $result->format);
    }

    public function test_validates_valid_italian_partita_iva(): void
    {
        $result = $this->service->validate('IT', '12345678901');

        $this->assertTrue($result->isValid);
        $this->assertSame('Partita IVA', $result->format);
    }

    public function test_rejects_invalid_italian_tax_id(): void
    {
        $result = $this->service->validate('IT', 'SHORT');

        $this->assertFalse($result->isValid);
        $this->assertNotEmpty($result->errors);
    }

    public function test_rejects_italian_partita_iva_with_wrong_length(): void
    {
        $result = $this->service->validate('IT', '1234567890');

        $this->assertFalse($result->isValid);
    }

    // ─── UK Company Registration Number ───────────────────────────────

    public function test_validates_valid_uk_company_number(): void
    {
        $result = $this->service->validate('GB', '12345678');

        $this->assertTrue($result->isValid);
        $this->assertSame('CRN', $result->format);
    }

    public function test_rejects_uk_company_number_with_wrong_length(): void
    {
        $result = $this->service->validate('GB', '1234567');

        $this->assertFalse($result->isValid);
        $this->assertSame('CRN', $result->format);
    }

    public function test_rejects_uk_company_number_with_letters(): void
    {
        $result = $this->service->validate('GB', '1234567A');

        $this->assertFalse($result->isValid);
    }

    // ─── Edge Cases ───────────────────────────────────────────────────

    public function test_unknown_country_passes_validation(): void
    {
        $result = $this->service->validate('XX', 'ANYTHING');

        $this->assertTrue($result->isValid);
        $this->assertSame('unknown', $result->format);
    }

    public function test_empty_registration_number_is_invalid(): void
    {
        $result = $this->service->validate('FR', '');

        $this->assertFalse($result->isValid);
    }

    public function test_whitespace_only_registration_number_is_invalid(): void
    {
        $result = $this->service->validate('FR', '   ');

        $this->assertFalse($result->isValid);
    }

    public function test_country_code_is_case_insensitive(): void
    {
        $result = $this->service->validate('fr', '73282932000074');

        $this->assertTrue($result->isValid);
        $this->assertSame('SIRET', $result->format);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $result = $this->service->validate('GB', '12345678');

        $array = $result->toArray();

        $this->assertArrayHasKey('is_valid', $array);
        $this->assertArrayHasKey('format', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertTrue($array['is_valid']);
        $this->assertSame('CRN', $array['format']);
        $this->assertSame([], $array['errors']);
    }
}
