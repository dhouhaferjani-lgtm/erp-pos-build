<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\DTOs\UpdateStockAdjustmentData;
use App\Modules\Inventory\Application\Services\StockAdjustmentDocumentService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Presentation\Requests\CancelStockAdjustmentRequest;
use App\Modules\Inventory\Presentation\Requests\PostStockAdjustmentRequest;
use App\Modules\Inventory\Presentation\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Presentation\Requests\UpdateStockAdjustmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `stock_adjustments` HTTP surface (DPA V7 / plan §2).
 *
 * Shape follows StockTransferController: location-scoped `index` through
 * LocationContext::getAllowedLocationIds(), a `Str::isUuid` 404 guard, and a
 * cross-company document treated as 404 rather than 403 (its existence is not
 * this company's business).
 *
 * Typed refusals are NOT mapped here — every one of them has a
 * `$exceptions->render()` entry in bootstrap/app.php registered BEFORE the
 * generic DomainException handler, so the same code and `details` shape reaches
 * the client whichever entry point threw.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentDocumentService $service,
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        $query = StockAdjustment::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['location', 'createdBy', 'postedBy', 'cancelledBy']);

        $allowedLocationIds = $this->locationContext->getAllowedLocationIds($company->id, $user);
        if ($allowedLocationIds !== null) {
            $query->whereIn('location_id', $allowedLocationIds);
        }

        if ($request->filled('status')) {
            $status = StockAdjustmentStatus::tryFrom((string) $request->input('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('location_id')) {
            $locationId = (string) $request->input('location_id');
            if (Str::isUuid($locationId)) {
                $query->where('location_id', $locationId);
            }
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $adjustments = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $adjustments->getCollection()
                ->map(fn (StockAdjustment $adjustment): StockAdjustmentData => StockAdjustmentData::fromModel($adjustment, withLines: false))
                ->all(),
            // The canonical OffsetPaginationMeta shape (@/types/pagination), so
            // the frontend's shared pagination component can consume it without
            // a per-feature envelope.
            'meta' => [
                'current_page' => $adjustments->currentPage(),
                'last_page' => $adjustments->lastPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
                'from' => $adjustments->firstItem(),
                'to' => $adjustments->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, string $adjustment): JsonResponse
    {
        $model = $this->findVisible($request, $adjustment);

        return response()->json(['data' => StockAdjustmentData::fromModel($model)]);
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        $postImmediately = $request->boolean('post_immediately');
        $acknowledgeStale = $request->boolean('acknowledge_stale');
        $ignoreReservations = $request->boolean('ignore_reservations');

        // Two-leg check (D9): `create` and `post` are separately authorized, so
        // posting on create needs BOTH. Enforced here rather than on the route
        // because it is PAYLOAD-dependent.
        if ($postImmediately && ! $user->can('inventory.adjustments.post')) {
            return $this->forbidden('POST_PERMISSION_REQUIRED', 'Posting a stock adjustment requires inventory.adjustments.post.');
        }

        // Supplying an override WITHOUT the posting permission is a 403, never a
        // silent ignore — an integrity override the caller is not entitled to
        // make must not look like it succeeded.
        if (($acknowledgeStale || $ignoreReservations) && ! $user->can('inventory.adjustments.post')) {
            return $this->forbidden('POST_PERMISSION_REQUIRED', 'Overriding a stock-adjustment guard requires inventory.adjustments.post.');
        }

        $idempotencyKey = $request->input('idempotency_key');
        $existing = $idempotencyKey !== null
            ? StockAdjustment::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->where('idempotency_key', (string) $idempotencyKey)
                ->first()
            : null;

        if ($existing !== null) {
            return response()->json(['data' => StockAdjustmentData::fromModel($existing)], Response::HTTP_OK);
        }

        // ONE transaction, so an immediate-post refusal rolls the draft back with
        // it — which is precisely why the refusal payload carries a null line_id
        // on this flow (D15a).
        $adjustment = DB::transaction(function () use ($request, $company, $user, $postImmediately, $acknowledgeStale, $ignoreReservations): StockAdjustment {
            $draft = $this->service->createDraft(new CreateStockAdjustmentData(
                tenantId: $company->tenant_id,
                companyId: $company->id,
                locationId: (string) $request->input('location_id'),
                createdByUserId: $user->id,
                lines: $this->lineInputs($request),
                note: $request->input('note'),
                idempotencyKey: $request->input('idempotency_key'),
            ));

            if (! $postImmediately) {
                return $draft;
            }

            return $this->service->post(
                adjustmentId: $draft->id,
                actorId: $user->id,
                acknowledgeStale: $acknowledgeStale,
                ignoreReservations: $ignoreReservations,
            );
        });

        return response()->json(
            ['data' => StockAdjustmentData::fromModel($adjustment->fresh() ?? $adjustment)],
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateStockAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->findVisible($request, $adjustment);

        $updated = $this->service->updateDraft($model->id, new UpdateStockAdjustmentData(
            note: $request->input('note'),
            lines: $request->has('lines') ? $this->lineInputs($request) : null,
            noteProvided: $request->has('note'),
        ));

        return response()->json(['data' => StockAdjustmentData::fromModel($updated)]);
    }

    public function post(PostStockAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->findVisible($request, $adjustment);
        /** @var User $user */
        $user = $request->user();

        $posted = $this->service->post(
            adjustmentId: $model->id,
            actorId: $user->id,
            acknowledgeStale: $request->boolean('acknowledge_stale'),
            ignoreReservations: $request->boolean('ignore_reservations'),
        );

        return response()->json(['data' => StockAdjustmentData::fromModel($posted)]);
    }

    public function cancel(CancelStockAdjustmentRequest $request, string $adjustment): JsonResponse
    {
        $model = $this->findVisible($request, $adjustment);
        /** @var User $user */
        $user = $request->user();

        $cancelled = $this->service->cancel($model->id, $user->id, $request->input('reason'));

        return response()->json(['data' => StockAdjustmentData::fromModel($cancelled)]);
    }

    public function correct(Request $request, string $adjustment): JsonResponse
    {
        $model = $this->findVisible($request, $adjustment);
        /** @var User $user */
        $user = $request->user();

        $contra = $this->service->correct($model->id, $user->id);

        return response()->json(['data' => StockAdjustmentData::fromModel($contra)], Response::HTTP_CREATED);
    }

    // ------------------------------------------------------------- internals

    /**
     * @return list<StockAdjustmentLineInput>
     */
    private function lineInputs(Request $request): array
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = (array) $request->input('lines', []);

        $lines = [];
        foreach ($raw as $line) {
            /** @var numeric-string $delta */
            $delta = (string) $line['delta_quantity'];
            /** @var numeric-string $observedBefore */
            $observedBefore = (string) $line['observed_before'];

            $lines[] = new StockAdjustmentLineInput(
                productId: (string) $line['product_id'],
                // An explicitly-sent null and a missing key both mean
                // "product-level line", matching StoreStockTransferRequest.
                variantId: array_key_exists('variant_id', $line) && $line['variant_id'] !== null
                    ? (string) $line['variant_id']
                    : null,
                batchUuid: array_key_exists('batch_uuid', $line) && $line['batch_uuid'] !== null
                    ? (string) $line['batch_uuid']
                    : null,
                reasonCode: MovementReason::from((string) $line['reason_code']),
                deltaQuantity: $delta,
                observedBefore: $observedBefore,
                lineNote: isset($line['line_note']) ? (string) $line['line_note'] : null,
            );
        }

        return $lines;
    }

    /**
     * A malformed id is a 404, and a document belonging to another company is a
     * 404 too — 403 would confirm it exists.
     */
    private function findVisible(Request $request, string $adjustmentId): StockAdjustment
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($adjustmentId)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /** @var StockAdjustment $model */
        $model = StockAdjustment::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['location', 'createdBy', 'postedBy', 'cancelledBy', 'lines.product.unitOfMeasure', 'lines.batch'])
            ->findOrFail($adjustmentId);

        $allowed = $this->locationContext->getAllowedLocationIds($company->id, $user);
        if ($allowed !== null && ! in_array($model->location_id, $allowed, true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $model;
    }

    private function forbidden(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], Response::HTTP_FORBIDDEN);
    }
}
