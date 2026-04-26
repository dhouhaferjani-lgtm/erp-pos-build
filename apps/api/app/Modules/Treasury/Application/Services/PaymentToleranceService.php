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
     * @return array{enabled: bool, percentage: string, max_amount: string, source: string}
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
     * @deprecated Use PaymentToleranceCheckerContract::check() instead.
     *             Will be removed once all callers migrate (PaymentAllocationService
     *             is the last in-tree consumer at the time of writing).
     *
     * Check if a payment difference qualifies for auto-write-off.
     *
     * Boundary semantics:
     * - $strict = false (default, A1 / SmartPayment): inclusive `<=` against both
     *   percentage and absolute thresholds. A difference exactly at the limit qualifies.
     * - $strict = true (A2 close-with-tolerance, per spec §15): exclusive `<`.
     *   A difference exactly at the limit rejects, closing a sub-tolerance abuse vector
     *   (auditor would otherwise see "balance == threshold" written off without scrutiny).
     *
     * Single source of truth for tolerance qualification across A1 and A2.
     *
     * @return array{qualifies: bool, difference: string, type: string|null, reason: string|null}
     */
    public function checkTolerance(
        string $invoiceAmount,
        string $paymentAmount,
        string $companyId,
        bool $strict = false,
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

        // Must be within BOTH percentage AND max amount.
        // Strict mode flips inclusive `<=` to exclusive `<` on both gates.
        $withinPercentage = $strict
            ? bccomp($absDifference, $percentageThreshold, 4) < 0
            : bccomp($absDifference, $percentageThreshold, 4) <= 0;
        $withinMaxAmount = $strict
            /** @phpstan-ignore-next-line argument.type */
            ? bccomp($absDifference, $settings['max_amount'], 4) < 0
            /** @phpstan-ignore-next-line argument.type */
            : bccomp($absDifference, $settings['max_amount'], 4) <= 0;

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
     * Typed cross-module qualifier surface — see PaymentToleranceCheckerContract docblock
     * for full semantics. Country/currency keyed; the deprecated checkTolerance() above
     * remains the only path that honors company-level overrides.
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
