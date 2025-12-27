<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\CountryPaymentSettings;

class PaymentToleranceService
{
    public function __construct(
        private GeneralLedgerService $glService
    ) {}

    /**
     * Get effective tolerance settings for a company
     * Priority: Company override → Country default → System default
     *
     * @return array{enabled: bool, percentage: string, max_amount: string, source: string}
     */
    public function getToleranceSettings(string $companyId): array
    {
        $company = Company::with('country.paymentSettings')->findOrFail($companyId);

        /** @var CountryPaymentSettings|null $countrySettings */
        $countrySettings = $company->country?->paymentSettings;

        $percentage = $company->payment_tolerance_percentage
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ?? ($countrySettings?->payment_tolerance_percentage ?? '0.0050');

        $maxAmount = $company->max_payment_tolerance_amount
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ?? ($countrySettings?->max_payment_tolerance_amount ?? '0.50');

        return [
            'enabled' => $company->payment_tolerance_enabled
                /** @phpstan-ignore-next-line nullsafe.neverNull */
                ?? ($countrySettings?->payment_tolerance_enabled ?? true),
            /** @phpstan-ignore argument.type */
            'percentage' => bcadd($percentage, '0', 4),
            /** @phpstan-ignore argument.type */
            'max_amount' => bcadd($maxAmount, '0', 4),
            'source' => $this->determineSettingsSource($company, $countrySettings),
        ];
    }

    /**
     * Check if a payment difference qualifies for auto-write-off
     *
     * @return array{qualifies: bool, difference: string, type: string|null, reason: string|null}
     */
    public function checkTolerance(
        string $invoiceAmount,
        string $paymentAmount,
        string $companyId
    ): array {
        $settings = $this->getToleranceSettings($companyId);

        if (! $settings['enabled']) {
            return [
                'qualifies' => false,
                'difference' => '0.0000',
                'type' => null,
                'reason' => 'Tolerance disabled',
            ];
        }

        /** @phpstan-ignore-next-line argument.type */
        $difference = bcsub($paymentAmount, $invoiceAmount, 4);
        $absDifference = bccomp($difference, '0', 4) < 0
            ? bcmul($difference, '-1', 4)
            : $difference;

        // Calculate percentage threshold
        /** @phpstan-ignore-next-line argument.type */
        $percentageThreshold = bcmul($invoiceAmount, $settings['percentage'], 4);

        // Must be within BOTH percentage AND max amount
        $withinPercentage = bccomp($absDifference, $percentageThreshold, 4) <= 0;
        /** @phpstan-ignore-next-line argument.type */
        $withinMaxAmount = bccomp($absDifference, $settings['max_amount'], 4) <= 0;

        if ($withinPercentage && $withinMaxAmount && bccomp($absDifference, '0', 4) > 0) {
            $type = bccomp($difference, '0', 4) < 0 ? 'underpayment' : 'overpayment';

            return [
                'qualifies' => true,
                'difference' => $absDifference,
                'type' => $type,
                'reason' => null,
            ];
        }

        $reason = null;
        if (! $withinPercentage) {
            $reason = "Exceeds percentage threshold ({$settings['percentage']})";
        } elseif (! $withinMaxAmount) {
            $reason = "Exceeds max amount threshold ({$settings['max_amount']})";
        }

        return [
            'qualifies' => false,
            'difference' => $absDifference,
            'type' => bccomp($difference, '0', 4) < 0 ? 'underpayment' : 'overpayment',
            'reason' => $reason,
        ];
    }

    /**
     * Apply payment tolerance write-off
     * Creates GL journal entry for the tolerance amount
     */
    public function applyTolerance(
        string $companyId,
        string $partnerId,
        string $documentId,
        string $amount,
        string $type,
        \DateTimeInterface $date,
        ?string $description = null
    ): void {
        $this->glService->createPaymentToleranceJournalEntry(
            companyId: $companyId,
            partnerId: $partnerId,
            documentId: $documentId,
            amount: $amount,
            type: $type,
            date: $date,
            description: $description
        );
    }

    private function determineSettingsSource(Company $company, ?CountryPaymentSettings $countrySettings): string
    {
        if ($company->payment_tolerance_enabled !== null) {
            return 'company';
        }
        if ($countrySettings?->payment_tolerance_enabled !== null) {
            return 'country';
        }

        return 'system_default';
    }
}
