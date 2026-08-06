<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Rules;

use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Presentation\Requests\Concerns\AppliesDiscountToleranceRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a per-line `discount_amount` that exceeds the line's own gross
 * (quantity × unit_price), computed at the same currency scale the document
 * controller will use for {@see DocumentLine::computeLineTotal()}.
 *
 * W-3 (2026-08-03 ticket) — before this guard, `discount_amount` was
 * validated only as `nullable|numeric|min:0|3dp`, never compared against its
 * own line's gross, so an over-discount silently drove the line net, VAT, and
 * total negative into the fiscal hash chain (MTP-DSC-04 tripwire).
 *
 * Applied to the base `lines.*.discount_amount` wildcard rule in
 * `CreateDocumentRequest`/`UpdateDocumentRequest`, so it fires for EVERY
 * document type (quotes, credit notes, delivery notes, invoices, orders).
 *
 * {@see AppliesDiscountToleranceRule} ALSO constructs and lists an instance
 * of this rule directly in its own `lines.{index}.discount_amount` override
 * for invoice/order routes — it is not enough to rely on the base wildcard
 * rule alone there. Laravel's `ValidationRuleParser::explodeRules()` visits
 * the wildcard key first (merging it into any explicit
 * `lines.{index}.discount_amount` key already present), but then revisits
 * that explicit key from its ORIGINAL pre-merge snapshot and overwrites the
 * merged result with it — net effect: an explicit per-index rule array
 * silently wins over the wildcard, it does not merge with it. Both request
 * classes must therefore be double-checked whenever `lines.*.discount_amount`
 * or `lines.{index}.discount_amount` rule arrays are touched.
 */
final class LineDiscountAmountWithinGross implements ValidationRule
{
    /**
     * @param  array<int|string, mixed>  $lines  The full raw `lines` payload as submitted, so the
     *                                           rule can look up the specific line's own
     *                                           quantity/unit_price from the resolved attribute
     *                                           (e.g. `lines.2.discount_amount` → index 2).
     */
    public function __construct(
        private readonly array $lines,
        private readonly int $scale,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            // Absence/emptiness/non-numeric shape is the `nullable`/`numeric`
            // rules' job — this rule only compares well-formed numbers.
            return;
        }

        if (! preg_match('/^lines\.(\d+)\.discount_amount$/', $attribute, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $line = $this->lines[$index] ?? null;

        if (! is_array($line)) {
            return;
        }

        $quantity = $line['quantity'] ?? null;
        $unitPrice = $line['unit_price'] ?? null;

        if (! is_numeric($quantity) || ! is_numeric($unitPrice)) {
            // Malformed quantity/unit_price is rejected by their own rules —
            // nothing meaningful to compare a gross against here.
            return;
        }

        $gross = bcmul((string) $quantity, (string) $unitPrice, $this->scale);
        $discountAmount = (string) $value;

        if (bccomp($discountAmount, $gross, $this->scale) > 0) {
            $fail(__('documents.discount.amount_exceeds_line_gross', ['gross' => $gross]));
        }
    }
}
