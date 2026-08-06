<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests\Concerns;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Services\Conversion;
use App\Modules\Document\Presentation\Rules\LineDiscountAmountWithinGross;
use App\Modules\Treasury\Application\Services\DiscountToleranceBoundary;
use App\Modules\Treasury\Presentation\Rules\DiscountAboveTolerance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;

/**
 * Adds the anti-abuse discount/tolerance boundary check (spec §7) to a
 * document FormRequest's rule list, but ONLY for document types where a
 * payment is becoming due — Invoice and SalesOrder. Quote, CreditNote and
 * DeliveryNote bypass the rule:
 *
 *  - Quote is negotiation-only; the conversion auto-strip in the
 *    {@see Conversion} converters
 *    catches sub-tolerance discounts when the quote graduates.
 *  - CreditNote moves money outward — no skimming vector.
 *  - DeliveryNote carries no payment obligation.
 *
 * Document type is inferred from the matched route's name prefix
 * (`invoices.*`, `orders.*`) rather than from request payload, since
 * `CreateDocumentRequest` is shared across all document controllers and the
 * type is implicit in the route binding.
 *
 * @see DiscountAboveTolerance
 * @see DiscountToleranceBoundary
 *
 * @mixin FormRequest
 */
trait AppliesDiscountToleranceRule
{
    /**
     * Merge per-line and document-header discount/tolerance rules into the
     * existing rule set.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function withDiscountToleranceRules(array $rules): array
    {
        if (! $this->isPaymentDueDocumentRoute()) {
            return $rules;
        }

        $companyContext = app(CompanyContext::class);
        if (! $companyContext->hasCompany()) {
            return $rules;
        }
        $company = $companyContext->requireCompany();
        $companyId = $company->id;
        // $this->scaleResolver: the trait's two consumers (CreateDocumentRequest,
        // UpdateDocumentRequest) both constructor-inject CurrencyScaleResolverInterface
        // as `scaleResolver` — traits share the host class's scope, so this private
        // property is directly visible here without an app() lookup (rule 13,
        // backend gate IMPORTANT-2, 2026-08-06).
        $scale = $this->scaleResolver->getScale($company->currency);

        $lines = $this->input('lines', []);
        $linesArray = is_array($lines) ? $lines : [];

        $documentSubtotal = '0';
        foreach ($linesArray as $idx => $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = (string) ($line['quantity'] ?? '0');
            $unitPrice = (string) ($line['unit_price'] ?? '0');

            if (! is_numeric($quantity) || ! is_numeric($unitPrice)) {
                continue;
            }

            $lineSubtotal = bcmul($quantity, $unitPrice, 4);
            /** @phpstan-ignore-next-line argument.type */
            $documentSubtotal = bcadd($documentSubtotal, $lineSubtotal, 4);

            // Per-line evaluation: a 5-line invoice with 5 sub-tolerance
            // discounts rejects all 5 lines independently. Aggregate
            // semantics (e.g. sum of line discounts vs threshold) was
            // considered and rejected per spec §7 ambiguity — see PR #49
            // audit Low #1.
            //
            // W-3 (2026-08-03): this array REPLACES the base rules()'s
            // `lines.*.discount_amount` wildcard rule set for this specific
            // index rather than merging with it — Laravel's
            // `ValidationRuleParser::explodeRules()` processes the wildcard
            // key first (merging in whatever explicit `lines.{idx}.*` rules
            // already exist), then re-visits the ORIGINAL explicit key from
            // its pre-merge snapshot and overwrites the merged result with
            // it. So `LineDiscountAmountWithinGross` must be listed here
            // explicitly too, or invoice/order lines would silently lose the
            // over-gross guard the base rule set provides to every other
            // document type.
            $rules["lines.{$idx}.discount_amount"] = [
                'nullable',
                'numeric',
                'min:0',
                'regex:/^\d+(\.\d{1,3})?$/',
                new DiscountAboveTolerance(
                    subtotal: $lineSubtotal,
                    companyId: $companyId,
                ),
                new LineDiscountAmountWithinGross(
                    lines: $linesArray,
                    scale: $scale,
                ),
            ];
        }

        // Document-header discount: defense-in-depth. No controller currently
        // ships a header `discount_amount` in payload, but the column exists
        // on `documents` and any future endpoint that does pass one needs the
        // same anti-abuse boundary. Validated against the rolled-up document
        // subtotal so the threshold matches what a payment-time tolerance
        // call would see for the whole document.
        if (bccomp($documentSubtotal, '0', 4) > 0) {
            $rules['discount_amount'] = [
                'nullable',
                'numeric',
                'min:0',
                'regex:/^\d+(\.\d{1,3})?$/',
                new DiscountAboveTolerance(
                    subtotal: $documentSubtotal,
                    companyId: $companyId,
                ),
            ];
        }

        return $rules;
    }

    private function isPaymentDueDocumentRoute(): bool
    {
        $route = $this->route();

        // FormRequest::route() returns Route|object|string|null; we only act
        // on the typed Route case to keep the rule untyped-cast-free.
        if (! $route instanceof Route) {
            return false;
        }

        $name = $route->getName() ?? '';

        // Whitelist exact create/update endpoints rather than prefix-matching
        // `invoices.*` / `orders.*`, so future read-only or action routes
        // (e.g. invoices.email, invoices.pdf, orders.confirm) don't
        // unintentionally trigger the boundary validator.
        return in_array($name, [
            'invoices.store',
            'invoices.update',
            'orders.store',
            'orders.update',
        ], true);
    }
}
