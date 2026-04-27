<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Requests\Concerns;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Services\Conversion;
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
        $companyId = $companyContext->requireCompanyId();

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

            $rules["lines.{$idx}.discount_amount"] = [
                'nullable',
                'numeric',
                'min:0',
                new DiscountAboveTolerance(
                    subtotal: $lineSubtotal,
                    companyId: $companyId,
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

        return str_starts_with($name, 'invoices.')
            || str_starts_with($name, 'orders.');
    }
}
