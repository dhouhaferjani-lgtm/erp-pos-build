<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The line rule set shared by the store and update requests (DPA V7 / plan §2).
 *
 * Extracted so the two verbs cannot drift: the PATCH contract is explicitly
 * "identical field rules to POST", and two hand-maintained copies is how that
 * stops being true.
 */
final class StockAdjustmentLineRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function forCompany(string $tenantId, string $companyId, bool $linesRequired = true): array
    {
        return [
            'lines' => $linesRequired
                ? ['required', 'array', 'min:1']
                : ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => [
                'required',
                'string',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
            'lines.*.variant_id' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('product_variants', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            // The PUBLIC lot identifier. Existence, product ownership and
            // location applicability are decided in the service, where the
            // header's location is in scope (D1b part 2).
            'lines.*.batch_uuid' => ['nullable', 'string', 'uuid'],
            'lines.*.reason_code' => ['required', Rule::in(MovementReason::manualAdjustmentValues())],
            'lines.*.delta_quantity' => [
                'required',
                'numeric',
                'not_in:0',
                'regex:'.StoreStockAdjustmentRequest::SIGNED_QUANTITY_REGEX,
            ],
            // SIGNED too: stock_levels.quantity has no non-negative CHECK and POS
            // paths can drive it below zero.
            'lines.*.observed_before' => [
                'required',
                'numeric',
                'regex:'.StoreStockAdjustmentRequest::SIGNED_QUANTITY_REGEX,
            ],
            'lines.*.line_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'lines.*.delta_quantity.regex' => 'The delta quantity must be a signed number with at most 4 decimal places.',
            'lines.*.delta_quantity.not_in' => 'A stock adjustment delta must not be zero.',
            'lines.*.observed_before.regex' => 'The observed quantity must be a signed number with at most 4 decimal places.',
        ];
    }

    /**
     * The reason ↔ sign invariant, DERIVED from MovementReason::getMovementType()
     * (D6a). Zero literal reason lists: the enum is the single origin, the
     * migration's CHECK is a frozen snapshot of the same partition, and
     * AdjustmentReasonSignPartitionTest fails CI if the two ever disagree.
     */
    public static function attachSignInvariant(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $lines */
            $lines = (array) ($validator->getData()['lines'] ?? []);

            foreach ($lines as $index => $line) {
                $reason = MovementReason::tryFrom((string) ($line['reason_code'] ?? ''));
                $delta = (string) ($line['delta_quantity'] ?? '');

                if ($reason === null || ! is_numeric($delta)) {
                    continue;
                }

                $sign = bccomp($delta, '0', 4); // precision-ok: quantity is decimal(15,4), canonical scale 4
                $expected = $reason->getMovementType() === 'in' ? 1 : -1;

                if ($sign !== 0 && $sign !== $expected) {
                    $validator->errors()->add(
                        "lines.{$index}.delta_quantity",
                        $expected === 1
                            ? "Reason {$reason->value} requires a positive delta quantity."
                            : "Reason {$reason->value} requires a negative delta quantity.",
                    );
                }
            }
        });
    }

    /**
     * Mirrors the DB's four partial uniques: one correction per
     * (product, variant, lot) per document.
     */
    public static function attachLineUniqueness(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $lines */
            $lines = (array) ($validator->getData()['lines'] ?? []);
            $seen = [];

            foreach ($lines as $index => $line) {
                $key = implode('|', [
                    (string) ($line['product_id'] ?? ''),
                    (string) ($line['variant_id'] ?? ''),
                    (string) ($line['batch_uuid'] ?? ''),
                ]);

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        "lines.{$index}.product_id",
                        'Each product, variant and lot may appear at most once per adjustment.',
                    );

                    continue;
                }

                $seen[$key] = true;
            }
        });
    }
}
