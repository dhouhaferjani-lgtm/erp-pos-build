<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptData;
use App\Modules\Inventory\Application\Services\GoodsReceiptPdfService;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GoodsReceiptService $goodsReceiptService,
        private readonly GoodsReceiptPdfService $goodsReceiptPdfService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = GoodsReceipt::query()
            ->where('tenant_id', $this->companyContext->requireTenantId())
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->with('lines')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $status = $request->query('status');
        if ($status !== null) {
            $statusEnum = GoodsReceiptStatus::tryFrom((string) $status);
            if (! $statusEnum instanceof GoodsReceiptStatus) {
                return response()->json([
                    'error' => [
                        'code' => 'GOODS_RECEIPT_INVALID_STATUS',
                        'message' => 'Invalid goods receipt status.',
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $query->where('status', $statusEnum);
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (GoodsReceipt $receipt): GoodsReceiptData => GoodsReceiptData::fromModel($receipt))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $receipt): JsonResponse
    {
        return response()->json([
            'data' => GoodsReceiptData::fromModel($this->receiptForCurrentCompany($receipt)),
        ]);
    }

    public function pdf(string $receipt): Response|JsonResponse
    {
        $model = $this->receiptForCurrentCompany($receipt);

        if ($model->status !== GoodsReceiptStatus::Posted) {
            return response()->json([
                'error' => [
                    'code' => 'GOODS_RECEIPT_PDF_NOT_POSTED',
                    'message' => 'Only posted goods receipts can be printed.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->goodsReceiptPdfService
            ->generate($model)
            ->download($this->goodsReceiptPdfService->getFilename($model));
    }

    /**
     * GR-IR accrual on this path is fire-and-forget (swallowing listener); a GL
     * failure leaves stock without 408 — detectable via procurement:grir-drift.
     * Auto-PO entry-point flows use the fail-closed path instead (spec §2.2).
     */
    public function post(Request $request, string $receipt): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $posted = $this->goodsReceiptService->post($this->receiptForCurrentCompany($receipt), $user->id);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'GOODS_RECEIPT_POST_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => GoodsReceiptData::fromModel($posted),
        ]);
    }

    public function destroy(string $receipt): Response|JsonResponse
    {
        $model = $this->receiptForCurrentCompany($receipt);

        if ($model->status !== GoodsReceiptStatus::Draft) {
            return response()->json([
                'error' => [
                    'code' => 'GOODS_RECEIPT_DELETE_FAILED',
                    'message' => 'Only draft goods receipts can be deleted.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(static function () use ($model): void {
            $model->lines()->delete();
            $model->delete();
        });

        return response()->noContent();
    }

    private function receiptForCurrentCompany(string $receiptId): GoodsReceipt
    {
        return GoodsReceipt::query()
            ->where('tenant_id', $this->companyContext->requireTenantId())
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->with('lines')
            ->findOrFail($receiptId);
    }
}
