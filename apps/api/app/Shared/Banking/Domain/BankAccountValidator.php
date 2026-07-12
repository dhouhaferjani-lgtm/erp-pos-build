<?php

declare(strict_types=1);

namespace App\Shared\Banking\Domain;

use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Banking\Domain\ValueObjects\IbanValidationResult;
use App\Shared\Banking\Domain\ValueObjects\RibValidationResult;

final class BankAccountValidator implements BankAccountValidatorInterface
{
    /**
     * @var array<string, array{rib_length: int, iban_length: int, bank_code_length: int}>
     */
    private const COUNTRY_CONFIG = [
        'TN' => [
            'rib_length' => 20,
            'iban_length' => 24,
            'bank_code_length' => 2,
        ],
    ];

    public function validateRib(string $rib, string $country): RibValidationResult
    {
        $normalized = $this->normalizeRib($rib);
        $countryCode = strtoupper(trim($country));
        $config = self::COUNTRY_CONFIG[$countryCode] ?? null;

        if ($config === null) {
            return $this->invalidRib($normalized, 'unsupported_country');
        }

        if (! ctype_digit($normalized)) {
            return $this->invalidRib($normalized, 'invalid_format');
        }

        if (strlen($normalized) !== $config['rib_length']) {
            return $this->invalidRib($normalized, 'invalid_length');
        }

        $body = substr($normalized, 0, -2);
        $actualKey = substr($normalized, -2);
        $expectedKey = str_pad(
            bcsub('97', bcmod($body.'00', '97', 0), 0),
            2,
            '0',
            STR_PAD_LEFT,
        );

        if (! hash_equals($expectedKey, $actualKey)) {
            return $this->invalidRib($normalized, 'invalid_checksum');
        }

        return new RibValidationResult(
            valid: true,
            normalized: $normalized,
            iban: $this->deriveIban($normalized, $countryCode),
            bic: null,
            bank_code: substr($normalized, 0, $config['bank_code_length']),
            bank_name: null,
            errors: [],
        );
    }

    public function toIban(string $rib, string $country): string
    {
        $result = $this->validateRib($rib, $country);

        return $result->valid ? ($result->iban ?? '') : '';
    }

    public function validateIban(string $iban): IbanValidationResult
    {
        $normalized = $this->normalizeIban($iban);
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $normalized) !== 1) {
            return $this->invalidIban($normalized, null, 'invalid_format');
        }

        $countryCode = substr($normalized, 0, 2);
        $config = self::COUNTRY_CONFIG[$countryCode] ?? null;
        if ($config === null) {
            return $this->invalidIban($normalized, $countryCode, 'unsupported_country');
        }

        if (strlen($normalized) !== $config['iban_length']) {
            return $this->invalidIban($normalized, $countryCode, 'invalid_length');
        }

        $rearranged = substr($normalized, 4).substr($normalized, 0, 4);
        $numeric = $this->expandIbanLetters($rearranged);
        if (bcmod($numeric, '97', 0) !== '1') {
            return $this->invalidIban($normalized, $countryCode, 'invalid_checksum');
        }

        return new IbanValidationResult(
            valid: true,
            normalized: $normalized,
            country_code: $countryCode,
            bank_code: substr($normalized, 4, $config['bank_code_length']),
            errors: [],
        );
    }

    public function validateBic(string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', strtoupper(trim($bic))) === 1;
    }

    private function normalizeRib(string $rib): string
    {
        return str_replace([' ', '-', "\t", "\n", "\r"], '', trim($rib));
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(str_replace([' ', "\t", "\n", "\r"], '', trim($iban)));
    }

    private function deriveIban(string $rib, string $countryCode): string
    {
        $countryNumeric = $this->expandIbanLetters($countryCode);
        $checkDigits = str_pad(
            bcsub('98', bcmod($rib.$countryNumeric.'00', '97', 0), 0),
            2,
            '0',
            STR_PAD_LEFT,
        );

        return $countryCode.$checkDigits.$rib;
    }

    private function expandIbanLetters(string $value): string
    {
        $expanded = '';
        foreach (str_split($value) as $character) {
            if (ctype_alpha($character)) {
                $expanded .= (string) (ord($character) - ord('A') + 10);
            } else {
                $expanded .= $character;
            }
        }

        return $expanded;
    }

    private function invalidRib(string $normalized, string $error): RibValidationResult
    {
        return new RibValidationResult(
            valid: false,
            normalized: $normalized,
            iban: null,
            bic: null,
            bank_code: strlen($normalized) >= 2 ? substr($normalized, 0, 2) : null,
            bank_name: null,
            errors: [$error],
        );
    }

    private function invalidIban(
        string $normalized,
        ?string $countryCode,
        string $error,
    ): IbanValidationResult {
        return new IbanValidationResult(
            valid: false,
            normalized: $normalized,
            country_code: $countryCode,
            bank_code: null,
            errors: [$error],
        );
    }
}
