<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Rules;

use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;
use App\Rules\ValidLocationAccess;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule wrapping {@see DiscountToleranceBoundary} for use on document
 * line- and header-discount inputs. The rule fires only on document types where
 * a payment is becoming due (Invoice, SalesOrder); the request layer is
 * responsible for that gating — the rule itself is type-agnostic.
 *
 * The boundary service is resolved through the container at validate-time
 * rather than constructor-injected, mirroring the project's existing
 * {@see ValidLocationAccess} pattern: parameterised validation
 * rules need per-instance state (subtotal, companyId), and Laravel constructs
 * them with `new` from the rule list — the underlying domain service still
 * uses constructor injection at its boundary, so Rule #13 is preserved at the
 * place that matters.
 */
final class DiscountAboveTolerance implements ValidationRule
{
    public function __construct(
        private readonly string $subtotal,
        private readonly string $companyId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $discountAmount = (string) $value;

        // Zero is "no discount" — the boundary handles this internally too,
        // but short-circuit here to avoid a service hit on the common case.
        if (! is_numeric($discountAmount) || bccomp($discountAmount, '0', 4) === 0) {
            return;
        }

        try {
            app(DiscountToleranceBoundary::class)->assertDiscountAboveTolerance(
                discountAmount: $discountAmount,
                subtotal: $this->subtotal,
                companyId: $this->companyId,
            );
        } catch (DiscountBelowToleranceException $e) {
            $fail(__('documents.discount.below_tolerance', [
                'margin' => $e->toleranceMargin,
            ]));
        }
    }
}
