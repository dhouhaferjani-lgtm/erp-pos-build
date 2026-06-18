<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Application\Services\VariantLabelBarcodeRenderer;
use App\Modules\Catalog\Application\Services\VariantLabelPdfService;
use App\Modules\Catalog\Application\Services\VariantLabelService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Support\LabelSheetFormat;
use App\Modules\Catalog\Presentation\Requests\GenerateLabelPdfRequest;
use App\Modules\Catalog\Presentation\Requests\PrepareLabelsRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Pricing\Domain\Services\PricingService;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * REST surface for variant label printing.
 *
 * `prepare` resolves a batch of variant ids + quantities into ready-to-print
 * label descriptors (assigning sku-as-barcode where missing) plus a list of
 * skipped items, scoped to the current company.
 */
class VariantLabelController extends Controller
{
    public function __construct(
        private readonly VariantLabelService $labelService,
        private readonly CompanyContext $companyContext,
        private readonly VariantLabelPdfService $pdfService,
        private readonly VariantLabelBarcodeRenderer $renderer,
        private readonly PricingService $pricingService,
    ) {}

    /**
     * List the supported label sheet/roll layouts the print flow can target.
     */
    public function formats(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (LabelSheetFormat $f): array => $f->toArray(),
                LabelSheetFormat::all(),
            ),
        ]);
    }

    public function prepare(PrepareLabelsRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        /** @var array<int, array{variant_id: string, quantity: int}> $rawItems */
        $rawItems = $request->validated('items');

        $items = array_map(
            static fn (array $item): array => [
                'variantId' => $item['variant_id'],
                'quantity' => (int) $item['quantity'],
            ],
            $rawItems,
        );

        $result = $this->labelService->prepare($company, $items);

        return response()->json([
            'data' => [
                'ready' => collect($result['ready'])
                    ->map(static fn (VariantLabelData $d): array => [
                        'variant_id' => $d->variant_id,
                        'quantity' => $d->quantity,
                        'barcode_value' => $d->barcode_value,
                        'symbology' => $d->symbology,
                    ])
                    ->values(),
            ],
            'meta' => [
                'skipped' => $result['skipped'],
            ],
        ]);
    }

    /**
     * Render a print-ready label sheet PDF for a batch of variants.
     *
     * Read-only: unlike `prepare`, this never assigns a barcode — a variant
     * without one is rejected (422) so the caller runs `prepare` first.
     */
    public function pdf(GenerateLabelPdfRequest $request): Response
    {
        $company = $this->companyContext->requireCompany();

        $formatKey = (string) $request->validated('format');
        $startCell = (int) $request->validated('start_cell', 0);

        /** @var array<int, array{variant_id: string, quantity: int}> $rawItems */
        $rawItems = $request->validated('items');

        $labels = [];

        foreach ($rawItems as $item) {
            $variant = ProductVariant::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->find($item['variant_id']);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'items' => 'A requested variant was not found in this company.',
                ]);
            }

            if ($variant->barcode === null || $variant->barcode === '') {
                throw ValidationException::withMessages([
                    'items' => 'A requested variant has no barcode. Prepare the labels first.',
                ]);
            }

            // The parent product may be soft-deleted while the variant row survives.
            // Fetch it nullable + tenant-scoped (so a trashed product yields null) and
            // guard before dereferencing ->name (Codex H-2).
            $product = Product::query()
                ->where('tenant_id', $company->tenant_id)
                ->find($variant->product_id);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'items' => 'A requested variant belongs to an unavailable product.',
                ]);
            }

            $barcodeValue = (string) $variant->barcode;

            // A pre-existing barcode that resolves to a product code (Spec A Tier 1)
            // mis-scans to that product — reject before printing (B1).
            if ($this->labelService->collidesWithProductCode($company->tenant_id, $barcodeValue)) {
                throw ValidationException::withMessages([
                    'items' => "A requested variant's barcode collides with a product code; fix it before printing.",
                ]);
            }

            // A non-ASCII (un-encodable) barcode would render a corrupt, unscannable
            // symbol — reject rather than emit garbage (H1).
            if (! $this->renderer->canEncode($barcodeValue)) {
                throw ValidationException::withMessages([
                    'items' => 'A requested variant barcode cannot be encoded as a scannable barcode.',
                ]);
            }

            $price = $this->pricingService->getPrice(
                productId: $variant->product_id,
                partnerId: null,
                quantity: '1.00',
                currency: $company->currency,
                date: null,
                variantId: $variant->id,
            )['price'];

            $labels[] = new VariantLabelData(
                variant_id: $variant->id,
                product_name: $product->name,
                name_suffix: $variant->name_suffix,
                effective_price: $price,
                barcode_value: $barcodeValue,
                symbology: $this->renderer->symbologyFor($barcodeValue),
                quantity: (int) $item['quantity'],
                sku: $variant->sku,
            );
        }

        $pdf = $this->pdfService->render($formatKey, $labels, $startCell, $company->name);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="variant-labels.pdf"',
        ]);
    }
}
