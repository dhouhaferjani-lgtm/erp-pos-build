<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Application\Services\VariantLabelService;
use App\Modules\Catalog\Domain\Support\LabelSheetFormat;
use App\Modules\Catalog\Presentation\Requests\PrepareLabelsRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

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
}
