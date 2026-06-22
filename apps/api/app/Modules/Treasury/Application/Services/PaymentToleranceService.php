<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Shared\Contracts\Treasury\DTOs\ToleranceCheckResult;
use App\Shared\Contracts\Treasury\Enums\ToleranceType;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;

class PaymentToleranceService implements PaymentToleranceCheckerContract
{
    /**
     * Default tolerance settings used when neither company nor country
     * settings are configured. Mirrors the legacy fall-through chain in
     * getToleranceSettings() and resolveCountrySettings() so the two
     * code paths return identical numbers for the system_default branch.
     */
    private const SYSTEM_DEFAULT_PERCENTAGE = '0.0050';

    private const SYSTEM_DEFAULT_MAX_AMOUNT = '0.50';

    public function __construct(
        private GeneralLedgerService $glService
    ) {}

    /**
     * Get effective tolerance settings for a company
     * Priority: Company override → Country default → System default
     *
     * The percentage / max_amount strings are always bcmath-formatted at
     * scale 4 (see the bcadd($value, '0', 4) calls below) — `numeric-string`
     * is the truthful type, and tightening it lets downstream callers do
     * bccomp / bcmul without static-analysis noise.
     *
     * @return array{enabled: bool, percentage: numeric-string, max_amount: numeric-string, source: string}
     */
    public function getToleranceSettings(string $companyId): array
    {
        $company = Company::with('country.paymentSettings')->findOrFail($companyId);

        /** @var CountryPaymentSettings|null $countrySettings */
        $countrySettings = $company->country?->paymentSettings;

        $percentage = $company->payment_tolerance_percentage
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ?? ($countrySettings?->payment_tolerance_percentage ?? self::SYSTEM_DEFAULT_PERCENTAGE);

        $maxAmount = $company->max_payment_tolerance_amount
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ?? ($countrySettings?->max_payment_tolerance_amount ?? self::SYSTEM_DEFAULT_MAX_AMOUNT);

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
     * Typed cross-module qualifier surface — see PaymentToleranceCheckerContract docblock
     * for full semantics. Country/currency keyed; company-level overrides are exposed
     * via getToleranceSettings() above (used by SmartPaymentController, DiscountToleranceBoundary,
     * and CloseInvoiceWithToleranceService for the company-aware UI/threshold path).
     */
    public function check(
        string $shortfall,
        string $invoiceTotal,
        string $currencyCode,
        string $countryCode,
        bool $strict = false,
    ): ToleranceCheckResult {
        $settings = $this->resolveCountrySettings($countryCode);

        if (! $settings['enabled']) {
            return new ToleranceCheckResult(
                qualifies: false,
                difference: '0.0000',
                type: ToleranceType::None,
                reason: 'Tolerance disabled',
            );
        }

        // Normalise inputs to scale 4. Negative shortfalls are not meaningful;
        // callers are expected to pass an absolute value, but defensively
        // collapse the sign so downstream comparisons are stable.
        /** @phpstan-ignore-next-line argument.type */
        $absDifference = bcadd($shortfall, '0', 4);
        if (bccomp($absDifference, '0', 4) < 0) {
            $absDifference = bcmul($absDifference, '-1', 4);
        }

        /** @phpstan-ignore-next-line argument.type */
        $percentageThreshold = bcmul($invoiceTotal, $settings['percentage'], 4);

        $withinPercentage = $strict
            ? bccomp($absDifference, $percentageThreshold, 4) < 0
            : bccomp($absDifference, $percentageThreshold, 4) <= 0;

        $withinMaxAmount = $strict
            /** @phpstan-ignore-next-line argument.type */
            ? bccomp($absDifference, $settings['max_amount'], 4) < 0
            /** @phpstan-ignore-next-line argument.type */
            : bccomp($absDifference, $settings['max_amount'], 4) <= 0;

        // Zero difference is never a qualifying writeoff — no money gap to clear.
        // A non-strict caller that passes 0.0000 still gets ToleranceType::None.
        if (bccomp($absDifference, '0', 4) === 0) {
            return new ToleranceCheckResult(
                qualifies: false,
                difference: '0.0000',
                type: ToleranceType::None,
                reason: null,
            );
        }

        // Note: shortfall is unsigned by contract — the contract callers compute
        // abs(payment - invoice) before invoking. We default the direction to
        // Underpayment (matches the dominant POS A1 / B2B A2 use cases). A future
        // overload could thread an explicit direction if overpayment write-offs
        // become a thing on this surface.
        $type = ToleranceType::Underpayment;

        if ($withinPercentage && $withinMaxAmount) {
            return new ToleranceCheckResult(
                qualifies: true,
                difference: $absDifference,
                type: $type,
                reason: null,
            );
        }

        $reason = null;
        if (! $withinPercentage) {
            $reason = "Exceeds percentage threshold ({$settings['percentage']})";
        } elseif (! $withinMaxAmount) {
            $reason = "Exceeds max amount threshold ({$settings['max_amount']})";
        }

        return new ToleranceCheckResult(
            qualifies: false,
            difference: $absDifference,
            type: $type,
            reason: $reason,
        );
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
        ?string $description = null,
        ?string $postedByUserId = null,
        ?string $currencyCode = null,
    ): void {
        $this->glService->createPaymentToleranceJournalEntry(
            companyId: $companyId,
            partnerId: $partnerId,
            documentId: $documentId,
            amount: $amount,
            type: $type,
            date: $date,
            description: $description,
            postedByUserId: $postedByUserId,
            currencyCode: $currencyCode,
        );
    }

    /**
     * Country-only settings resolution for the typed contract surface.
     * Mirrors the Country → System-default tail of getToleranceSettings(),
     * minus the Company override step (the contract has no companyId).
     *
     * @return array{enabled: bool, percentage: string, max_amount: string}
     */
    private function resolveCountrySettings(string $countryCode): array
    {
        /** @var CountryPaymentSettings|null $countrySettings */
        $countrySettings = CountryPaymentSettings::query()
            ->where('country_code', $countryCode)
            ->first();

        $percentage =
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ($countrySettings?->payment_tolerance_percentage ?? self::SYSTEM_DEFAULT_PERCENTAGE);

        $maxAmount =
            /** @phpstan-ignore-next-line nullsafe.neverNull */
            ($countrySettings?->max_payment_tolerance_amount ?? self::SYSTEM_DEFAULT_MAX_AMOUNT);

        return [
            'enabled' =>
                /** @phpstan-ignore-next-line nullsafe.neverNull */
                ($countrySettings?->payment_tolerance_enabled ?? true),
            /** @phpstan-ignore argument.type */
            'percentage' => bcadd($percentage, '0', 4),
            /** @phpstan-ignore argument.type */
            'max_amount' => bcadd($maxAmount, '0', 4),
        ];
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
