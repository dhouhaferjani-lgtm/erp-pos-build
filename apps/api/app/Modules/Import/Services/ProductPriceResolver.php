<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Shared\Domain\CurrencyScale;

final class ProductPriceResolver
{
    /**
     * @param  array<string, mixed>  $row  normalized row data
     * @param  'ttc'|'ht'|'margin'  $authority
     * @return array{sale_price: ?string, warnings: list<array{code: string, detail: string}>}
     */
    public function resolve(array $row, string $authority, string $taxRate): array
    {
        /** @var list<array{code: string, detail: string}> $warnings */
        $warnings = [];
        /** @var array<string, array{field: string, ttc: numeric-string}> $candidates */
        $candidates = [];

        $ttcField = $this->present($row['sale_price_incl_tax'] ?? null)
            ? 'sale_price_incl_tax'
            : ($this->present($row['sale_price'] ?? null) ? 'sale_price' : null);

        if ($ttcField !== null) {
            $candidates['ttc'] = [
                'field' => $ttcField,
                'ttc' => $this->formatMoney((string) $row[$ttcField]),
            ];
        }

        if ($this->present($row['sale_price_excl_tax'] ?? null)) {
            $candidates['ht'] = [
                'field' => 'sale_price_excl_tax',
                'ttc' => $this->ttcFromHt((string) $row['sale_price_excl_tax'], $taxRate),
            ];
        }

        if ($this->present($row['margin'] ?? null)) {
            if (! $this->present($row['purchase_price'] ?? null)) {
                $warnings[] = [
                    'code' => 'margin_without_cost',
                    'detail' => 'margin provided without purchase_price; margin candidate skipped',
                ];
            } else {
                $candidates['margin'] = [
                    'field' => 'margin',
                    'ttc' => $this->ttcFromMargin((string) $row['purchase_price'], (string) $row['margin'], $taxRate),
                ];
            }
        }

        $chosenKey = $this->chooseCandidate($candidates, $authority);
        if ($chosenKey === null || ! array_key_exists($chosenKey, $candidates)) {
            return ['sale_price' => null, 'warnings' => $warnings];
        }

        $chosen = $candidates[$chosenKey]['ttc'];

        foreach ($candidates as $key => $candidate) {
            if ($key === $chosenKey) {
                continue;
            }

            if ($this->differsByMoreThanLastUnit($candidate['ttc'], $chosen)) {
                $warnings[] = [
                    'code' => 'price_conflict',
                    'detail' => "{$candidate['field']}: provided implies {$candidate['ttc']}, kept {$chosen}",
                ];
            }
        }

        return ['sale_price' => $chosen, 'warnings' => $warnings];
    }

    private function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * @param  array<string, array{field: string, ttc: numeric-string}>  $candidates
     */
    private function chooseCandidate(array $candidates, string $authority): ?string
    {
        if (array_key_exists($authority, $candidates)) {
            return $authority;
        }

        foreach (['ttc', 'ht', 'margin'] as $fallback) {
            if (array_key_exists($fallback, $candidates)) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * @return numeric-string
     */
    private function ttcFromHt(string $ht, string $taxRate): string
    {
        return $this->formatMoney(bcmul($this->numeric($ht), $this->taxFactor($taxRate), 4));
    }

    /**
     * @return numeric-string
     */
    private function ttcFromMargin(string $purchasePrice, string $margin, string $taxRate): string
    {
        $ht = bcmul($this->numeric($purchasePrice), $this->marginFactor($margin), 4);

        return $this->formatMoney(bcmul($ht, $this->taxFactor($taxRate), 4));
    }

    /**
     * @return numeric-string
     */
    private function taxFactor(string $taxRate): string
    {
        return bcadd('1', bcdiv($this->numeric($taxRate), '100', 4), 4);
    }

    /**
     * @return numeric-string
     */
    private function marginFactor(string $margin): string
    {
        return bcadd('1', bcdiv($this->numeric($margin), '100', 4), 4);
    }

    /**
     * @return numeric-string
     */
    private function formatMoney(string $value): string
    {
        return CurrencyScale::bcformatStrict($value, 3);
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $value): string
    {
        return CurrencyScale::bcformatStrict($value, 4);
    }

    /**
     * @param  numeric-string  $derived
     * @param  numeric-string  $chosen
     */
    private function differsByMoreThanLastUnit(string $derived, string $chosen): bool
    {
        $diff = bccomp($derived, $chosen, 4) >= 0
            ? bcsub($derived, $chosen, 4)
            : bcsub($chosen, $derived, 4);

        return bccomp($diff, '0.001', 4) === 1;
    }
}
