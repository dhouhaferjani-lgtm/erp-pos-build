<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Partner\Domain\Services\TaxIdValidationService;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * P0 convergence guard — the three incompatible Tunisian matricule-fiscale
 * regexes (research spec 2026-08-23 §3.3).
 *
 * Before convergence the codebase enforced THREE mutually incompatible TN
 * patterns:
 *   - partner requests: `/^[0-9]{7}[A-Z]{3}[0-9]{3}$/`   (exactly 3 letters)
 *   - TaxIdValidationService: `/^\d{7}[A-Z][A-Z0-9]{3}$/` (1 letter + 3 alnum)
 *   - CountryTaxNumberRules / FiscalPayloadConstraintValidator:
 *     `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D`                  (exactly 2 letters)
 *
 * The first and the third cannot both accept the same string, so a partner
 * whose MF was entered in the canonical 13-character form passed partner
 * validation and was then REJECTED at seal time by
 * `assertTaxNumberForCountry(..., buyer: true)`.
 *
 * This test pins the converged contract: one canonical pattern in
 * `CountryTaxNumberRules`, a strict superset of the pattern that has already
 * accepted values into sealed bytes, consumed by every entry point and
 * mirrored byte-for-byte on the device.
 */
class TunisianMatriculeConvergenceTest extends TestCase
{
    /**
     * The TN pattern as it stood BEFORE this convergence, in both the shared
     * rules and `FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS`.
     *
     * Values matching it are already present in SEALED fiscal payload bytes,
     * so the widened pattern must keep accepting every one of them or a
     * historical payload would become retroactively invalid on verify-chain
     * replay.
     */
    private const TN_SEALED_LEGACY_PATTERN = '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D';

    /**
     * SUPERSET PROOF (sealed-bytes compatibility).
     *
     * The legacy TN language is exactly
     *   D{7,8} · L{2} · D{3}
     * with D = [0-9] and L = [A-Z]. The widened pattern is
     *   D{7,8} · L{2,3} · D{3}
     * which differs in one quantifier only: `{2}` -> `{2,3}`. `{2,3}` accepts
     * every string `{2}` accepts, and the surrounding factors are byte-
     * identical, so the widened language is a strict superset by construction.
     *
     * The construction argument is verified below by enumerating the language
     * exhaustively over its ONLY varying alphabet dimension (all 676 ordered
     * [A-Z] pairs) crossed with both digit-count arms and boundary digit
     * groups — 4,056 concrete witnesses.
     */
    public function test_widened_tn_pattern_is_a_strict_superset_of_the_sealed_legacy_pattern(): void
    {
        $widened = CountryTaxNumberRules::PATTERNS['TN'];

        $this->assertNotSame(
            self::TN_SEALED_LEGACY_PATTERN,
            $widened,
            'The TN pattern was expected to be widened away from the legacy 2-letter-only form.'
        );

        $leadDigitGroups = ['0000000', '1234567', '9999999', '00000000', '12345678', '99999999'];
        $tailDigitGroups = ['000', '001', '999'];

        $witnesses = 0;

        foreach ($leadDigitGroups as $lead) {
            foreach (range('A', 'Z') as $first) {
                foreach (range('A', 'Z') as $second) {
                    $candidate = $lead.$first.$second.$tailDigitGroups[$witnesses % 3];

                    // Sanity: the witness really is in the legacy language.
                    $this->assertSame(
                        1,
                        preg_match(self::TN_SEALED_LEGACY_PATTERN, $candidate),
                        "Witness {$candidate} is not in the legacy language; the proof enumeration is wrong."
                    );

                    // Superset: the widened pattern must accept it too.
                    $this->assertSame(
                        1,
                        preg_match($widened, $candidate),
                        "SEALED-BYTES REGRESSION: legacy-accepted {$candidate} is rejected by the widened TN pattern."
                    );

                    $witnesses++;
                }
            }
        }

        $this->assertSame(4056, $witnesses);
    }

    /**
     * The superset must also hold at the seal boundary itself, not merely at
     * the shared-rules constant.
     */
    public function test_fiscal_validator_still_accepts_legacy_twelve_char_matricules(): void
    {
        foreach (['1234567AM000', '12345678AM000', '0000000ZZ999'] as $legacy) {
            $this->assertSame(1, preg_match(self::TN_SEALED_LEGACY_PATTERN, $legacy));
            $this->assertFiscalAccepts($legacy, 'seller.tax_number', buyer: false);
            $this->assertFiscalAccepts($legacy, 'buyer.tax_number', buyer: true);
        }
    }

    public function test_canonical_thirteen_char_matricule_is_accepted_by_the_shared_rule(): void
    {
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567AMN000'));
        $this->assertTrue(CountryTaxNumberRules::matches('TN', '1234567AM000'));
    }

    /**
     * Normalization contract: operator input is canonicalized to the COMPACT
     * uppercase form that is what seeded/demo data actually stores
     * (`DemoPharmacySeeder` stores `1234567AM000`, not `1234567/A/M/000`).
     */
    public function test_normalize_for_storage_produces_the_compact_stored_form(): void
    {
        $this->assertSame('1234567AM000', CountryTaxNumberRules::normalizeForStorage('TN', '1234567/A/M/000'));
        $this->assertSame('1234567AMN000', CountryTaxNumberRules::normalizeForStorage('TN', '1234567/A/M/N/000'));
        $this->assertSame('1234567AM000', CountryTaxNumberRules::normalizeForStorage('TN', ' 1234567 am 000 '));
        $this->assertSame('1234567AM000', CountryTaxNumberRules::normalizeForStorage('tn', '1234567am000'));

        // Non-TN countries are returned untouched — no cross-country drift.
        $this->assertSame(' fr 732829320 ', CountryTaxNumberRules::normalizeForStorage('FR', ' fr 732829320 '));
    }

