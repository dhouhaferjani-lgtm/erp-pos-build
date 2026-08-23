<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain\Services;

use App\Shared\Domain\Validation\CountryTaxNumberRules;

final class TaxIdValidationService
{
    public function validate(string $countryCode, string $registrationNumber): TaxIdValidationResult
    {
        $registrationNumber = trim($registrationNumber);

        if ($registrationNumber === '') {
            return TaxIdValidationResult::invalid('unknown', ['Registration number is required.']);
        }

        return match (strtoupper($countryCode)) {
            'FR' => $this->validateFrench($registrationNumber),
            'TN' => $this->validateTunisian($registrationNumber),
            'IT' => $this->validateItalian($registrationNumber),
            'GB' => $this->validateUK($registrationNumber),
            default => TaxIdValidationResult::valid('unknown'),
        };
    }

    private function validateFrench(string $registrationNumber): TaxIdValidationResult
    {
        $digits = preg_replace('/\s+/', '', $registrationNumber);

        if ($digits === null || strlen($digits) !== 14 || ! ctype_digit($digits)) {
            return TaxIdValidationResult::invalid(
                'SIRET',
                ['French SIRET must be exactly 14 digits.'],
            );
        }

        if (! $this->validateFrenchSiret($digits)) {
            return TaxIdValidationResult::invalid(
                'SIRET',
                ['French SIRET failed Luhn checksum validation.'],
            );
        }

        return TaxIdValidationResult::valid('SIRET');
    }

    private function validateFrenchSiret(string $siret): bool
    {
        $sum = 0;

        for ($i = 0; $i < 14; $i++) {
            $digit = (int) $siret[$i];

            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function validateTunisian(string $registrationNumber): TaxIdValidationResult
    {
        $cleaned = preg_replace('/[\s\/\-]/', '', $registrationNumber);

        if ($cleaned === null) {
            return TaxIdValidationResult::invalid(
                'Matricule Fiscale',
                ['Invalid Tunisian matricule fiscale format.'],
            );
        }

        if (! $this->validateTunisianMatricule($cleaned)) {
            return TaxIdValidationResult::invalid(
                'Matricule Fiscale',
                ['Tunisian matricule fiscale must match pattern: 7-8 digits + 2 or 3 letters + 3-digit establishment (e.g., 1234567AM000 or 1234567AMN000).'],
            );
        }

        return TaxIdValidationResult::valid('Matricule Fiscale');
    }

    /**
     * Delegated to the single source of truth. This advisory endpoint used to
     * carry its own third, incompatible TN pattern (`/^\d{7}[A-Z][A-Z0-9]{3}$/`)
     * which told operators a matricule was fine that the seal boundary then
     * refused — research spec 2026-08-23 §3.3.
     */
    private function validateTunisianMatricule(string $matricule): bool
    {
        return CountryTaxNumberRules::matches('TN', strtoupper($matricule));
    }

    private function validateItalian(string $registrationNumber): TaxIdValidationResult
    {
        $cleaned = preg_replace('/\s+/', '', $registrationNumber);

        if ($cleaned === null) {
            return TaxIdValidationResult::invalid(
                'unknown',
                ['Invalid Italian tax ID format.'],
            );
        }

        if ($this->validateItalianTaxId($cleaned)) {
            $format = strlen($cleaned) === 16 ? 'Codice Fiscale' : 'Partita IVA';

            return TaxIdValidationResult::valid($format);
        }

        return TaxIdValidationResult::invalid(
            'Codice Fiscale / Partita IVA',
            ['Italian tax ID must be a Codice Fiscale (16 alphanumeric characters) or Partita IVA (11 digits).'],
        );
    }

    private function validateItalianTaxId(string $taxId): bool
    {
        $upper = strtoupper($taxId);

        // Codice Fiscale: 16 alphanumeric characters
        if (strlen($upper) === 16 && (bool) preg_match('/^[A-Z0-9]{16}$/', $upper)) {
            return true;
        }

        // Partita IVA: 11 digits
        if (strlen($taxId) === 11 && ctype_digit($taxId)) {
            return true;
        }

        return false;
    }

    private function validateUK(string $registrationNumber): TaxIdValidationResult
    {
        $cleaned = preg_replace('/\s+/', '', $registrationNumber);

        if ($cleaned === null) {
            return TaxIdValidationResult::invalid(
                'CRN',
                ['Invalid UK Company Registration Number format.'],
            );
        }

        if (! $this->validateUKCompanyNumber($cleaned)) {
            return TaxIdValidationResult::invalid(
                'CRN',
                ['UK Company Registration Number must be exactly 8 digits.'],
            );
        }

        return TaxIdValidationResult::valid('CRN');
    }

    private function validateUKCompanyNumber(string $number): bool
    {
        return strlen($number) === 8 && ctype_digit($number);
    }
}
