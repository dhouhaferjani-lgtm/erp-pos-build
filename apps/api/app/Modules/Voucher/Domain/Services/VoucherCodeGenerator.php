<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use InvalidArgumentException;

/**
 * Generates and validates scannable voucher codes using a Damm-style check digit
 * over a 32-character alphabet.
 *
 * Code format: <TENANT_PREFIX>-<12 alphanum>-<1 check digit>
 * Total length: 19 characters (4 + 1 + 12 + 1 + 1)
 *
 * - TENANT_PREFIX: 4-character uppercase tenant code (e.g. OTSP).
 * - 12-char body: random chars from ALPHABET (32 chars, no I/O/0/1 for OCR safety).
 * - Check digit: 1 char from ALPHABET, computed via a Damm-style algorithm over
 *   a totally anti-symmetric quasigroup (TASG) of order 32.
 *
 * The quasigroup is defined by multiplication in GF(2^5) with primitive polynomial
 * x^5 + x^2 + 1 (0x25), which is a known TASG. This catches all single-character
 * substitution errors and all adjacent transpositions.
 *
 * Algorithm: start with interim = 0; for each character c (mapped to index 0–31),
 * compute interim = TABLE[interim XOR index(c)]. Append the character whose index
 * equals interim. Validation: run all chars including check digit through the same
 * process — result is 0 for valid codes.
 *
 * Example: OTSP-ABCDEFGH2345-Q
 */
final class VoucherCodeGenerator
{
    /**
     * 32-character alphabet — excludes I, O, 0, 1 to avoid OCR/handwriting confusion.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Body length (excluding tenant prefix and check digit).
     */
    private const BODY_LENGTH = 12;

    /**
     * Damm permutation table over GF(2^5) (base 32).
     *
     * Entry [n] = n * 3 mod p(x) in GF(2^5), primitive polynomial x^5+x^2+1 (0x25).
     * The step function is: interim = TABLE[interim XOR char_index].
     *
     * The resulting operation satisfies the TASG property required by Damm's algorithm:
     *   - For any a != b: d(0, a) != d(0, b)                 (no single substitution)
     *   - For any a != b: d(d(0, a), b) != d(d(0, b), a)     (no adjacent transposition)
     *
     * @var array<int, int>
     */
    private const TABLE = [
        0,  3,  6,  5, 12, 15, 10,  9, 24, 27, 30, 29, 20, 23, 18, 17,
        16, 19, 22, 21, 28, 31, 26, 25,  8, 11, 14, 13,  4,  7,  2,  1,
    ];

    /**
     * Generate a new voucher code for the given tenant prefix.
     *
     * @param  string  $tenantPrefix  Up to 4-character uppercase code (padded/truncated to 4)
     */
    public function generate(string $tenantPrefix): string
    {
        $prefix = strtoupper(substr($tenantPrefix, 0, 4));
        $body = $this->randomBody();
        $checkChar = $this->computeCheckChar($body);

        return "{$prefix}-{$body}-{$checkChar}";
    }

    /**
     * Validate a voucher code.
     *
     * Accepts lowercase input (uppercases before checking) for cashier ergonomics.
     * Returns false for: malformed input, wrong length, missing dashes, characters
     * outside ALPHABET, or a failed check digit.
     */
    public function isValid(string $code): bool
    {
        $code = strtoupper($code);

        // Pattern: 4-char prefix + dash + 12-char body + dash + 1-char check
        // Total length: 4 + 1 + 12 + 1 + 1 = 19 chars
        if (strlen($code) !== 19) {
            return false;
        }

        if ($code[4] !== '-' || $code[17] !== '-') {
            return false;
        }

        $body = substr($code, 5, 12);
        $checkChar = $code[18];

        // Verify all chars are in the alphabet
        $alphabetFlip = $this->alphabetFlip();
        foreach (str_split($body) as $char) {
            if (! isset($alphabetFlip[$char])) {
                return false;
            }
        }
        if (! isset($alphabetFlip[$checkChar])) {
            return false;
        }

        // Run Damm over body + check digit — result must be 0 for a valid code
        return $this->dammDigest($body.$checkChar) === 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a random 12-character body from ALPHABET.
     */
    private function randomBody(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $body = '';
        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $body;
    }

    /**
     * Compute the check character for a given body string.
     * Runs Damm and encodes the resulting interim value as an ALPHABET character.
     */
    private function computeCheckChar(string $body): string
    {
        $interim = $this->dammDigest($body);

        return self::ALPHABET[$interim];
    }

    /**
     * Run the Damm algorithm over a string of ALPHABET characters.
     *
     * Each character is mapped to its index in ALPHABET (0–31).
     * The step function is: interim = TABLE[interim XOR char_index].
     * Returns the final interim value (0 = valid complete code).
     */
    private function dammDigest(string $input): int
    {
        $flip = $this->alphabetFlip();
        $interim = 0;

        foreach (str_split($input) as $char) {
            if (! isset($flip[$char])) {
                throw new InvalidArgumentException(
                    "Character '{$char}' is not in the voucher code alphabet."
                );
            }
            $interim = self::TABLE[$interim ^ $flip[$char]];
        }

        return $interim;
    }

    /**
     * Build a char → index map for the alphabet.
     *
     * Returns a map of single-character string → 0-based integer index.
     *
     * @return array<string, int>
     */
    private function alphabetFlip(): array
    {
        /** @var array<string, int>|null $cached */
        static $cached = null;
        if ($cached === null) {
            $cached = $this->buildAlphabetFlip();
        }

        return $cached;
    }

    /**
     * @return array<string, int>
     */
    private function buildAlphabetFlip(): array
    {
        $map = [];
        /** @var list<string> $chars */
        $chars = str_split(self::ALPHABET);
        foreach ($chars as $i => $char) {
            $map[$char] = $i;
        }

        return $map;
    }
}
