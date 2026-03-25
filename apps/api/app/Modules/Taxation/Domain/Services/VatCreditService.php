<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

class VatCreditService
{
    /**
     * Calculate VAT credit and payable amounts.
     *
     * Business rules:
     * - If net VAT (output - input) is negative or zero, no amount is payable.
     *   The deficit plus any brought-forward credit becomes the new carried-forward credit.
     * - If net VAT is positive and credit brought forward fully covers it,
     *   the remainder becomes the new carried-forward credit.
     * - If net VAT is positive and credit brought forward only partially covers it,
     *   the uncovered balance is the amount payable and carried-forward credit is zero.
     *
     * @param  numeric-string  $totalOutputVat
     * @param  numeric-string  $totalInputVat
     * @param  numeric-string  $creditBroughtForward
     * @return array{net_vat: string, amount_payable: string, credit_carried_forward: string}
     */
    public function calculate(
        string $totalOutputVat,
        string $totalInputVat,
        string $creditBroughtForward
    ): array {
        $netVat = bcsub($totalOutputVat, $totalInputVat, 3);

        if (bccomp($netVat, '0', 3) <= 0) {
            $creditCarriedForward = bcadd(bcmul($netVat, '-1', 3), $creditBroughtForward, 3);

            return [
                'net_vat' => $netVat,
                'amount_payable' => '0.000',
                'credit_carried_forward' => $creditCarriedForward,
            ];
        }

        if (bccomp($creditBroughtForward, $netVat, 3) >= 0) {
            return [
                'net_vat' => $netVat,
                'amount_payable' => '0.000',
                'credit_carried_forward' => bcsub($creditBroughtForward, $netVat, 3),
            ];
        }

        return [
            'net_vat' => $netVat,
            'amount_payable' => bcsub($netVat, $creditBroughtForward, 3),
            'credit_carried_forward' => '0.000',
        ];
    }
}