    /**
     * THE LOAD-BEARING ROUND TRIP.
     *
     * Property: any matricule accepted at the partner entry boundary (after
     * that boundary's own normalization) MUST also pass the sealed-payload
     * buyer AND seller tax-number checks. This is the invariant whose
     * violation made the ACCOUNT_CHARGE B2B lane unsealable.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedMatriculeProvider(): array
    {
        return [
            'legacy compact 12-char' => ['1234567AM000', '1234567AM000'],
            'canonical compact 13-char' => ['1234567AMN000', '1234567AMN000'],
            'long slashed 12-char form' => ['1234567/A/M/000', '1234567AM000'],
            'long slashed 13-char form' => ['1234567/A/M/N/000', '1234567AMN000'],
            'spaced lowercase input' => ['1234567 am 000', '1234567AM000'],
            'eight-digit legacy arm' => ['12345678AM000', '12345678AM000'],
            'eight-digit canonical arm' => ['12345678AMN000', '12345678AMN000'],
        ];
    }

    #[DataProvider('acceptedMatriculeProvider')]
    public function test_every_partner_accepted_matricule_seals_as_buyer_and_seller(string $raw, string $expectedStored): void
    {
        $normalized = CountryTaxNumberRules::normalizeForStorage('TN', $raw);

        $this->assertSame($expectedStored, $normalized, 'Stored-value convention drift.');

        // The predicate BOTH partner requests delegate their TN arm to.
        // The request wiring itself (prepareForValidation, the country
        // fallback, the grandfather clause, the validation closure) is new and
        // risky, so it is covered end-to-end over real HTTP in
        // Tests\Feature\Partner\PartnerTunisianMatriculeTest — NOT reflected
        // past here (gate R1 F-7).
        $this->assertTrue(
            CountryTaxNumberRules::matches('TN', $normalized),
            "Shared rule rejected {$normalized}"
        );

        $this->assertFiscalAccepts($normalized, 'buyer.tax_number', buyer: true);
        $this->assertFiscalAccepts($normalized, 'seller.tax_number', buyer: false);

        // The advisory endpoint must agree with the blocking ones.
        $this->assertTrue(
            (new TaxIdValidationService)->validate('TN', $raw)->isValid,
            "TaxIdValidationService rejected {$raw}"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedMatriculeProvider(): array
    {
        return [
            'six digits' => ['123456AM000'],
            'one letter only' => ['1234567A000'],
            'four letters' => ['1234567ABCD000'],
            'two-digit establishment' => ['1234567AM00'],
            'nine leading digits' => ['123456789AM000'],
            'digits in the letter block' => ['1234567A1000'],
        ];
    }

    #[DataProvider('rejectedMatriculeProvider')]
    public function test_non_canonical_matricules_are_rejected_on_every_arm(string $raw): void
    {
        $normalized = CountryTaxNumberRules::normalizeForStorage('TN', $raw);

        $this->assertFalse(CountryTaxNumberRules::matches('TN', $normalized));
        $this->assertFalse((new TaxIdValidationService)->validate('TN', $raw)->isValid);
    }

    /**
     * Lowercase never reaches the seal boundary as-is: the entry boundary
     * uppercases it. Un-normalized lowercase must still be rejected by the
     * matching predicate so the two sides cannot drift apart.
     */
    public function test_unnormalized_lowercase_is_rejected_by_the_matcher(): void
    {
        $this->assertFalse(CountryTaxNumberRules::matches('TN', '1234567am000'));
    }

    /**
     * Single source of truth: the fiscal validator must not carry its own TN
     * literal any more.
     */
    public function test_fiscal_validator_table_is_the_shared_rules_table(): void
    {
        $ref = new ReflectionClass(FiscalPayloadConstraintValidator::class);
        $fiscal = $ref->getConstant('TAX_NUMBER_PATTERNS');

        $this->assertSame(CountryTaxNumberRules::PATTERNS, $fiscal);
    }

    /**
     * Both-sides mirror discipline: the device fiscal engine seals the same
     * bytes, so its TN literal must match the server's byte-for-byte (modulo
     * the PHP-only `D` modifier, which JS `$` implies without the `m` flag).
     */
    public function test_pos_device_tn_pattern_mirrors_the_server_pattern(): void
    {
        $enginePath = dirname(__DIR__, 3).'/../pos/src/lib/fiscal/FiscalEventEngine.ts';

        $this->assertFileExists($enginePath, 'POS fiscal engine not found; the mirror guard cannot run.');

        $source = file_get_contents($enginePath);
        $this->assertIsString($source);

        $matched = preg_match('/^\s*TN:\s*(\S+),\s*$/m', $source, $m);
        $this->assertSame(1, $matched, 'TN entry not found in the device TAX_NUMBER_PATTERNS table.');

        $expected = substr(CountryTaxNumberRules::PATTERNS['TN'], 0, -1); // drop the PHP `D` modifier

        $this->assertSame(
            $expected,
            $m[1],
            'Device TN pattern has drifted from the server canonical rule.'
        );
    }

    private function assertFiscalAccepts(string $value, string $path, bool $buyer): void
    {
        $validator = (new ReflectionClass(FiscalPayloadConstraintValidator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(FiscalPayloadConstraintValidator::class, 'assertTaxNumberForCountry');
        $method->setAccessible(true);

        try {
            $method->invoke($validator, $value, 'TN', $path, $buyer);
        } catch (RuntimeException $e) {
            $this->fail(sprintf(
                'Sealed-payload check rejected a partner-accepted matricule (%s at %s): %s',
                $value,
                $path,
                $e->getMessage()
            ));
        }

        $this->addToAssertionCount(1);
    }
}
