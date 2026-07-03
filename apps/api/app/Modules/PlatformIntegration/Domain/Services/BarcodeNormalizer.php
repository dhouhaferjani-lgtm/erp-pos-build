<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\Services;

final class BarcodeNormalizer
{
    /**
     * Normalize barcode: trim, strip non-alphanumeric, UPC-12 to EAN-13, validate EAN-13 check digit.
     */
    public function normalize(string $barcode): ?string
    {
        $barcode = trim($barcode);

        // Strip non-alphanumeric characters
        $barcode = (string) preg_replace('/[^a-zA-Z0-9]/', '', $barcode);

        if ($barcode === '') {
            return null;
        }

        // UPC-12 to EAN-13 conversion (12 digits -> prepend 0)
        if (preg_match('/^\d{12}$/', $barcode) === 1) {
            $barcode = '0'.$barcode;
        }

        // EAN-13 check digit validation
        if (preg_match('/^\d{13}$/', $barcode) === 1) {
            if (! $this->isValidEan13($barcode)) {
                return null;
            }
        }

        return $barcode;
    }

    /**
     * Validate EAN-13 check digit.
     * Algorithm: sum digits with alternating weights 1 and 3, check digit = (10 - sum%10) % 10
     */
    private function isValidEan13(string $ean): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean[$i];
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $expectedCheckDigit = (10 - ($sum % 10)) % 10;

        return $expectedCheckDigit === (int) $ean[12];
    }
}
