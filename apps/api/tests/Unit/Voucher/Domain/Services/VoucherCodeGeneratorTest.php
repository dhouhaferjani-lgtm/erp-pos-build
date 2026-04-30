<?php

declare(strict_types=1);

namespace Tests\Unit\Voucher\Domain\Services;

use App\Modules\Voucher\Domain\Services\VoucherCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VoucherCodeGenerator (Task 12).
 *
 * Validates: code format, Damm check digit correctness, tamper detection,
 * transposition detection, alphabet exclusions, and input ergonomics.
 */
final class VoucherCodeGeneratorTest extends TestCase
{
    private VoucherCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new VoucherCodeGenerator;
    }

    // -------------------------------------------------------------------------
    // Format tests
    // -------------------------------------------------------------------------

    public function test_generates_code_with_tenant_prefix_and_check_digit(): void
    {
        $code = $this->generator->generate('OTSP');

        $this->assertMatchesRegularExpression(
            '/^OTSP-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]$/',
            $code,
            "Code '{$code}' does not match expected pattern."
        );
    }

    public function test_code_has_correct_total_length(): void
    {
        $code = $this->generator->generate('OTSP');
        // 4 (prefix) + 1 (dash) + 12 (body) + 1 (dash) + 1 (check) = 19
        $this->assertSame(19, strlen($code));
    }

    public function test_generates_unique_codes_on_multiple_calls(): void
    {
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[] = $this->generator->generate('TEST');
        }

        $unique = array_unique($codes);
        $this->assertCount(100, $unique, 'Duplicate codes found in 100 generated codes.');
    }

    public function test_prefix_is_uppercased_in_output(): void
    {
        $code = $this->generator->generate('otsp');

        $this->assertStringStartsWith('OTSP-', $code);
    }

    // -------------------------------------------------------------------------
    // Check digit validation
    // -------------------------------------------------------------------------

    public function test_check_digit_validates_correctly(): void
    {
        $code = $this->generator->generate('OTSP');

        $this->assertTrue(
            $this->generator->isValid($code),
            "Freshly generated code '{$code}' failed validation."
        );
    }

    public function test_validates_multiple_generated_codes(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = $this->generator->generate('TEST');
            $this->assertTrue(
                $this->generator->isValid($code),
                "Generated code '{$code}' failed validation."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Tamper detection
    // -------------------------------------------------------------------------

    public function test_tampered_code_fails_validation(): void
    {
        $code = $this->generator->generate('OTSP');
        $tampered = $this->tamperOneChar($code);

        $this->assertFalse(
            $this->generator->isValid($tampered),
            "Tampered code '{$tampered}' (from '{$code}') should fail validation."
        );
    }

    public function test_adjacent_transposition_fails_validation(): void
    {
        // Generate until we get a code where positions 5 and 6 differ
        // (so a swap actually changes the string)
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->generator->generate('OTSP');
            $transposed = $this->transposeTwoAdjacentCharsInBody($code);

            if ($transposed === $code) {
                // Body chars at those positions were identical — retry
                continue;
            }

            $this->assertFalse(
                $this->generator->isValid($transposed),
                "Transposed code '{$transposed}' (from '{$code}') should fail validation."
            );

            return;
        }

        $this->markTestSkipped('Could not find a code with differing adjacent body chars after 20 attempts (extremely unlikely).');
    }

    // -------------------------------------------------------------------------
    // Alphabet exclusions
    // -------------------------------------------------------------------------

    public function test_excludes_confusable_alphabet_chars(): void
    {
        $confusable = ['I', 'O', '0', '1'];

        for ($i = 0; $i < 100; $i++) {
            $code = $this->generator->generate('TEST');
            // Check body + check digit only (positions 5–17 and 18)
            $bodyAndCheck = substr($code, 5, 13);

            foreach ($confusable as $char) {
                $this->assertStringNotContainsString(
                    $char,
                    $bodyAndCheck,
                    "Confusable character '{$char}' found in code '{$code}'."
                );
            }
        }
    }

    public function test_alphabet_constant_does_not_contain_confusable_chars(): void
    {
        $this->assertStringNotContainsString('I', VoucherCodeGenerator::ALPHABET);
        $this->assertStringNotContainsString('O', VoucherCodeGenerator::ALPHABET);
        $this->assertStringNotContainsString('0', VoucherCodeGenerator::ALPHABET);
        $this->assertStringNotContainsString('1', VoucherCodeGenerator::ALPHABET);
    }

    public function test_alphabet_has_exactly_32_chars(): void
    {
        $this->assertSame(32, strlen(VoucherCodeGenerator::ALPHABET));
    }

    // -------------------------------------------------------------------------
    // Cashier ergonomics
    // -------------------------------------------------------------------------

    public function test_validates_known_good_code_with_lowercase_input(): void
    {
        $code = $this->generator->generate('OTSP');
        $lowercase = strtolower($code);

        $this->assertTrue(
            $this->generator->isValid($lowercase),
            "Valid code '{$code}' failed validation after lowercasing to '{$lowercase}'."
        );
    }

    // -------------------------------------------------------------------------
    // Malformed input rejection
    // -------------------------------------------------------------------------

    public function test_rejects_empty_string(): void
    {
        $this->assertFalse($this->generator->isValid(''));
    }

    public function test_rejects_wrong_length_code(): void
    {
        $this->assertFalse($this->generator->isValid('OTSP-SHORT-A'));
        $this->assertFalse($this->generator->isValid('OTSP-TOOLONGBODYXYZ1234567890-A'));
    }

    public function test_rejects_missing_prefix_dash(): void
    {
        // Remove the first dash: OTSP-ABCDEFGH2345-Q -> OTSPABCDEFGH2345-Q
        $code = $this->generator->generate('OTSP');
        $noDash = substr($code, 0, 4).substr($code, 5);

        $this->assertFalse($this->generator->isValid($noDash));
    }

    public function test_rejects_missing_check_dash(): void
    {
        // Remove the second dash: OTSP-ABCDEFGH2345-Q -> OTSP-ABCDEFGH2345Q
        $code = $this->generator->generate('OTSP');
        $noDash = substr($code, 0, 17).substr($code, 18);

        $this->assertFalse($this->generator->isValid($noDash));
    }

    public function test_rejects_code_with_confusable_chars_in_body(): void
    {
        // Manually craft a malformed code with 'O' in body
        $this->assertFalse($this->generator->isValid('OTSP-ABCDEFGOABCD-A'));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Mutate one character in the body section (positions 5–16) of the code.
     */
    private function tamperOneChar(string $code): string
    {
        // Pick position 5 (first char of body) and change it to a different alphabet char
        $original = $code[5];
        $alphabet = VoucherCodeGenerator::ALPHABET;

        // Find a different character in the alphabet
        foreach (str_split($alphabet) as $char) {
            if ($char !== $original) {
                return substr($code, 0, 5).$char.substr($code, 6);
            }
        }

        return $code; // Should never reach here
    }

    /**
     * Swap two adjacent characters in the body section (positions 5 and 6).
     */
    private function transposeTwoAdjacentCharsInBody(string $code): string
    {
        // Swap positions 5 and 6 in the full code string
        $chars = str_split($code);
        [$chars[5], $chars[6]] = [$chars[6], $chars[5]];

        return implode('', $chars);
    }
}
