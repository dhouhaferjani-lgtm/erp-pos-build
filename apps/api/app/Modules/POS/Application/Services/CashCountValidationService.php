<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Application\DTOs\CashCountValidationResultDTO;
use App\Modules\POS\Application\DTOs\FraudSettingsDTO;
use App\Modules\POS\Application\DTOs\ValidationError;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\Treasury\Infrastructure\Repositories\PaymentMethodRepository;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;

/**
 * Validates a cash-count submission and computes tiered variance severity.
 *
 * Design decision (Task 16): The fourth argument `array $expectedPerMethod` maps
 * payment_method_id → expected_amount string. This is explicit Option A — the caller
 * (Task 18 orchestrator) builds the expected totals from receipt payments before calling
 * this service, keeping the POS frontend free from computing expected amounts itself and
 * keeping this service pure and easily testable with arbitrary fixtures.
 */
final class CashCountValidationService
{
    public function __construct(
        private readonly PaymentMethodRepository $paymentMethodRepository,
    ) {}

    /**
     * Validate a cash-count submission and compute variance severity.
     *
     * @param  array<CashCountInputDTO>  $inputs
     * @param  array<string, string>  $expectedPerMethod  payment_method_id → scale-4 decimal string
     */
    public function validate(
        array $inputs,
        FraudSettingsDTO $settings,
        string $currencyCode,
        array $expectedPerMethod,
    ): CashCountValidationResultDTO {
        /** @var array<ValidationError> $errors */
        $errors = [];

        // ── Duplicate check ──────────────────────────────────────────────────
        /** @var array<string, int> $seenIds */
        $seenIds = [];
        foreach ($inputs as $index => $input) {
            if (isset($seenIds[$input->paymentMethodId])) {
                $errors[] = new ValidationError(
                    code: 'duplicate_payment_method',
                    field: "cash_counts.{$index}.payment_method_id",
                    message: "Payment method '{$input->paymentMethodId}' appears more than once.",
                );
            } else {
                $seenIds[$input->paymentMethodId] = $index;
            }
        }

        // ── Per-row validation ────────────────────────────────────────────────
        foreach ($inputs as $index => $input) {
            // Currency code mismatch
            if ($input->currencyCode !== $currencyCode) {
                $errors[] = new ValidationError(
                    code: 'currency_mismatch',
                    field: "cash_counts.{$index}.currency_code",
                    message: "Currency '{$input->currencyCode}' does not match shift currency '{$currencyCode}'.",
                );
            }

            // Actual amount format: must match /^\d+(\.\d{1,4})?$/
            if (preg_match('/^\d+(\.\d{1,4})?$/', $input->actualAmount) !== 1) {
                $errors[] = new ValidationError(
                    code: 'amount_format',
                    field: "cash_counts.{$index}.actual_amount",
                    message: "Amount '{$input->actualAmount}' is not a valid decimal with up to 4 decimal places.",
                );
            }

            // Payment method existence and physical check (only if not already a duplicate — still check
            // but allow the repo lookup; the duplicate check above already marks an error)
            $method = $this->paymentMethodRepository->findById($input->paymentMethodId);

            if ($method === null) {
                $errors[] = new ValidationError(
                    code: 'method_not_found',
                    field: "cash_counts.{$index}.payment_method_id",
                    message: "Payment method '{$input->paymentMethodId}' was not found.",
                );
            } elseif (! $method->is_physical) {
                $errors[] = new ValidationError(
                    code: 'method_not_physical',
                    field: "cash_counts.{$index}.payment_method_id",
                    message: "Payment method '{$input->paymentMethodId}' is not a physical (cash-like) method.",
                );
            }
        }

        // If there are validation errors, return early with a no-op result.
        // The severity/flags are meaningless when inputs are invalid.
        if ($errors !== []) {
            return new CashCountValidationResultDTO(
                aggregateVariance: new VarianceAmount(amount: '0.0000', currencyCode: $currencyCode),
                severity: VarianceSeverity::Info,
                needsReason: false,
                needsManagerPin: false,
                perTender: [],
                errors: $errors,
            );
        }

        // ── Build per-tender breakdowns ───────────────────────────────────────
        /** @var array<CashCountBreakdownDTO> $breakdowns */
        $breakdowns = [];
        /** @var numeric-string $aggregate */
        $aggregate = '0.0000';

        foreach ($inputs as $input) {
            /** @var numeric-string $expected */
            $expected = $expectedPerMethod[$input->paymentMethodId] ?? '0.0000';
            /** @var numeric-string $actualAmount */
            $actualAmount = $input->actualAmount;
            $variance = bcsub($actualAmount, $expected, 4);
            $direction = VarianceDirection::fromSignedAmount($variance);

            $breakdowns[] = new CashCountBreakdownDTO(
                paymentMethodId: $input->paymentMethodId,
                currencyCode: $input->currencyCode,
                expectedAmount: $expected,
                actualAmount: $actualAmount,
                varianceAmount: $variance,
                varianceDirection: $direction,
                transactionCount: 0,
            );

            $aggregate = bcadd($aggregate, $variance, 4);
        }

        // ── Compute aggregate variance ────────────────────────────────────────
        $aggregateVariance = new VarianceAmount(amount: $aggregate, currencyCode: $currencyCode);

        // ── Compute severity tier ─────────────────────────────────────────────
        /** @var numeric-string $absVariance */
        $absVariance = ltrim($aggregate, '-');
        $direction = VarianceDirection::fromSignedAmount($aggregate);

        /**
         * @var array{numeric-string, numeric-string} $thresholds
         */
        $thresholds = match ($direction) {
            VarianceDirection::Over => [$settings->cashVarianceOverSoft, $settings->cashVarianceOverHard],
            VarianceDirection::Under => [$settings->cashVarianceUnderSoft, $settings->cashVarianceUnderHard],
            VarianceDirection::Balanced => [$settings->cashVarianceOverSoft, $settings->cashVarianceOverHard],
        };
        [$soft, $hard] = $thresholds;

        $severity = $this->computeSeverity($absVariance, $soft, $hard);

        // ── Compute flags ─────────────────────────────────────────────────────
        $needsReason = ($severity !== VarianceSeverity::Info) && bccomp($absVariance, '0', 4) > 0;
        $needsManagerPin = ($severity === VarianceSeverity::Critical) && $settings->requireManagerPinAboveHard;

        return new CashCountValidationResultDTO(
            aggregateVariance: $aggregateVariance,
            severity: $severity,
            needsReason: $needsReason,
            needsManagerPin: $needsManagerPin,
            perTender: $breakdowns,
            errors: [],
        );
    }

    /**
     * Classify the absolute variance string against soft and hard thresholds.
     *
     * @param  numeric-string  $absVariance  Non-negative scale-4 decimal string (leading '-' already stripped)
     * @param  numeric-string  $soft  Scale-4 decimal threshold string
     * @param  numeric-string  $hard  Scale-4 decimal threshold string
     */
    private function computeSeverity(string $absVariance, string $soft, string $hard): VarianceSeverity
    {
        // Exact zero → Info (short-circuit, avoids spurious comparisons)
        if (bccomp($absVariance, '0', 4) === 0) {
            return VarianceSeverity::Info;
        }

        if (bccomp($absVariance, $soft, 4) <= 0) {
            return VarianceSeverity::Info;
        }

        if (bccomp($absVariance, $hard, 4) <= 0) {
            return VarianceSeverity::Warning;
        }

        return VarianceSeverity::Critical;
    }
}
