<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for processing partial returns on POS receipts.
 *
 * Creates a new negative receipt (return receipt) that references the original.
 * The return receipt is part of the fiscal hash chain, maintaining NF525 compliance.
 *
 * Flow:
 * 1. Validate original receipt and return quantities
 * 2. Create a new receipt with negative amounts (receipt_type = 'return')
 * 3. Restore stock for returned items
 * 4. Record cash drawer refund if applicable
 * 5. Compute fiscal hash chain (return receipt chains like any other receipt)
 */
final class ReceiptReturnService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly CashDrawerService $cashDrawerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Process a partial or full return on a receipt.
     *
     * @param  string  $originalReceiptId  UUID of the original receipt
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines  Lines to return with quantities
     * @param  ReturnReason  $returnReason  Reason for the return
     * @param  User  $cashier  The cashier processing the return
     * @param  string  $terminalId  The terminal processing the return
     * @param  string|null  $notes  Optional notes
     * @return Receipt The created return receipt with relationships loaded
     *
     * @throws \RuntimeException If receipt cannot be returned
     * @throws \InvalidArgumentException If return data is invalid
     */
    public function processReturn(
        string $originalReceiptId,
        array $returnLines,
        ReturnReason $returnReason,
        User $cashier,
        string $terminalId,
        ?string $notes = null,
    ): Receipt {
        if (count($returnLines) === 0) {
            throw new \InvalidArgumentException('At least one line item is required for a return');
        }

        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($originalReceiptId, $returnLines, $returnReason, $cashier, $terminalId, $notes, $companyId): Receipt {
            // 1. Load and validate original receipt
            /** @var Receipt $originalReceipt */
            $originalReceipt = Receipt::with(['lines', 'returnReceipts.lines'])
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($originalReceiptId);

            $this->validateOriginalReceipt($originalReceipt);

            // 2. Lock and load terminal
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $terminalId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $terminal->isActive()) {
                throw new \RuntimeException('Terminal is not active');
            }

            // 3. Verify active shift
            $shift = Shift::where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->first();

            if ($shift === null) {
                throw new \RuntimeException('No active shift on this terminal. Open a shift first.');
            }

            // 4. Validate return quantities against original (minus already returned)
            $validatedLines = $this->validateReturnQuantities($originalReceipt, $returnLines);

            // 5. Calculate return receipt totals (negative amounts)
            /** @var Company $company */
            $company = $terminal->company ?? Company::findOrFail($terminal->company_id);
            $currency = $company->currency ?? 'TND';

            $receiptLines = [];
            $vatAggregates = [];
            $subtotal = '0.00';
            $totalTax = '0.00';
            $totalDiscount = '0.00';

            foreach ($validatedLines as $index => $returnLine) {
                /** @var ReceiptLine $originalLine */
                $originalLine = $returnLine['original_line'];
                /** @var numeric-string $returnQuantity */
                $returnQuantity = $returnLine['quantity'];

                // Calculate proportional amounts based on return quantity vs original quantity
                $ratio = bcdiv($returnQuantity, (string) $originalLine->quantity, 10);

                // Line total is negative (we are returning money)
                /** @var numeric-string $lineTotal */
                $lineTotal = bcmul(
                    bcmul($ratio, (string) $originalLine->line_total, $this->scale()),
                    '-1',
                    $this->scale(),
                );

                $taxRate = (string) $originalLine->tax_rate;
                $taxRateDecimal = bcdiv($taxRate, '100', 6);
                $divisor = bcadd('1', $taxRateDecimal, 6);
                $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());

                $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                $totalTax = bcadd($totalTax, $taxAmount, $this->scale());

                // Proportional discount
                /** @var numeric-string $discountAmount */
                $discountAmount = bcmul($ratio, (string) $originalLine->discount_amount, $this->scale());
                $totalDiscount = bcadd($totalDiscount, $discountAmount, $this->scale());

                $receiptLines[] = [
                    'line_number' => $index + 1,
                    'original_line_id' => $originalLine->id,
                    'product_id' => $originalLine->product_id,
                    'composite_item_id' => $originalLine->composite_item_id,
                    'product_code' => $originalLine->product_code,
                    'product_name' => $originalLine->product_name,
                    'product_description' => $originalLine->product_description,
                    'quantity' => '-'.$returnQuantity,
                    'unit' => $originalLine->unit,
                    'unit_price' => (string) $originalLine->unit_price,
                    'line_total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'modifiers' => $originalLine->modifiers,
                    'discount_amount' => $discountAmount,
                    'discount_reason' => $originalLine->discount_reason,
                ];

                // Aggregate VAT by rate
                $rateKey = $taxRate;
                if (! isset($vatAggregates[$rateKey])) {
                    $vatAggregates[$rateKey] = [
                        'tax_rate' => $taxRate,
                        'net_amount' => '0.00',
                        'vat_amount' => '0.00',
                        'gross_amount' => '0.00',
                    ];
                }
                $vatAggregates[$rateKey]['net_amount'] = bcadd($vatAggregates[$rateKey]['net_amount'], $netAmount, $this->scale());
                $vatAggregates[$rateKey]['vat_amount'] = bcadd($vatAggregates[$rateKey]['vat_amount'], $taxAmount, $this->scale());
                $vatAggregates[$rateKey]['gross_amount'] = bcadd($vatAggregates[$rateKey]['gross_amount'], $lineTotal, $this->scale());
            }

            // Recalculate VAT aggregates from aggregate net_amount (same as ReceiptCreationService)
            $totalTax = '0.00';
            foreach ($vatAggregates as &$vatData) {
                $vatData['vat_amount'] = $this->roundVat($vatData['net_amount'], $vatData['tax_rate']);
                $vatData['gross_amount'] = bcadd($vatData['net_amount'], $vatData['vat_amount'], $this->scale());
                $totalTax = bcadd($totalTax, $vatData['vat_amount'], $this->scale());
            }
            unset($vatData);

            $total = bcadd($subtotal, $totalTax, $this->scale());

            // 6. Generate receipt number and sequence
            $currentYear = (int) now()->format('Y');
            if ($terminal->needsSequenceReset()) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
            }

            $sequence = $terminal->current_sequence;
            $terminalCode = $terminal->code;
            $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
            $receiptNumber = "{$terminalCode}-{$currentYear}-{$sequenceStr}";

            // 7. Calculate hashes
            $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
            $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

            // 8. Create return receipt
            $now = Carbon::now();
            $previousHash = $terminal->last_hash;
            $receiptId = Str::uuid()->toString();

            /** @var Receipt $returnReceipt */
            $returnReceipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $originalReceipt->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $receiptNumber,
                'receipt_type' => ReceiptType::Return,
                'original_receipt_id' => $originalReceipt->id,
                'return_reason' => $returnReason,
                'chain_sequence' => $sequence,
                'receipt_year' => $currentYear,
                'previous_hash' => $previousHash,
                'posted_at' => $now,
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name ?? 'Unknown',
                'subtotal' => $subtotal,
                'tax_amount' => $totalTax,
                'discount_amount' => $totalDiscount,
                'total' => $total,
                'currency' => $currency,
                'consumption_mode' => $originalReceipt->consumption_mode,
                'customer_name' => $originalReceipt->customer_name,
                'customer_identifier' => $originalReceipt->customer_identifier,
                'partner_id' => $originalReceipt->partner_id,
                'is_voided' => false,
                'vat_breakdown_hash' => $vatHash,
                'payment_methods_hash' => $paymentHash,
                'notes' => $notes,
            ]);

            $returnReceipt->id = $receiptId;
            $returnReceipt->setRelation('terminal', $terminal);
            $fiscalHash = $this->receiptHashService->calculateHash($returnReceipt, $previousHash);
            $returnReceipt->fiscal_hash = $fiscalHash;
            $returnReceipt->save();

            // 9. Create receipt lines
            foreach ($receiptLines as $lineData) {
                ReceiptLine::create(array_merge($lineData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $returnReceipt->id,
                ]));
            }

            // 10. Create VAT details
            foreach ($vatAggregates as $vatData) {
                ReceiptVatDetail::create(array_merge($vatData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $returnReceipt->id,
                ]));
            }

            // 11. Update terminal sequence and hash chain
            $terminal->current_sequence = $sequence + 1;
            $terminal->last_hash = $fiscalHash;
            $terminal->save();

            // 12. Restore stock for returned items
            foreach ($validatedLines as $returnLine) {
                /** @var ReceiptLine $originalLine */
                $originalLine = $returnLine['original_line'];

                if ($originalLine->product_id === null) {
                    continue;
                }

                /** @var numeric-string $qty */
                $qty = $returnLine['quantity'];
                $this->restoreStock(
                    tenantId: $terminal->tenant_id,
                    companyId: $companyId,
                    locationId: $originalReceipt->location_id,
                    productId: $originalLine->product_id,
                    quantity: $qty,
                    returnReceiptId: $returnReceipt->id,
                    cashierId: $cashier->id,
                );
            }

            // 13. Record cash drawer refund
            $this->recordCashDrawerRefund($returnReceipt, $total, $cashier, $shift);

            /** @var Receipt */
            return $returnReceipt->fresh([
                'lines',
                'vatDetails',
                'terminal',
                'cashier',
                'originalReceipt',
            ]);
        });
    }

    /**
     * Validate that the original receipt can be returned.
     *
     * @throws \RuntimeException If receipt cannot be returned
     */
    private function validateOriginalReceipt(Receipt $receipt): void
    {
        if ($receipt->is_voided) {
            throw new \RuntimeException('Cannot return a voided receipt');
        }

        if ($receipt->receipt_type === ReceiptType::Return) {
            throw new \RuntimeException('Cannot return a return receipt');
        }
    }

    /**
     * Validate return quantities against original receipt, accounting for previous returns.
     *
     * @param  Receipt  $originalReceipt  The original receipt with lines and returnReceipts loaded
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines
     * @return array<int, array{original_line: ReceiptLine, quantity: string}>
     *
     * @throws \InvalidArgumentException If quantities are invalid
     */
    private function validateReturnQuantities(Receipt $originalReceipt, array $returnLines): array
    {
        // Calculate already-returned quantities per original line
        $alreadyReturned = $this->calculateAlreadyReturnedQuantities($originalReceipt);

        $originalLinesById = $originalReceipt->lines->keyBy('id');
        $validated = [];

        foreach ($returnLines as $returnLine) {
            $lineId = $returnLine['line_id'];
            /** @var numeric-string $requestedQuantity */
            $requestedQuantity = (string) $returnLine['quantity'];

            if (! $originalLinesById->has($lineId)) {
                throw new \InvalidArgumentException("Line '{$lineId}' not found on original receipt");
            }

            /** @var ReceiptLine $originalLine */
            $originalLine = $originalLinesById->get($lineId);

            if (bccomp($requestedQuantity, '0', 4) <= 0) {
                throw new \InvalidArgumentException("Return quantity must be positive for line '{$lineId}'");
            }

            /** @var numeric-string $alreadyReturnedQty */
            $alreadyReturnedQty = $alreadyReturned[$lineId] ?? '0.000';
            /** @var numeric-string $remainingReturnable */
            $remainingReturnable = bcsub((string) $originalLine->quantity, $alreadyReturnedQty, 3);

            if (bccomp($requestedQuantity, $remainingReturnable, 3) > 0) {
                throw new \InvalidArgumentException(
                    "Cannot return {$requestedQuantity} of '{$originalLine->product_name}'. "
                    ."Maximum returnable: {$remainingReturnable} (original: {$originalLine->quantity}, already returned: {$alreadyReturnedQty})"
                );
            }

            $validated[] = [
                'original_line' => $originalLine,
                'quantity' => $requestedQuantity,
            ];
        }

        return $validated;
    }

    /**
     * Calculate already-returned quantities per original line.
     *
     * Sums negative quantities from all non-voided return receipts referencing this original receipt.
     * Uses original_line_id for precise matching when available, falls back to product attribute
     * matching for legacy return lines created before original_line_id was added.
     *
     * @return array<string, string> Map of original line ID to returned quantity
     */
    private function calculateAlreadyReturnedQuantities(Receipt $originalReceipt): array
    {
        $returned = [];

        foreach ($originalReceipt->returnReceipts as $returnReceipt) {
            if ($returnReceipt->is_voided) {
                continue;
            }

            foreach ($returnReceipt->lines as $returnLine) {
                // Return line quantities are negative, so we take the absolute value
                $absQuantity = bcmul((string) $returnLine->quantity, '-1', 3);

                // Prefer direct FK match when available (new return lines)
                if ($returnLine->original_line_id !== null) {
                    $key = $returnLine->original_line_id;
                    $returned[$key] = bcadd($returned[$key] ?? '0.000', $absQuantity, 3);

                    continue;
                }

                // Legacy fallback: match by product attributes (breaks on duplicate products)
                foreach ($originalReceipt->lines as $originalLine) {
                    $sameProduct = (
                        $originalLine->product_id === $returnLine->product_id
                        && $originalLine->composite_item_id === $returnLine->composite_item_id
                        && $originalLine->product_code === $returnLine->product_code
                    );

                    if ($sameProduct) {
                        $key = $originalLine->id;
                        $returned[$key] = bcadd($returned[$key] ?? '0.000', $absQuantity, 3);
                        break;
                    }
                }
            }
        }

        return $returned;
    }

    /**
     * Restore stock for a returned product.
     *
     * Creates a Receipt (inbound) StockMovement with POSReturn reason.
     *
     * @param  numeric-string  $quantity
     */
    private function restoreStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $returnReceiptId,
        string $cashierId,
    ): void {
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            Log::warning('No stock level found for product during return stock restore', [
                'product_id' => $productId,
                'location_id' => $locationId,
            ]);

            return;
        }

        $quantityBefore = (string) $stockLevel->quantity;
        $quantityAfter = bcadd((string) $stockLevel->quantity, $quantity, 2);

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::POSReturn,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference' => 'POS Return',
            'reference_type' => 'pos_receipt_return',
            'reference_id' => $returnReceiptId,
            'notes' => "Stock returned via POS return (return receipt: {$returnReceiptId})",
            'user_id' => $cashierId,
            'is_historical' => false,
        ]);
    }

    /**
     * Record cash drawer refund for the return amount.
     *
     * @param  numeric-string  $returnTotal  Negative total
     */
    private function recordCashDrawerRefund(Receipt $returnReceipt, string $returnTotal, User $cashier, Shift $shift): void
    {
        // Return total is negative, refund amount is positive (absolute value)
        $refundAmount = bcmul($returnTotal, '-1', $this->scale());

        if (bccomp($refundAmount, '0.00', $this->scale()) <= 0) {
            return;
        }

        $this->cashDrawerService->recordRefund(
            $shift,
            $refundAmount,
            $cashier,
            $returnReceipt->id,
        );
    }

    /**
     * Round VAT amount to match PostgreSQL rounding.
     */
    /**
     * @return numeric-string
     */
    private function roundVat(string $netAmount, string $taxRate): string
    {
        $raw = (float) $netAmount * (float) $taxRate / 100.0;

        /** @var numeric-string */
        return number_format(round($raw, $this->scale()), $this->scale(), '.', '');
    }
}
