<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Services;

final class SensitiveResponseMasker
{
    private const REDACTED = '[REDACTED]';

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function mask(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->mask($value);

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $key));
            if ($this->isPermittedLastFour($normalized)) {
                continue;
            }
            if ($this->mustFullyRedact($normalized)) {
                $payload[$key] = self::REDACTED;

                continue;
            }
            if ($this->mustMaskToLastFour($normalized)) {
                $payload[$key] = $this->lastFour($value);
            }
        }

        return $payload;
    }

    private function isPermittedLastFour(string $key): bool
    {
        return in_array($key, ['last4', 'lastfour', 'panlast4', 'ibanlast4'], true);
    }

    private function mustFullyRedact(string $key): bool
    {
        foreach (['password', 'token', 'secret', 'apikey', 'cvv', 'cvc', 'securitycode'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mustMaskToLastFour(string $key): bool
    {
        foreach (['pan', 'cardnumber', 'iban', 'nationalid', 'bankaccount', 'bankdetails'] as $needle) {
            if ($key === $needle || str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function lastFour(mixed $value): string
    {
        if (! is_scalar($value)) {
            return self::REDACTED;
        }

        $compact = (string) preg_replace('/[^a-zA-Z0-9]+/', '', (string) $value);
        if (strlen($compact) < 4) {
            return self::REDACTED;
        }

        return '••••'.substr($compact, -4);
    }
}
