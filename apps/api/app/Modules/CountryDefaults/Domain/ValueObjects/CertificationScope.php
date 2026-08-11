<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\ValueObjects;

use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use InvalidArgumentException;

final readonly class CertificationScope
{
    /** @var non-empty-list<string> */
    private array $codes;

    /**
     * @param  list<string>  $countryCodes
     */
    public function __construct(
        array $countryCodes,
        private CountryAccountingCapabilities $capabilities,
    ) {
        if ($countryCodes === []) {
            throw new InvalidArgumentException('Certification scope must not be empty.');
        }

        $normalized = array_values(array_unique(array_map(
            fn (string $countryCode): string => $this->normalizeScopeCode($countryCode),
            $countryCodes,
        )));

        if (in_array('*', $normalized, true)) {
            if ($normalized !== ['*']) {
                throw new InvalidArgumentException('Wildcard certification scope cannot be mixed with exact countries.');
            }

            $this->codes = ['*'];

            return;
        }

        $timbreStates = array_values(array_unique(array_map(
            fn (string $countryCode): bool => $this->capabilities->supportsStampDuty($countryCode),
            $normalized,
        )));
        if (count($timbreStates) !== 1) {
            throw new InvalidArgumentException('Exact certification scope cannot mix timbre and non-timbre countries.');
        }

        sort($normalized);
        /** @var non-empty-list<string> $normalized */
        $this->codes = $normalized;
    }

    /** @return non-empty-list<string> */
    public function countryCodes(): array
    {
        return $this->codes;
    }

    public function isWildcard(): bool
    {
        return $this->codes === ['*'];
    }

    public function includesTimbreCountry(): bool
    {
        return ! $this->isWildcard() && $this->capabilities->supportsStampDuty($this->codes[0]);
    }

    public function allowsAssignment(string $countryCode): bool
    {
        $normalized = $this->normalizeScopeCode($countryCode);

        if ($this->isWildcard()) {
            return $normalized === '*';
        }

        return $normalized !== '*' && in_array($normalized, $this->codes, true);
    }

    private function normalizeScopeCode(string $countryCode): string
    {
        $normalized = strtoupper(trim($countryCode));
        if ($normalized === '*') {
            return $normalized;
        }

        if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Invalid ISO-like country code '{$countryCode}'.");
        }

        return $normalized;
    }
}
