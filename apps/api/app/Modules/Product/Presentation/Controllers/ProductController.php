<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\Catalog\Application\DTOs\ProductMediaData;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\DTOs\StockLevelData;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Application\Services\ResetOpeningBalanceService;
use App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException;
use App\Modules\Inventory\Domain\Exceptions\OpeningLockedException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Application\DTOs\OpeningStateData;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Application\Services\CatalogBacklinkDispatcher;
use App\Modules\Product\Application\Services\MarginResolver;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Application\Services\ProductPricingIntentService;
use App\Modules\Product\Application\Services\ProductTombstoneService;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Events\ProductCreated;
use App\Modules\Product\Domain\Events\ProductDeleted;
use App\Modules\Product\Domain\Events\ProductUpdated;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\PostOpeningBalanceRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use App\Modules\Taxation\Domain\Services\TaxResolutionService;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\CatalogMediaQueryInterface;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Domain\QuantityScale;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ProductTombstoneService $tombstoneService,
        private readonly CatalogMediaQueryInterface $catalogMedia,
        private readonly TaxResolutionService $taxResolution,
        private readonly OpeningBalancePostingService $openingPosting,
        private readonly LocationContext $locationContext,
        private readonly InventoryServiceInterface $inventory,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ResetOpeningBalanceService $resetOpening,
        private readonly BatchStockService $batchStockService,
        private readonly ProductPricingIntentService $pricingIntent,
        private readonly MarginService $marginService,
        private readonly MarginResolver $marginResolver,
        private readonly CatalogBacklinkDispatcher $catalogBacklinkDispatcher,
    ) {}

    /**
     * List all products for the current company with sorting, filtering, and aggregates.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();

        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            $this->getAllowedSortColumns(),
            $this->getDefaultSort()['column'],
            $this->getDefaultSort()['direction']
        );

        // Get filter parameters
        $filterConfig = $this->getFilterConfig();
        /** @phpstan-ignore argument.type */
        $filters = $this->getFilterParams($request, $filterConfig);

        // Get per_page parameter (allow up to 2000 for POS systems)
        $perPage = min((int) $request->input('per_page', 25), 2000);

        // Build query with conditional vertical-specific metadata loading
        $with = ['category', 'unitOfMeasure', 'brand'];
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $with[] = 'parapharmacyMetadata.ingredients';
            $with[] = 'parapharmacyMetadata.keyComponents';
            $with[] = 'parapharmacyMetadata.healthClaims';
            $with[] = 'parapharmacyMetadata.certifications';
            $with[] = 'parapharmacyMetadata.skinSuitabilities';
            $with[] = 'parapharmacyMetadata.routines';
            $with[] = 'parapharmacyMetadata.equivalentProducts';
            $with[] = 'parapharmacyMetadata.complementProducts';
        }

        if ($company->tenant->vertical->isAutomotive()) {
            $with[] = 'automotiveMetadata.crossReferences';
            $with[] = 'automotiveMetadata.vehicles';
            $with[] = 'automotiveMetadata.criteria';
        }

        $query = Product::query()
            ->where('company_id', $companyId)
            ->withCount(['activeVariants'])
            ->with($with);

        // Apply filters
        /** @phpstan-ignore argument.type */
        $this->applyFilters($query, $filters, $filterConfig);

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Calculate aggregates (on filtered query, before pagination)
        /** @phpstan-ignore argument.type */
        $aggregates = $this->calculateAggregates($query, $this->getAggregateConfig());

        // Paginate
        $paginator = $query->paginate($perPage);

        // Batch-resolve media for all products on this page in ONE query (no N+1).
        /** @var array<string> $productIds */
        $productIds = collect($paginator->items())->pluck('id')->all();
        /** @var array<string, ProductMediaData> $mediaMap */
        $mediaMap = $this->catalogMedia->forProducts($productIds, $company->tenant_id);

        // Cost/margin confidentiality: redact WAC/margins server-side for callers
        // without pricing.view_cost_prices — the list endpoint must not leak cost
        // data any more than show() does (Rev 3 R3-2 / F2).
        /** @var User $user */
        $user = $request->user();
        $redactCost = ! $user->can('pricing.view_cost_prices');

        // Map each product model to its DTO with media injected.
        /** @var array<int, ProductData> $data */
        $data = array_map(
            function (Product $item) use ($mediaMap, $redactCost): ProductData {
                $dto = ProductData::fromModel(
                    $item,
                    $mediaMap[$item->id] ?? ProductMediaData::makeEmpty(),
                );

                return $redactCost ? $dto->withoutCostFields() : $dto;
            },
            $paginator->items(),
        );

        // Build base response — same outer shape as formatOffsetPaginatedResponse.
        $payload = [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'aggregates' => $aggregates,
        ];

        // Tombstone support: include deleted_ids when an updated_since cursor is provided
        $updatedSince = $request->input('updated_since');
        if (is_string($updatedSince) && $updatedSince !== '') {
            try {
                $cursor = Carbon::parse($updatedSince);
                /** @var array<int, string> $deletedIds */
                $deletedIds = $this->tombstoneService->idsDeletedSince(
                    $company->tenant_id,
                    $companyId,
                    $cursor,
                );
            } catch (\Throwable) {
                return response()->json([
                    'error' => ['message' => 'Invalid updated_since cursor', 'field' => 'updated_since'],
                ], 422);
            }
            $payload['deleted_ids'] = $deletedIds;
        }

        return response()->json($payload);
    }

    /**
     * Get allowed sort columns for products.
     *
     * @return array<string>
     */
    protected function getAllowedSortColumns(): array
    {
        return ['name', 'sku', 'sale_price', 'type', 'created_at'];
    }

    /**
     * Get default sort configuration.
     *
     * @return array{column: string, direction: string}
     */
    protected function getDefaultSort(): array
    {
        return ['column' => 'name', 'direction' => 'asc'];
    }

    /**
     * Get filter configuration for products.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getFilterConfig(): array
    {
        return [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
            'is_physical' => [
                'type' => 'boolean',
                'column' => 'is_physical',
            ],
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
            'category_id' => [
                'type' => 'relationship',
                'column' => 'category_id',
            ],
            'price_min' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '>=',
            ],
            'price_max' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '<=',
            ],
            'search' => [
                'type' => 'text',
                'columns' => ['name', 'sku', 'barcode'],
            ],
            'barcode' => [
                'type' => 'computed',
                'callback' => fn ($q, $value) => $q->where(function ($sub) use ($value) {
                    $exact = (string) $value;
                    $sub->where('barcode', $exact)
                        ->orWhere('sku', $exact);
                }),
            ],
            'has_stock' => [
                'type' => 'computed',
                'callback' => fn ($q) => $q->whereHas('stockLevels', fn ($sq) => $sq->where('quantity', '>', 0)),
            ],
        ];
    }

    /**
     * Get aggregate configuration for products.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getAggregateConfig(): array
    {
        return [
            'total_products' => ['type' => 'count'],
            'total_active' => [
                'type' => 'count',
                'filter' => ['is_active' => true],
            ],
            'average_price' => [
                'type' => 'avg',
                'column' => 'sale_price',
            ],
        ];
    }

    /**
     * Get a single product.
     */
    public function show(Request $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall pattern so
        // the bare-where AST scanner detects the scope, plus tenant_id added
        // for defense-in-depth (products carry both tenant_id + company_id).
        $productModel = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $product)
            ->with(['unitOfMeasure', 'brand', 'category', 'latestEnrichmentResult'])
            ->withSum('stockLevels as stock_quantity', 'quantity')
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Conditionally load vertical-specific metadata
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $productModel->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
                'parapharmacyMetadata.skinSuitabilities',
                'parapharmacyMetadata.routines',
                'parapharmacyMetadata.equivalentProducts',
                'parapharmacyMetadata.complementProducts',
            ]);
        }

        if ($company->tenant->vertical->isAutomotive()) {
            $productModel->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
        }

        $media = $this->catalogMedia->forProduct($productModel->id, $company->tenant_id);

        $data = ProductData::fromModel($productModel, $media, effective: $this->marginResolver->resolve($productModel));

        // Cost/margin confidentiality: redact WAC/margins server-side for callers without
        // pricing.view_cost_prices — the FE panel only hides them in the DOM (Rev 3 R3-2 / F2).
        /** @var User $user */
        $user = $request->user();
        if (! $user->can('pricing.view_cost_prices')) {
            $data->withoutCostFields();
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new product, optionally posting an inline opening balance.
     *
     * The full create body (product row + optional metadata + optional opening balance)
     * executes inside a single DB::transaction so a failed posting rolls back the
     * product as well. ProductCreated is deferred to DB::afterCommit so a rolled-back
     * transaction never leaks the event to downstream listeners.
     */
    public function store(CreateProductRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $validated = $this->resolveUnitId($validated, $tenantId);

        // Extract opening balance fields before mass-assignment so they are not
        // passed to Product::create (the columns do not exist on the products table).
        /** @var string|null $openingQty */
        $openingQty = isset($validated['opening_qty']) ? (string) $validated['opening_qty'] : null;
        /** @var string|null $openingCost */
        $openingCost = isset($validated['opening_unit_cost']) ? (string) $validated['opening_unit_cost'] : null;
        unset($validated['opening_qty'], $validated['opening_unit_cost']);

        // Extract parapharmacy metadata if provided
        $parapharmacyMetadata = null;
        if (array_key_exists('parapharmacy_metadata', $validated)) {
            $parapharmacyMetadata = $validated['parapharmacy_metadata'];
            unset($validated['parapharmacy_metadata']);
        }

        // Extract automotive metadata if provided
        $automotiveMetadata = null;
        if (array_key_exists('automotive_metadata', $validated)) {
            $automotiveMetadata = $validated['automotive_metadata'];
            unset($validated['automotive_metadata']);
        }

        // Campaign defect N-1: the chosen tax configuration is the source of
        // truth for `tax_rate`; it outranks anything the client echoed.
        // Otherwise fall back to category default_tax_rate > company
        // default_tax_rate > '0.00', exactly as before.
        $validated = $this->applyTaxRateFromConfiguration($validated, $company);
        if (($validated['tax_rate'] ?? null) === null) {
            $validated['tax_rate'] = $this->taxResolution->getDefaultTaxForNewProduct(
                $company,
                $validated['category_id'] ?? null,
            );
        }

        // C-2: derive brand_source from the supplied brand_id — never trust the client.
        // isset() returns false when brand_id is absent OR null, so a non-null
        // brand_id unambiguously means the user is assigning a brand. Set BEFORE the
        // creation transaction so it flows into Product::create([...$validated]).
        unset($validated['brand_source']);
        $validated['brand_source'] = isset($validated['brand_id'])
            ? BrandSource::User->value
            : null;

        // Authz gate: posting an opening balance is an inventory-adjustment action.
        // Check BEFORE any write so no partial state is created.
        // is_numeric() doubles as a PHPStan type-guard: narrows string → numeric-string for bccomp.
        if ($openingQty !== null && is_numeric($openingQty) && bccomp($openingQty, '0', 4) > 0) {
            abort_unless($user->can('inventory.adjust'), 403);
        }

        /** @var Product $product */
        $product = DB::transaction(function () use (
            $validated,
            $tenantId,
            $companyId,
            $company,
            $parapharmacyMetadata,
            $automotiveMetadata,
            $openingQty,
            $openingCost,
            $user,
        ): Product {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                // Exclude pricing intent fields from the blind create mass-assign;
                // they are applied authoritatively by the pricing intent seam below.
                ...Arr::except($validated, ['pricing_mode', 'target_margin_override', 'minimum_margin_override', 'sale_price']),
            ]);

            // Re-evaluate pricing fields through the intent seam so that
            // Auto mode, override suppression (equal-to-inherited → null), and
            // minimum_margin_override are applied authoritatively. Runs inside the
            // transaction so sale_price is settled before the deferred ProductCreated
            // event snapshots it.
            $this->pricingIntent->applyIntent($product, $validated);
            $product->save();
            if ($product->pricing_mode === PricingMode::Auto) {
                $this->marginService->updateSalePrice($product);
            }

            // Defer the domain event to after a successful commit so a rolled-back
            // opening balance does not leak ProductCreated to downstream listeners.
            $productSnapshot = $product;
            $tenantSnapshot = $tenantId;
            $companySnapshot = $companyId;
            DB::afterCommit(static function () use ($productSnapshot, $tenantSnapshot, $companySnapshot): void {
                event(new ProductCreated(
                    productId: $productSnapshot->id,
                    tenantId: $tenantSnapshot,
                    companyId: $companySnapshot,
                    name: $productSnapshot->name,
                    sku: $productSnapshot->sku ?? '',
                    type: $productSnapshot->type?->value ?? '',
                    salePrice: (string) $productSnapshot->sale_price,
                    createdAt: $productSnapshot->created_at?->toIso8601String(),
                ));
            });

            // Create parapharmacy metadata if provided AND tenant is Parapharmacy vertical
            if ($parapharmacyMetadata !== null && is_array($parapharmacyMetadata) && $company->tenant->vertical === Vertical::Parapharmacy) {
                $product->parapharmacyMetadata()->create($parapharmacyMetadata);
            }

            // Create automotive metadata if provided AND tenant is automotive vertical
            if ($automotiveMetadata !== null && is_array($automotiveMetadata) && $company->tenant->vertical->isAutomotive()) {
                $crossReferences = $automotiveMetadata['cross_references'] ?? null;
                $vehicles = $automotiveMetadata['vehicles'] ?? null;
                $criteria = $automotiveMetadata['criteria'] ?? null;
                unset($automotiveMetadata['cross_references'], $automotiveMetadata['vehicles'], $automotiveMetadata['criteria']);

                $metadata = $product->automotiveMetadata()->create($automotiveMetadata);

                if (is_array($crossReferences)) {
                    foreach ($crossReferences as $crossRef) {
                        $metadata->crossReferences()->create($crossRef);
                    }
                }

                if (is_array($vehicles)) {
                    foreach ($vehicles as $vehicle) {
                        $metadata->vehicles()->create($vehicle);
                    }
                }

                if (is_array($criteria)) {
                    foreach ($criteria as $criterion) {
                        $metadata->criteria()->create($criterion);
                    }
                }
            }

            // Post opening balance when a positive quantity was supplied.
            // is_numeric() doubles as a PHPStan type-guard: narrows string → numeric-string for bccomp.
            if ($openingQty !== null && is_numeric($openingQty) && bccomp($openingQty, '0', 4) > 0) {
                abort_unless($product->is_physical, 422, __('inventory.opening_requires_physical'));

                $location = $this->locationContext->getDefaultLocation($companyId);
                abort_if($location === null, 422, __('inventory.no_active_location'));

                $this->locationContext->validateLocationAccess($location->id, $companyId, $user);

                // Pass the company currency explicitly — no bare no-arg getScale() per rule 19.
                $scale = $this->scaleResolver->getScale($company->currency);

                $this->openingPosting->post(new OpeningBalancePosting(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    userId: $user->id,
                    entryDate: now(),
                    isHistorical: true,
                    sourceType: 'opening_balance',
                    sourceId: $product->id,
                    reference: 'Opening balance: '.($product->sku ?? $product->id),
                    notes: null,
                    lines: [
                        OpeningBalanceLine::make(
                            $product->id,
                            null,
                            $location->id,
                            $openingQty,
                            $openingCost ?? '0.000',
                            $scale,
                        ),
                    ],
                ));
            }

            return $product;
        });

        $platformVertical = $company->tenant->vertical->platformVertical();
        $platformProductId = $validated['platform_product_id'] ?? null;
        $backlinkBarcode = $validated['barcode'] ?? null;

        if (is_string($platformProductId) && is_string($backlinkBarcode) && $platformVertical !== null) {
            $this->catalogBacklinkDispatcher->linkAndApply(
                productId: $product->id,
                companyId: $companyId,
                tenantId: $tenantId,
                platformProductId: $platformProductId,
                barcode: $backlinkBarcode,
                vertical: $platformVertical,
            );
        }

        // Load metadata for response if Parapharmacy vertical
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $product->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
                'parapharmacyMetadata.skinSuitabilities',
                'parapharmacyMetadata.routines',
                'parapharmacyMetadata.equivalentProducts',
                'parapharmacyMetadata.complementProducts',
            ]);
        }

        // Load metadata for response if automotive vertical
        if ($company->tenant->vertical->isAutomotive()) {
            $product->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
        }

        $product->load(['unitOfMeasure', 'brand']);

        $media = $this->catalogMedia->forProduct($product->id, $tenantId);

        // Compute opening state — queried after the transaction so committed data is visible.
        $opening = OpeningStateData::fromFlags(
            $this->inventory->hasActiveOpening($companyId, $product->id),
            $this->inventory->hasDownstreamMovements($companyId, $product->id),
        );

        return response()->json([
            'data' => ProductData::fromModel($product, $media, $opening, $this->marginResolver->resolve($product)),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Post an opening balance on an existing eligible physical product.
     *
     * Re-entry path: used after a reset, or to add an opening to a product
     * that was created without one. Reuses the same OpeningBalancePostingService
     * as the inline create flow (store()).
     *
     * Guards (in order):
     *   1. product must be physical (422)
     *   2. no downstream non-opening movements (409 — inventory is live)
     *   3. active default location must exist (422)
     *   4. openingPosting->post() → OpeningAlreadyExistsException → 409
     */
    public function postOpening(PostOpeningBalanceRequest $request, string $product): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $model = Product::where('company_id', $companyId)->findOrFail($product);

        abort_unless($model->is_physical, 422, __('inventory.opening_requires_physical'));
        abort_if($this->inventory->hasDownstreamMovements($companyId, $model->id), 409, __('inventory.opening_locked_downstream'));

        /** @var User $user */
        $user = $request->user();

        $location = $this->locationContext->getDefaultLocation($companyId);
        abort_if($location === null, 422, __('inventory.no_active_location'));
        $this->locationContext->validateLocationAccess($location->id, $companyId, $user);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $qty = (string) $validated['opening_qty'];
        $cost = (string) $validated['opening_unit_cost'];

        // Pass the company currency explicitly — no bare no-arg getScale() per rule 19.
        $scale = $this->scaleResolver->getScale($company->currency);

        try {
            $this->openingPosting->post(new OpeningBalancePosting(
                tenantId: $company->tenant_id,
                companyId: $companyId,
                userId: $user->id,
                entryDate: now(),
                isHistorical: true,
                sourceType: 'opening_balance',
                sourceId: $model->id,
                reference: 'Opening balance: '.($model->sku ?? $model->id),
                notes: null,
                lines: [
                    OpeningBalanceLine::make($model->id, null, $location->id, $qty, $cost, $scale),
                ],
            ));
        } catch (OpeningAlreadyExistsException) {
            abort(409, __('inventory.opening_already_exists'));
        }

        $media = $this->catalogMedia->forProduct($model->id, $company->tenant_id);

        $opening = OpeningStateData::fromFlags(
            $this->inventory->hasActiveOpening($companyId, $model->id),
            $this->inventory->hasDownstreamMovements($companyId, $model->id),
        );

        /** @var Product $freshModel */
        $freshModel = $model->fresh();

        return response()->json(['data' => ProductData::fromModel($freshModel, $media, $opening)], 201);
    }

    /**
     * Reset the opening balance for an existing product by posting a reversing
     * movement + contra GL entry (audit-preserving — the original rows are kept).
     *
     * The product is re-enterable via postOpening() after a successful reset.
     *
     * Guards (delegated to ResetOpeningBalanceService):
     *   - An active (non-reversed) opening movement must exist.
     *   - No downstream (non-opening) movements must exist.
     *
     * Returns 200 with the fresh ProductData (opening.can_enter_opening = true).
     * Returns 409 when the guard fails (no active opening or downstream exists).
     */
    public function resetOpening(Request $request, string $product): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $model = Product::where('company_id', $companyId)->findOrFail($product);

        try {
            $this->resetOpening->reset($companyId, $company->tenant_id, $model->id, (string) $request->user()?->id);
        } catch (OpeningLockedException) {
            abort(409, __('inventory.opening_locked_downstream'));
        }

        $media = $this->catalogMedia->forProduct($model->id, $company->tenant_id);

        $opening = OpeningStateData::fromFlags(
            $this->inventory->hasActiveOpening($companyId, $model->id),
            $this->inventory->hasDownstreamMovements($companyId, $model->id),
        );

        /** @var Product $freshModel */
        $freshModel = $model->fresh();

        return response()->json(['data' => ProductData::fromModel($freshModel, $media, $opening)], 200);
    }

    /**
     * Update an existing product.
     */
    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope.
        $productModel = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $product)
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $validated = $this->resolveUnitId($validated, $company->tenant_id);

        // Campaign defect N-1: `::update()` had NO tax handling whatsoever, so
        // a product whose `tax_rate` drifted from its configuration could never
        // self-heal — not even by re-picking the same rate in the form. When a
        // configuration is submitted it now re-derives the denormalised rate;
        // when none is submitted nothing fiscal is touched.
        $validated = $this->applyTaxRateFromConfiguration($validated, $company);

        $wasBatchTracked = $productModel->requires_batch_tracking;

        // Extract parapharmacy metadata if provided
        $parapharmacyMetadata = null;
        if (array_key_exists('parapharmacy_metadata', $validated)) {
            $parapharmacyMetadata = $validated['parapharmacy_metadata'];
            unset($validated['parapharmacy_metadata']);
        }

        // Extract automotive metadata if provided
        $automotiveMetadata = null;
        if (array_key_exists('automotive_metadata', $validated)) {
            $automotiveMetadata = $validated['automotive_metadata'];
            unset($validated['automotive_metadata']);
        }

        // C-2: derive brand_source from the supplied brand_id — never trust the client.
        if (array_key_exists('brand_id', $validated)) {
            unset($validated['brand_source']);
            if ($validated['brand_id'] !== null) {
                $validated['brand_source'] = BrandSource::User->value;
            } else {
                $validated['brand_source'] = null;
            }
        }

        $requiresBatchTracking = array_key_exists('requires_batch_tracking', $validated)
            ? (bool) $validated['requires_batch_tracking']
            : (bool) $wasBatchTracked;

        // Update product core fields. Exclude pricing intent fields from blind
        // mass-assign; they are handled authoritatively by ProductPricingIntentService
        // after the base update.
        $base = Arr::except($validated, ['pricing_mode', 'target_margin_override', 'minimum_margin_override', 'sale_price']);
        $productModel->update($base);

        if (! $wasBatchTracked && $requiresBatchTracking) {
            $this->backfillDefaultBatchesForExistingStock($productModel, $company->tenant_id);
        }

        $changes = $productModel->getChanges();
        unset($changes['updated_at']);

        // Route pricing fields through the intent seam (separate save so that
        // override suppression and Auto reprice fire after the base update).
        $this->pricingIntent->applyIntent($productModel, $validated);
        $productModel->save();
        $pricingChanged = $productModel->wasChanged();
        if ($productModel->pricing_mode === PricingMode::Auto) {
            $pricingChanged = $this->marginService->updateSalePrice($productModel) || $pricingChanged;
        }

        // Emit once — whether the base fields changed OR the pricing flow changed.
        if ($changes !== [] || $pricingChanged) {
            event(new ProductUpdated(
                productId: $productModel->id,
                tenantId: $productModel->tenant_id,
                companyId: $productModel->company_id,
                changes: $changes,
                updatedAt: $productModel->updated_at?->toIso8601String(),
            ));
        }

        // Update or create parapharmacy metadata if provided AND tenant is Parapharmacy vertical
        if ($parapharmacyMetadata !== null && is_array($parapharmacyMetadata) && $company->tenant->vertical === Vertical::Parapharmacy) {
            $productModel->parapharmacyMetadata()->updateOrCreate(
                ['product_id' => $productModel->id],
                $parapharmacyMetadata
            );
        }

        // Update or create automotive metadata if provided AND tenant is automotive vertical
        if ($automotiveMetadata !== null && is_array($automotiveMetadata) && $company->tenant->vertical->isAutomotive()) {
            $crossReferences = $automotiveMetadata['cross_references'] ?? null;
            $vehicles = $automotiveMetadata['vehicles'] ?? null;
            $criteria = $automotiveMetadata['criteria'] ?? null;
            unset($automotiveMetadata['cross_references'], $automotiveMetadata['vehicles'], $automotiveMetadata['criteria']);

            $metadata = $productModel->automotiveMetadata()->updateOrCreate(
                ['product_id' => $productModel->id],
                $automotiveMetadata
            );

            // Sync cross-references (replace all)
            if (is_array($crossReferences)) {
                $metadata->crossReferences()->delete();
                foreach ($crossReferences as $crossRef) {
                    $metadata->crossReferences()->create($crossRef);
                }
            }

            // Sync vehicles (replace all)
            if (is_array($vehicles)) {
                $metadata->vehicles()->delete();
                foreach ($vehicles as $vehicle) {
                    $metadata->vehicles()->create($vehicle);
                }
            }

            // Sync criteria (replace all)
            if (is_array($criteria)) {
                $metadata->criteria()->delete();
                foreach ($criteria as $criterion) {
                    $metadata->criteria()->create($criterion);
                }
            }
        }

        /** @var Product $freshProduct */
        $freshProduct = $productModel->fresh();

        // Load metadata for response if Parapharmacy vertical
        if ($company->tenant->vertical === Vertical::Parapharmacy) {
            $freshProduct->load([
                'parapharmacyMetadata.ingredients',
                'parapharmacyMetadata.keyComponents',
                'parapharmacyMetadata.healthClaims',
                'parapharmacyMetadata.certifications',
                'parapharmacyMetadata.skinSuitabilities',
                'parapharmacyMetadata.routines',
                'parapharmacyMetadata.equivalentProducts',
                'parapharmacyMetadata.complementProducts',
            ]);
        }

        // Load metadata for response if automotive vertical
        if ($company->tenant->vertical->isAutomotive()) {
            $freshProduct->load([
                'automotiveMetadata.crossReferences',
                'automotiveMetadata.vehicles',
                'automotiveMetadata.criteria',
            ]);
        }

        $freshProduct->load(['unitOfMeasure', 'brand']);

        $media = $this->catalogMedia->forProduct($freshProduct->id, $company->tenant_id);

        return response()->json([
            'data' => ProductData::fromModel($freshProduct, $media, effective: $this->marginResolver->resolve($freshProduct)),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    private function backfillDefaultBatchesForExistingStock(Product $product, string $tenantId): void
    {
        StockLevel::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderBy('location_id')
            ->get()
            ->each(function (StockLevel $stockLevel) use ($product, $tenantId): void {
                // 🚨 Campaign W2-7 — the backfill used to seed the DEFAULT lot with
                // the FULL aggregate quantity. A product flipped to batch-tracked may
                // already hold real dated lots (they exist independently of the flag),
                // and those units are already lot-represented: the DEFAULT lot may
                // only carry the untracked remainder.
                $this->batchStockService->ensureDefaultBatchForUntrackedRemainder(
                    companyId: $product->company_id,
                    tenantId: $tenantId,
                    productId: $product->id,
                    locationId: $stockLevel->location_id,
                    aggregateQuantity: (string) $stockLevel->quantity,
                    shelfLifeDays: $product->default_shelf_life_days,
                    asOfDate: now()->toDateString(),
                    variantId: $stockLevel->variant_id,
                );
            });
    }

    /**
     * Delete a product (soft delete).
     */
    public function destroy(Request $request, string $product): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->companyContext->requireCompany();

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope.
        $productModel = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $product)
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $productModel->delete();

        event(new ProductDeleted(
            productId: $productModel->id,
            tenantId: $productModel->tenant_id,
            companyId: $productModel->company_id,
            deletedAt: now()->toIso8601String(),
        ));

        return response()->json(null, 204);
    }

    /**
     * Get stock levels for a product across all locations.
     */
    public function stockLevels(Request $request, string $product): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        // api.catalog round-2 (Codex Finding 1): chained MethodCall + tenant scope.
        $productModel = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('id', $product)
            ->with('unitOfMeasure')
            ->first();

        if (! $productModel) {
            return response()->json([
                'error' => [
                    'code' => 'PRODUCT_NOT_FOUND',
                    'message' => 'Product not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Variant-aware callers (e.g. the stock-transfer availability cell) pass
        // ?variant_id=... so the per-location availability reflects the CHOSEN
        // variant — without it a variant product returns one row per variant per
        // location and the client's location lookup would surface an arbitrary
        // variant's quantity. Omitted = product-wide (legacy) behaviour.
        $variantId = $request->query('variant_id');
        $variantFilter = is_string($variantId) && Str::isUuid($variantId) ? $variantId : null;

        // stock_levels carries tenant_id + company_id; lead with both.
        $stockLevels = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('product_id', $productModel->id)
            ->when($variantFilter !== null, fn ($q) => $q->where('variant_id', $variantFilter))
            ->with(['location', 'product.unitOfMeasure'])
            ->get();

        // Calculate incoming stock from confirmed purchase orders
        $incomingByLocation = DB::table('document_lines')
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->where('documents.type', DocumentType::PurchaseOrder)
            ->where('documents.status', DocumentStatus::Confirmed)
            ->where('documents.company_id', $companyId)
            ->where('document_lines.product_id', $productModel->id)
            ->whereRaw('document_lines.quantity > COALESCE(document_lines.quantity_received, 0)')
            ->selectRaw('
                document_lines.location_id,
                SUM(document_lines.quantity - COALESCE(document_lines.quantity_received, 0)) as incoming
            ')
            ->groupBy('document_lines.location_id')
            ->get()
            ->keyBy('location_id');

        // Map stock levels with incoming data
        $data = $stockLevels->map(function (StockLevel $level) use ($incomingByLocation) {
            $incoming = $incomingByLocation->get($level->location_id)->incoming ?? '0.00';

            return StockLevelData::fromModel($level, (string) $incoming);
        });

        // Aggregate canonical quantity strings without allowing Collection::sum
        // to coerce them through PHP numeric types.
        $zeroQuantity = QuantityScale::formatForUnit('0', null);
        /** @var numeric-string $totalQuantity */
        $totalQuantity = $zeroQuantity;
        /** @var numeric-string $totalReserved */
        $totalReserved = $zeroQuantity;
        foreach ($stockLevels as $level) {
            /** @var numeric-string $quantity */
            $quantity = (string) $level->quantity;
            /** @var numeric-string $reserved */
            $reserved = (string) $level->reserved;
            $totalQuantity = bcadd($totalQuantity, $quantity, QuantityScale::SCALE);
            $totalReserved = bcadd($totalReserved, $reserved, QuantityScale::SCALE);
        }
        $totalAvailable = bcsub($totalQuantity, $totalReserved, QuantityScale::SCALE);

        /** @var numeric-string $totalIncoming */
        $totalIncoming = $zeroQuantity;
        foreach ($incomingByLocation as $incomingRow) {
            /** @var numeric-string $incoming */
            $incoming = (string) $incomingRow->incoming;
            $totalIncoming = bcadd($totalIncoming, $incoming, QuantityScale::SCALE);
        }
        $totalProjectedAvailable = bcadd($totalAvailable, $totalIncoming, QuantityScale::SCALE);
        $quantityDecimals = $productModel->unitOfMeasure->decimal_places ?? QuantityScale::SCALE;

        return response()->json([
            'data' => [
                'locations' => $data,
                'totals' => [
                    'quantity' => (string) $totalQuantity,
                    'reserved' => (string) $totalReserved,
                    'available' => $totalAvailable,
                    'incoming' => (string) $totalIncoming,
                    'projected_available' => $totalProjectedAvailable,
                    'quantity_decimals' => $quantityDecimals,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Campaign defect N-1 — make the chosen tax configuration authoritative
     * over the denormalised `products.tax_rate`, on BOTH create and update.
     *
     * WHY THE COLUMN CANNOT BE LEFT STALE. `products.tax_rate` is what
     * `ReceiptCreationService` reads for every POS line (unconditionally —
     * `StoreReceiptRequest` has no `lines.*.tax_rate` rule, so a till cannot
     * correct it) and what `DocumentLineTaxResolver` falls back to. Before this
     * lane the create path derived it from `categories`/`companies` defaults
     * only and the update path never touched it at all, so a Tunisian
     * parapharmacy that picked "TVA 7 %" sold at 19 % and sealed 19 % into the
     * fiscal hash chain, with the correct rate shown on screen throughout.
     *
     * THE THREE CASES, and why the third does nothing:
     *  - KEY ABSENT (`sometimes` on update, omitted on create): the caller made
     *    no statement about the configuration. Leave `tax_rate` exactly as the
     *    caller left it — the create path's category/company ladder still runs
     *    below, the update path leaves the stored value alone. This is what
     *    keeps "PATCH the name" from re-pricing a product.
     *  - KEY PRESENT AND NON-EMPTY: derive and OVERWRITE, discarding any
     *    `tax_rate` the client sent. The frontend already omits `tax_rate` from
     *    the product payload for this reason; a client that sends both is
     *    stating two things at once and the configuration is the one the
     *    operator actually clicked.
     *  - KEY PRESENT AND NULL: the operator is DETACHING the configuration,
     *    which says nothing about the rate. An explicit `tax_rate` in the same
     *    request is honoured (it falls through untouched); with no rate, the
     *    last derived number stands. Substituting the company default here
     *    would silently re-price the product — the same failure mode as N-1,
     *    pointed the other way.
     *
     * A configuration that cannot state a line-item percentage is REFUSED, not
     * silently defaulted: {@see TaxResolutionService::resolveRateFromTaxConfiguration()}
     * returns null for a fixed-amount or `DOCUMENT_TOTAL` configuration, and
     * inventing a rate at that point is exactly how the wrong number got
     * chained in the first place. A wrong-COUNTRY id never reaches here — the
     * Create/Update requests refuse it 422 via `TaxConfigurationCountryCoherent`.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyTaxRateFromConfiguration(array $validated, Company $company): array
    {
        if (! array_key_exists('default_tax_configuration_id', $validated)) {
            return $validated;
        }

        $configurationId = $validated['default_tax_configuration_id'];

        if (! is_string($configurationId) || trim($configurationId) === '') {
            return $validated;
        }

        $rate = $this->taxResolution->resolveRateFromTaxConfiguration($company, $configurationId);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'default_tax_configuration_id' => [
                    __('The selected tax configuration does not define a line-item percentage rate.'),
                ],
            ]);
        }

        $validated['tax_rate'] = $rate;

        return $validated;
    }

    /**
     * Resolve the unit-of-measure FK from the free-text `unit` when the caller
     * did not pass a `unit_id`, so quantity precision (decimals/step) applies to
     * products created or edited via the API — not just backfilled/seeded ones.
     * Matches code/symbol/name case-insensitively across the tenant's units and
     * shared system units; only a unique match is applied.
     *
     * FORWARD direction (unit_id → unit): when unit_id is present, look up the
     * Unit and overwrite the `unit` string with its `code`. This ensures
     * getSellableUnit() (which returns `$this->unit`) always reflects the
     * canonical unit code that the editor selected via the FK.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveUnitId(array $validated, ?string $tenantId): array
    {
        if (! empty($validated['unit_id'])) {
            // FORWARD mirror: unit_id is set — resolve the Unit and overwrite `unit`
            // with its code so getSellableUnit() stays in sync.
            $unitModel = Unit::query()
                ->where('id', $validated['unit_id'])
                ->where(function ($query) use ($tenantId): void {
                    $query->whereNull('tenant_id');
                    if ($tenantId !== null) {
                        $query->orWhere('tenant_id', $tenantId);
                    }
                })
                ->first();

            if ($unitModel instanceof Unit) {
                $validated['unit'] = $unitModel->code;
            }

            return $validated;
        }

        // REVERSE direction: unit_id is absent — attempt to resolve it from
        // the free-text `unit` string (legacy path, no regression).
        $unit = $validated['unit'] ?? null;
        if (! is_string($unit) || trim($unit) === '') {
            return $validated;
        }

        $needle = mb_strtolower(trim($unit));

        $matches = Unit::query()
            ->where(function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->get()
            ->filter(fn (Unit $candidate): bool => in_array($needle, [
                mb_strtolower((string) $candidate->code),
                mb_strtolower((string) $candidate->symbol),
                mb_strtolower((string) $candidate->name),
            ], true));

        $match = $matches->count() === 1 ? $matches->first() : null;
        if ($match instanceof Unit) {
            $validated['unit_id'] = $match->id;
        }

        return $validated;
    }
}
