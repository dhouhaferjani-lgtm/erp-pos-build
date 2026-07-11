<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\DTOs\DraftPurchaseOrderData;
use App\Modules\Document\Application\DTOs\DraftPurchaseOrderLineData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DraftPurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function createDraft(DraftPurchaseOrderData $data): Document
    {
        return DB::transaction(function () use ($data): Document {
            $company = Company::query()
                ->where('tenant_id', $data->tenantId)
                ->whereKey($data->companyId)
                ->firstOrFail();
            $scale = $this->scaleResolver->getScale($company->currency);
            $prepared = $this->prepareLines($data->tenantId, $data->companyId, $company, $data->lines, $scale);
            $totals = $this->totals($prepared, $scale);

            $document = Document::query()->create([
                'tenant_id' => $data->tenantId,
                'company_id' => $data->companyId,
                'type' => DocumentType::PurchaseOrder,
                'status' => DocumentStatus::Draft,
                'fiscal_status' => FiscalStatus::Draft,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::PurchaseOrder),
                'partner_id' => $data->supplierId,
                'location_id' => $data->destinationLocationId,
                'document_number' => $this->numberingService->generateNumber(
                    $data->tenantId,
                    $data->companyId,
                    DocumentType::PurchaseOrder,
                ),
                'document_date' => now()->toDateString(),
                'currency' => $company->currency,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total' => $totals['total'],
            ]);
            $this->persistLines($document, $prepared, 1, $scale);

            return $document->fresh('lines') ?? $document;
        });
    }

    /** @param list<DraftPurchaseOrderLineData> $lines */
    public function appendLines(
        string $documentId,
        string $companyId,
        string $supplierId,
        array $lines,
    ): Document {
        return DB::transaction(function () use ($documentId, $companyId, $supplierId, $lines): Document {
            $document = Document::query()->lockForUpdate()->find($documentId);
            if ($document === null
                || $document->company_id !== $companyId
                || $document->type !== DocumentType::PurchaseOrder
                || $document->status !== DocumentStatus::Draft
                || $document->partner_id !== $supplierId) {
                throw ValidationException::withMessages([
                    'existing_document_id' => 'The document must be a draft purchase order for this company and supplier.',
                ]);
            }

            $company = Company::query()->whereKey($companyId)->firstOrFail();
            $scale = $this->scaleResolver->getScale($document->currency);
            $prepared = $this->prepareLines($document->tenant_id, $companyId, $company, $lines, $scale);
            $nextLine = ((int) $document->lines()->max('line_number')) + 1;
            $this->persistLines($document, $prepared, $nextLine, $scale);
            $allLines = $document->lines()->get()->map(static fn (DocumentLine $line): array => [
                'line_total' => $line->line_total,
                'tax_amount' => $line->tax_amount ?? '0',
            ])->all();
            $totals = $this->totals($allLines, $scale);
            $document->update([
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total' => $totals['total'],
            ]);

            return $document->fresh('lines') ?? $document;
        });
    }

    /**
     * @param  list<DraftPurchaseOrderLineData>  $lines
     * @return list<array{product_id: string, variant_id: string|null, location_id: string|null, description: string, quantity: numeric-string, unit_price: numeric-string, line_total: numeric-string, tax_rate: numeric-string, tax_amount: numeric-string}>
     */
    private function prepareLines(
        string $tenantId,
        string $companyId,
        Company $company,
        array $lines,
        int $scale,
    ): array {
        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('id', array_map(static fn (DraftPurchaseOrderLineData $line): string => $line->productId, $lines))
            ->get()
            ->keyBy('id');
        if ($products->count() !== count(array_unique(array_map(
            static fn (DraftPurchaseOrderLineData $line): string => $line->productId,
            $lines,
        )))) {
            throw ValidationException::withMessages(['lines' => 'One or more products are outside the active company.']);
        }

        $payloads = [];
        foreach ($lines as $line) {
            $product = $products->get($line->productId);
            if (! $product instanceof Product) {
                throw ValidationException::withMessages(['lines' => 'Product not found.']);
            }
            $payloads[] = [
                'description' => $product->name,
                'quantity' => $line->quantity,
                'unit_price' => $product->purchase_price ?? '0',
                'product_id' => $product->id,
            ];
        }
        /** @var Collection<array-key, Product> $products */
        $taxResolved = $this->lineTaxResolver->resolve($payloads, $company, $products);

        $prepared = [];
        foreach ($lines as $index => $line) {
            $payload = $taxResolved[$index];
            $unitPrice = CurrencyScale::bcformatStrict((string) $payload['unit_price'], $scale);
            $lineTotal = CurrencyScale::bcformatStrict(
                bcmul($line->quantity, $unitPrice, $scale + 1),
                $scale,
            );
            $taxRate = (string) ($payload['tax_rate'] ?? '0');
            if (! is_numeric($taxRate)) {
                throw ValidationException::withMessages(['lines' => 'Resolved tax rate must be numeric.']);
            }
            $taxAmount = CurrencyScale::bcformatStrict(
                bcmul($lineTotal, bcdiv($taxRate, '100', $scale + 1), $scale + 1),
                $scale,
            );
            $prepared[] = [
                'product_id' => $line->productId,
                'variant_id' => $line->variantId,
                'location_id' => $line->lineLocationId,
                'description' => (string) $payload['description'],
                'quantity' => $line->quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array{product_id: string, variant_id: string|null, location_id: string|null, description: string, quantity: numeric-string, unit_price: numeric-string, line_total: numeric-string, tax_rate: numeric-string, tax_amount: numeric-string}>  $lines
     */
    private function persistLines(Document $document, array $lines, int $startingLineNumber, int $scale): void
    {
        foreach ($lines as $offset => $line) {
            DocumentLine::query()->create([
                'document_id' => $document->id,
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'location_id' => $line['location_id'],
                'line_number' => $startingLineNumber + $offset,
                'description' => $line['description'],
                'designation_default_snapshot' => mb_substr($line['description'], 0, 500),
                'quantity' => $line['quantity'],
                'free_quantity' => '0.0000',
                'unit_price' => $line['unit_price'],
                'discount_percent' => null,
                'discount_amount' => null,
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'tax_recoverable' => true,
                'recoverable_tax_amount' => $line['tax_amount'],
                'non_recoverable_tax_amount' => CurrencyScale::bcformatStrict('0', $scale),
                'line_total' => $line['line_total'],
                'price_entry_mode' => PriceEntryMode::Unit,
                'is_bonus_line' => false,
            ]);
        }
    }

    /**
     * @param  array<int, array{line_total: numeric-string, tax_amount: numeric-string}>  $lines
     * @return array{subtotal: numeric-string, tax: numeric-string, total: numeric-string}
     */
    private function totals(array $lines, int $scale): array
    {
        $subtotal = CurrencyScale::bcformatStrict('0', $scale);
        $tax = CurrencyScale::bcformatStrict('0', $scale);
        foreach ($lines as $line) {
            $subtotal = CurrencyScale::bcformatStrict(bcadd($subtotal, $line['line_total'], $scale + 1), $scale);
            $tax = CurrencyScale::bcformatStrict(bcadd($tax, $line['tax_amount'], $scale + 1), $scale);
        }

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => CurrencyScale::bcformatStrict(bcadd($subtotal, $tax, $scale + 1), $scale),
        ];
    }
}
