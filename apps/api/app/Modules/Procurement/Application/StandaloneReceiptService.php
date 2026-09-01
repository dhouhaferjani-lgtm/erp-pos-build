<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptResult;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class StandaloneReceiptService
{
    private const string SOURCE_STANDALONE_RECEIPT = 'standalone_receipt';

    private const string SOURCE_INVOICE_FIRST = 'invoice_first';

    public function __construct(
        private ProcurementPolicyResolver $policyResolver,
        private DocumentNumberingService $numberingService,
        private PurchaseOrderService $purchaseOrderService,
        private GoodsReceiptService $goodsReceiptService,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function execute(StandaloneReceiptInput $input): GoodsReceiptResult
    {
        $this->assertInput($input);

        $company = Company::query()->with('tenant')->findOrFail($input->companyId);
        $policy = $this->policyResolver->forCompany($company->id);
        if ($input->source === self::SOURCE_INVOICE_FIRST && ! $policy->allowsInvoiceFirst()) {
            throw new \DomainException("Invoice-first procurement is disabled for company [{$company->id}].");
        }
        if ($input->source === self::SOURCE_STANDALONE_RECEIPT && ! $policy->allowsReceiptFirst()) {
            throw new \DomainException("Receipt-first procurement is disabled for company [{$company->id}].");
        }

        $purchaseOrder = null;
        $existing = null;

        for ($attempt = 0; $purchaseOrder === null && $existing === null && $attempt < 2; $attempt++) {
            try {
                $purchaseOrder = DB::transaction(function () use ($input, $company): Document {
                    DB::table('procurement_idempotency_keys')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $company->tenant_id,
                        'company_id' => $company->id,
                        'idempotency_key' => $input->idempotencyKey,
                        'purchase_order_id' => null,
                        'goods_receipt_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $draft = $this->createAutoPurchaseOrder($company, $input);
                    $confirmed = $this->purchaseOrderService->confirm($draft, $input->actorId);

                    DB::table('procurement_idempotency_keys')
                        ->where('company_id', $company->id)
                        ->where('idempotency_key', $input->idempotencyKey)
                        ->update([
                            'purchase_order_id' => $confirmed->id,
                            'updated_at' => now(),
                        ]);

                    /** @var Document $fresh */
                    $fresh = $confirmed->fresh(['lines']);

                    return $fresh;
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = $this->existingResult($company->id, $input->idempotencyKey);
            }
        }

        if ($existing instanceof GoodsReceiptResult) {
            return $existing;
        }

        if ($existing instanceof Document) {
            $purchaseOrder = $existing;
        }

        if (! $purchaseOrder instanceof Document) {
            throw new \DomainException('Standalone receipt idempotency key could not be claimed.');
        }

        try {
            $receipt = DB::transaction(function () use ($purchaseOrder, $company, $input): GoodsReceipt {
                $purchaseOrder->load('lines');
                $maps = $this->receiptMaps($purchaseOrder, $input);

                $draft = $this->goodsReceiptService->createDraft(
                    $purchaseOrder,
                    $maps['receivedQuantities'],
                    $maps['batchData'],
                    $maps['freeQuantities'],
                    $maps['receivedUnitPrices'],
                    null,
                    $input->actorId,
                    $input->externalReference,
                    $input->externalDate,
                    allowExpired: $input->allowExpired,
                );

                $receipt = $input->postImmediately
                    ? $this->goodsReceiptService->post($draft, $input->actorId, null, true)
                    : $draft;

                DB::table('procurement_idempotency_keys')
                    ->where('company_id', $company->id)
                    ->where('idempotency_key', $input->idempotencyKey)
                    ->update([
                        'goods_receipt_id' => $receipt->id,
                        'updated_at' => now(),
                    ]);

                return $receipt;
            });
        } catch (\Throwable $exception) {
            $this->compensateFailedReceiptCreation($purchaseOrder, $company->id, $input);

            throw $exception;
        }

        /** @var Document $freshOrder */
        $freshOrder = $receipt->purchaseOrder->fresh(['lines']);

        /** @var GoodsReceipt $freshReceipt */
        $freshReceipt = $receipt->fresh(['lines']);

        return new GoodsReceiptResult($freshOrder, $freshReceipt);
    }

    private function assertInput(StandaloneReceiptInput $input): void
    {
        if (! in_array($input->source, [self::SOURCE_STANDALONE_RECEIPT, self::SOURCE_INVOICE_FIRST], true)) {
            throw new \DomainException("Unsupported standalone receipt source [{$input->source}].");
        }

        if ($input->idempotencyKey === '' || strlen($input->idempotencyKey) > 64) {
            throw new \DomainException('Standalone receipt idempotency key must be between 1 and 64 characters.');
        }

        if ($input->lines === []) {
            throw new \DomainException('Standalone receipt requires at least one line.');
        }
    }

    private function createAutoPurchaseOrder(Company $company, StandaloneReceiptInput $input): Document
    {
        $supplier = Partner::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('type', PartnerType::Supplier)
            ->findOrFail($input->supplierId);

        Location::query()
            ->where('company_id', $company->id)
            ->findOrFail($input->locationId);

        $scale = $this->scaleResolver->getScale((string) $company->currency);
        $subtotal = CurrencyScale::bcformatStrict('0', $scale);

        foreach ($input->lines as $line) {
            /** @var numeric-string $lineTotal */
            $lineTotal = CurrencyScale::bcformatStrict(
                bcmul(
                    CurrencyScale::bcformatStrict($line->quantity, 4),
                    CurrencyScale::bcformatStrict($line->unitPrice, $scale),
                    $scale + 4,
                ),
                $scale,
            );
            $subtotal = CurrencyScale::bcformatStrict(bcadd($subtotal, $lineTotal, $scale), $scale);
        }

        $purchaseOrder = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $input->locationId,
            'partner_id' => $supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => $this->numberingService->generateNumber($company->tenant_id, $company->id, DocumentType::PurchaseOrder),
            'document_date' => now()->toDateString(),
            'currency' => (string) $company->currency,
            'subtotal' => $subtotal,
            'discount_amount' => CurrencyScale::bcformatStrict('0', $scale),
            'tax_amount' => CurrencyScale::bcformatStrict('0', $scale),
            'total' => $subtotal,
            'balance_due' => $subtotal,
            'payload' => [
                'auto_generated' => [
                    'source' => $input->source,
                    'actor' => $input->actorId,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $lineNumber = 1;
        foreach ($input->lines as $line) {
            $this->createPurchaseOrderLine($purchaseOrder, $line, $lineNumber++, $scale);
        }

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        return $fresh;
    }

    private function createPurchaseOrderLine(
        Document $purchaseOrder,
        StandaloneReceiptLineInput $line,
        int $lineNumber,
        int $scale,
    ): void {
        $product = Product::query()
            ->where('tenant_id', $purchaseOrder->tenant_id)
            ->where('company_id', $purchaseOrder->company_id)
            ->findOrFail($line->productId);

        // Scoped variant resolution (api.document.045 class): the id must belong to
        // THIS tenant, company AND product — never persist the raw input UUID.
        $variantId = null;
        if ($line->variantId !== null) {
            $variantId = (string) ProductVariant::query()
                ->where('tenant_id', $purchaseOrder->tenant_id)
                ->where('company_id', $purchaseOrder->company_id)
                ->where('product_id', $product->id)
                ->findOrFail($line->variantId)
                ->id;
        }

        $quantity = CurrencyScale::bcformatStrict($line->quantity, 4);
        $freeQuantity = CurrencyScale::bcformatStrict($line->freeQuantity, 4);
        $unitPrice = CurrencyScale::bcformatStrict($line->unitPrice, $scale);
        $lineTotal = CurrencyScale::bcformatStrict(bcmul($quantity, $unitPrice, $scale + 4), $scale);

        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'location_id' => $purchaseOrder->location_id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'product_code' => $product->sku,
            'line_number' => $lineNumber,
            'description' => (string) $product->name,
            'quantity' => $quantity,
            'free_quantity' => $freeQuantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'tax_rate' => '0.00',
            'tax_amount' => CurrencyScale::bcformatStrict('0', $scale),
            'line_total' => $lineTotal,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => CurrencyScale::bcformatStrict($unitPrice, 6),
            'price_entry_mode' => PriceEntryMode::Unit->value,
        ]);
    }

    /**
     * @return array{
     *   receivedQuantities: array<string, string>,
     *   freeQuantities: array<string, string>,
     *   receivedUnitPrices: array<string, string>,
     *   batchData: array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>
     * }
     */
    private function receiptMaps(Document $purchaseOrder, StandaloneReceiptInput $input): array
    {
        $receivedQuantities = [];
        $freeQuantities = [];
        $receivedUnitPrices = [];
        $batchData = [];

        foreach ($purchaseOrder->lines->values() as $index => $poLine) {
            $inputLine = $input->lines[$index] ?? null;
            if (! $inputLine instanceof StandaloneReceiptLineInput) {
                throw new \DomainException('Standalone receipt line mapping drifted from auto-PO line order.');
            }

            $receivedQuantities[$poLine->id] = CurrencyScale::bcformatStrict($inputLine->quantity, 4);
            $freeQuantities[$poLine->id] = CurrencyScale::bcformatStrict($inputLine->freeQuantity, 4);

            if ($inputLine->batch !== null) {
                $batchData[$poLine->id] = $inputLine->batch;
            }
        }

        return [
            'receivedQuantities' => $receivedQuantities,
            'freeQuantities' => $freeQuantities,
            'receivedUnitPrices' => $receivedUnitPrices,
            'batchData' => $batchData,
        ];
    }

    private function existingResult(string $companyId, string $idempotencyKey): GoodsReceiptResult|Document|null
    {
        $row = DB::table('procurement_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->goods_receipt_id !== null && $row->purchase_order_id !== null) {
            /** @var Document $purchaseOrder */
            $purchaseOrder = Document::query()->with('lines')->findOrFail((string) $row->purchase_order_id);
            /** @var GoodsReceipt $receipt */
            $receipt = GoodsReceipt::query()->with('lines')->findOrFail((string) $row->goods_receipt_id);

            return new GoodsReceiptResult($purchaseOrder, $receipt);
        }

        $purchaseOrder = $row->purchase_order_id !== null
            ? Document::query()->with('lines')->find((string) $row->purchase_order_id)
            : null;

        if ($purchaseOrder instanceof Document) {
            /** @var GoodsReceipt|null $postedReceipt */
            $postedReceipt = GoodsReceipt::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->where('purchase_order_id', $purchaseOrder->id)
                ->where('status', GoodsReceiptStatus::Posted)
                ->orderBy('created_at')
                ->first();

            if ($postedReceipt instanceof GoodsReceipt) {
                DB::table('procurement_idempotency_keys')
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->update([
                        'goods_receipt_id' => $postedReceipt->id,
                        'updated_at' => now(),
                    ]);

                return new GoodsReceiptResult($purchaseOrder, $postedReceipt);
            }
        }

        if ($purchaseOrder instanceof Document && $purchaseOrder->status === DocumentStatus::Confirmed) {
            return $purchaseOrder;
        }

        DB::table('procurement_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->delete();

        return null;
    }

    private function compensateFailedReceiptCreation(Document $purchaseOrder, string $companyId, StandaloneReceiptInput $input): void
    {
        $postedReceiptExists = GoodsReceipt::query()
            ->where('company_id', $companyId)
            ->where('purchase_order_id', $purchaseOrder->id)
            ->where('status', GoodsReceiptStatus::Posted)
            ->exists();

        if ($postedReceiptExists) {
            Log::warning('Standalone receipt compensation skipped because a posted receipt exists for the purchase order.', [
                'company_id' => $companyId,
                'idempotency_key' => $input->idempotencyKey,
                'purchase_order_id' => $purchaseOrder->id,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($purchaseOrder, $companyId, $input): void {
                $purchaseOrder->forceFill([
                    'status' => DocumentStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $input->actorId,
                    'cancellation_reason' => 'Standalone receipt compensation after receipt creation failure.',
                ])->save();

                DB::table('procurement_idempotency_keys')
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $input->idempotencyKey)
                    ->delete();
            });
        } catch (\Throwable $compensationFailure) {
            Log::error('Standalone receipt compensation failed; preserving original exception.', [
                'company_id' => $companyId,
                'idempotency_key' => $input->idempotencyKey,
                'purchase_order_id' => $purchaseOrder->id,
                'exception' => $compensationFailure,
            ]);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
