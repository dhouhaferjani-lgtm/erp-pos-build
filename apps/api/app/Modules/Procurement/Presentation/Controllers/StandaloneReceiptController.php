<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptData;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Procurement\Presentation\Requests\CreateStandaloneReceiptRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class StandaloneReceiptController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly StandaloneReceiptService $standaloneReceiptService,
    ) {}

    public function store(CreateStandaloneReceiptRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validated();

        try {
            $result = $this->standaloneReceiptService->execute(new StandaloneReceiptInput(
                companyId: $this->companyContext->requireCompanyId(),
                supplierId: (string) $validated['supplier_id'],
                locationId: (string) $validated['location_id'],
                actorId: $user->id,
                idempotencyKey: (string) $validated['idempotency_key'],
                source: 'standalone_receipt',
                externalReference: isset($validated['external_reference']) ? (string) $validated['external_reference'] : null,
                externalDate: isset($validated['external_date']) ? (string) $validated['external_date'] : null,
                postImmediately: (bool) ($validated['post_immediately'] ?? false),
                lines: $this->lines($validated['lines']),
            ));
        } catch (\DomainException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'STANDALONE_RECEIPT_FAILED',
                    'message' => $exception->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'purchase_order' => DocumentData::fromModel($result->purchaseOrder, true),
                'goods_receipt' => GoodsReceiptData::fromModel($result->receipt),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * @param  array<int, array{
     *   product_id: string,
     *   variant_id?: string|null,
     *   qty: string,
     *   free_qty?: string|null,
     *   unit_price: string,
     *   batch?: array{batch_number: string, expiry_date: string, manufacturing_date?: string}|null
     * }>  $lines
     * @return list<StandaloneReceiptLineInput>
     */
    private function lines(array $lines): array
    {
        return array_values(array_map(
            static fn (array $line): StandaloneReceiptLineInput => new StandaloneReceiptLineInput(
                productId: (string) $line['product_id'],
                variantId: isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                quantity: (string) $line['qty'],
                freeQuantity: (string) ($line['free_qty'] ?? '0.0000'),
                unitPrice: (string) $line['unit_price'],
                batch: $line['batch'] ?? null,
            ),
            $lines,
        ));
    }
}
