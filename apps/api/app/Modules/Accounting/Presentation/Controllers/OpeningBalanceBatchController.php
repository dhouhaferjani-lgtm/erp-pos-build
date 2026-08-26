<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Exceptions\OpeningCashNotFullySeededException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Presentation\Concerns\RequiresCompanyAccess;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
use App\Modules\Treasury\Domain\Exceptions\RepositoryAlreadySeededException;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Controller for managing Opening Balance Batches.
 *
 * These endpoints allow users to:
 * - Create and manage opening balance import batches
 * - View batch details and statistics
 * - Lock batches to make them immutable
 */
class OpeningBalanceBatchController extends Controller
{
    use RequiresCompanyAccess;

    public function __construct(
        private readonly OpeningBalanceBatchService $batchService,
        private readonly AccountingOpeningService $accountingOpeningService,
        private readonly InventoryOpeningService $inventoryOpeningService,
        private readonly ArApOpeningService $arApOpeningService
    ) {}

    /**
     * GET /api/v1/companies/{companyId}/opening-batches
     *
     * List all opening balance batches for a company.
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batches = $this->batchService->getBatchesForCompany($companyId);

        return response()->json([
            'data' => $batches->map(fn (OpeningBalanceBatch $batch): array => $this->formatBatch($batch)),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'total' => $batches->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/companies/{companyId}/opening-batches/{batchId}
     *
     * Get details of a specific batch.
     */
    public function show(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatchWithRows($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        $stats = $this->batchService->getBatchStats($batch);

        return response()->json([
            'data' => array_merge($this->formatBatch($batch), [
                'statistics' => $stats,
                'creator' => $batch->creator ? [ // @phpstan-ignore-line (defensive null check)
                    'id' => $batch->creator->id,
                    'name' => $batch->creator->name,
                ] : null,
                'validator' => $batch->validator ? [
                    'id' => $batch->validator->id,
                    'name' => $batch->validator->name,
                ] : null,
                'locker' => $batch->locker ? [
                    'id' => $batch->locker->id,
                    'name' => $batch->locker->name,
                ] : null,
            ]),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/companies/{companyId}/opening-batches
     *
     * Create a new opening balance batch.
     */
    public function store(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                'in:'.implode(',', array_column(OpeningBatchType::cases(), 'value')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'cutover_date' => ['required', 'date'],
            'source_system' => ['nullable', 'string', 'max:100'],
        ]);

        $company = Company::findOrFail($companyId);
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'User not authenticated',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 401);
        }

        try {
            $batch = $this->batchService->createBatch(
                company: $company,
                type: OpeningBatchType::from($validated['type']),
                cutoverDate: Carbon::parse($validated['cutover_date']),
                name: $validated['name'],
                userId: (string) $user->id,
                sourceSystem: $validated['source_system'] ?? null
            );

            return response()->json([
                'data' => $this->formatBatch($batch),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_CREATION_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * DELETE /api/v1/companies/{companyId}/opening-batches/{batchId}
     *
     * Delete a draft batch.
     */
    public function destroy(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        try {
            $batch = $this->batchService->getBatch($batchId);

            if ($batch->company_id !== $companyId) {
                return response()->json([
                    'error' => [
                        'code' => 'BATCH_NOT_FOUND',
                        'message' => 'Opening balance batch not found',
                    ],
                    'meta' => ['timestamp' => now()->toIso8601String()],
                ], 404);
            }

            $this->batchService->deleteBatch($batch);

            return response()->json([
                'data' => [
                    'message' => 'Batch deleted successfully',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_DELETE_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * GET /api/v1/companies/{companyId}/opening-batches/{batchId}/rows
     *
     * Get import rows for a batch (paginated).
     */
    public function rows(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        $perPage = (int) $request->input('per_page', 50);
        $rows = $this->batchService->getImportRowsPaginated($batch, min($perPage, 100));

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/companies/{companyId}/opening-batches/{batchId}/lock
     *
     * Lock a validated batch (makes it immutable).
     */
    public function lock(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'User not authenticated',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 401);
        }

        try {
            $this->batchService->lockBatch($batch, (string) $user->id);

            $batch->refresh();

            return response()->json([
                'data' => array_merge($this->formatBatch($batch), [
                    'message' => 'Batch locked successfully',
                ]),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_LOCK_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * GET /api/v1/companies/{companyId}/opening-batches/status
     *
     * Get opening balance status for all types.
     */
    public function status(Request $request, string $companyId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $types = OpeningBatchType::cases();
        $status = [];

        foreach ($types as $type) {
            $batch = OpeningBalanceBatch::forCompany($companyId)
                ->ofType($type)
                ->orderBy('created_at', 'desc')
                ->first();

            $status[$type->value] = [
                'type' => $type->value,
                'label' => $type->label(),
                'has_batch' => $batch !== null,
                'batch' => $batch ? $this->formatBatch($batch) : null,
                'is_locked' => $batch?->isLocked() ?? false,
                'is_ready' => $batch === null || $batch->isLocked(),
            ];
        }

        return response()->json([
            'data' => [
                'types' => $status,
                'all_ready' => collect($status)->every(fn (array $s): bool => $s['is_ready']),
                'inventory_ready' => $this->batchService->isInventoryOpeningReady($companyId),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/opening-batches/types
     *
     * Get list of available batch types.
     */
    #[CrossTenantRoute(reason: 'Static enum endpoint: returns the OpeningBatchType enum cases (value/label/row_type/affects_gl) for UI dropdown population. No DB access; enum values are platform-level constants. The other methods on this controller use $this->assertCompanyAccess($request, $companyId) for tenant binding via the route\'s {companyId} parameter.')]
    public function types(): JsonResponse
    {
        $types = array_map(
            fn (OpeningBatchType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'row_type' => $type->rowType(),
                'affects_gl' => $type->affectsGL(),
            ],
            OpeningBatchType::cases()
        );

        return response()->json([
            'data' => $types,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/companies/{companyId}/opening-batches/{batchId}/import
     *
     * Import CSV data into a batch.
     */
    public function import(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        // Validation rules depend on batch type
        $baseRules = [
            'rows' => ['required', 'array', 'min:1'],
        ];

        // Add type-specific validation rules
        $typeRules = match ($batch->type) {
            OpeningBatchType::Accounting => [
                'rows.*.account_code' => ['required', 'string'],
                'rows.*.debit' => ['nullable', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'rows.*.credit' => ['nullable', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'rows.*.description' => ['nullable', 'string', 'max:255'],
                // W4-2 — optional; names the payment repository whose day-one
                // cash float this line seeds. AccountingOpeningService validates
                // existence, the debit-only rule, the GL-account match and the
                // never-traded rule per row; this is ingress shape only.
                'rows.*.repository_code' => ['nullable', 'string', 'max:50'],
            ],
            OpeningBatchType::Inventory => [
                'rows.*.product_code' => ['required', 'string'],
                'rows.*.location_code' => ['required', 'string'],
                'rows.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^-?\d+(\.\d{1,4})?$/'],
                'rows.*.unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                // W4-1 — optional; the expiry of the opening lot this row seeds.
                // Blank means "not supplied": the lot takes the product's configured
                // shelf life if it has one, and is otherwise minted UNDATED so FEFO
                // ranks it after every dated lot.
                //
                // `date_format` rather than `date` so a locale-ambiguous cell
                // (03/04/2027 — 3 April or 4 March?) is REFUSED here instead of
                // silently parsed as the wrong day. `InventoryOpeningService::
                // validateRow()` re-checks the same shape per row and reports
                // `expiry_date` as a row error.
                //
                // A PAST date is deliberately NOT refused, here or downstream
                // (gate r1 ruling): a parapharmacy may legitimately open with
                // expired stock in order to scrap it. It is surfaced instead —
                // `getPostPreview()` marks the line `expiry_is_past` so the
                // operator sees it BEFORE posting, and the Products import raises
                // the non-blocking `expiry_in_past` row warning.
                'rows.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            ],
            OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => [
                'rows.*.partner_code' => ['required', 'string'],
                'rows.*.document_date' => ['required', 'date'],
                'rows.*.due_date' => ['required', 'date'],
                'rows.*.total' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'rows.*.open_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'rows.*.external_invoice_number' => ['nullable', 'string', 'max:100'],
                'rows.*.document_type' => ['nullable', 'string', 'in:invoice,credit_note,inv,cn'],
                'rows.*.currency' => ['nullable', 'string', 'size:3'],
                'rows.*.notes' => ['nullable', 'string', 'max:500'],
            ],
        };

        $validated = $request->validate(array_merge($baseRules, $typeRules), [
            'rows.*.debit.regex' => 'Debit amount must have at most 3 decimal places.',
            'rows.*.credit.regex' => 'Credit amount must have at most 3 decimal places.',
            'rows.*.quantity.regex' => 'Quantity must have at most 4 decimal places.',
            'rows.*.unit_cost.regex' => 'Unit cost must have at most 3 decimal places.',
            'rows.*.total.regex' => 'Total must have at most 3 decimal places.',
            'rows.*.open_amount.regex' => 'Open amount must have at most 3 decimal places.',
        ]);

        try {
            $this->batchService->addImportRows($batch, $validated['rows']);
            $batch->refresh();

            return response()->json([
                'data' => [
                    'message' => 'Rows imported successfully',
                    'row_count' => count($validated['rows']),
                    'batch' => $this->formatBatch($batch),
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * POST /api/v1/companies/{companyId}/opening-batches/{batchId}/validate
     *
     * Validate all rows in a batch.
     */
    public function validateBatch(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        try {
            // Route to appropriate service based on batch type
            $result = match ($batch->type) {
                OpeningBatchType::Accounting => $this->accountingOpeningService->validateBatch($batch),
                OpeningBatchType::Inventory => $this->inventoryOpeningService->validateBatch($batch),
                OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => $this->arApOpeningService->validateBatch($batch),
            };

            return response()->json([
                'data' => $result,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * GET /api/v1/companies/{companyId}/opening-batches/{batchId}/preview
     *
     * Preview what will be posted from a batch.
     */
    public function preview(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        try {
            // Route to appropriate service based on batch type
            $preview = match ($batch->type) {
                OpeningBatchType::Accounting => $this->accountingOpeningService->getPostPreview($batch),
                OpeningBatchType::Inventory => $this->inventoryOpeningService->getPostPreview($batch),
                OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => $this->arApOpeningService->getPostPreview($batch),
            };

            return response()->json([
                'data' => $preview,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'PREVIEW_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * POST /api/v1/companies/{companyId}/opening-batches/{batchId}/post
     *
     * Post a validated batch to the ledger.
     */
    public function post(Request $request, string $companyId, string $batchId): JsonResponse
    {
        $this->assertCompanyAccess($request, $companyId);

        $batch = $this->batchService->getBatch($batchId);

        if ($batch->company_id !== $companyId) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Opening balance batch not found',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 404);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'User not authenticated',
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 401);
        }

        try {
            // Route to appropriate service based on batch type
            // Note: Accounting/Inventory return JournalEntry, AR/AP return array
            $result = match ($batch->type) {
                OpeningBatchType::Accounting => $this->accountingOpeningService->postBatch($batch, (string) $user->id),
                OpeningBatchType::Inventory => $this->inventoryOpeningService->postBatch($batch, (string) $user->id),
                OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => $this->arApOpeningService->postBatch($batch, (string) $user->id),
            };

            $batch->refresh();

            // Build response based on batch type
            $responseData = [
                'message' => 'Batch posted successfully',
                'batch' => $this->formatBatch($batch),
            ];

            // W4-1 gate r1 OPEN-2 — an INVENTORY batch names any row whose supplied
            // expiry did not end up on the lot. The preview showed the operator that
            // date; if the post could not honour it, the post has to say so.
            if ($batch->type === OpeningBatchType::Inventory) {
                $expiryNotices = $this->inventoryOpeningService->expiryNoticesFor($batch);

                if ($expiryNotices !== []) {
                    $responseData['expiry_notices'] = $expiryNotices;
                }
            }

            if ($result instanceof JournalEntry) {
                // Accounting/Inventory: include journal entry info
                $responseData['journal_entry'] = [
                    'id' => $result->id,
                    'entry_number' => $result->entry_number,
                    'entry_date' => $result->entry_date->toDateString(),
                    'line_count' => $result->lines->count(),
                ];
            } else {
                // AR/AP: include documents summary
                $responseData['documents'] = [
                    'documents_created' => $result['documents_created'] ?? 0,
                    'total_amount' => $result['total_amount'] ?? '0.00',
                    'total_open_amount' => $result['total_open_amount'] ?? '0.00',
                ];
            }

            return response()->json([
                'data' => $responseData,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (RepositoryAlreadySeededException $e) {
            // gate r1 F-5 — this used to fall through to the global handler and
            // reach the operator as `BUSINESS_ERROR` plus raw server English,
            // while the sibling W4-10 refusal got a typed code and en/fr/ar.
            // Same lane, same operator: same treatment.
            return response()->json([
                'error' => [
                    'code' => RepositoryAlreadySeededException::ERROR_CODE,
                    'message' => __('messages.treasury.repository_already_seeded', [
                        'repository' => $e->repositoryName,
                        'code' => $e->repositoryCode,
                    ]),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        } catch (OpeningCashNotFullySeededException $e) {
            return response()->json([
                'error' => [
                    'code' => OpeningCashNotFullySeededException::ERROR_CODE,
                    'message' => __('messages.accounting.opening_cash_not_fully_seeded'),
                    'gaps' => $e->gaps,
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'POST_FAILED',
                    'message' => $e->getMessage(),
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 422);
        }
    }

    /**
     * Format a batch for JSON response.
     *
     * @return array<string, mixed>
     */
    private function formatBatch(OpeningBalanceBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'type' => $batch->type->value,
            'type_label' => $batch->type->label(),
            'name' => $batch->name,
            'cutover_date' => $batch->cutover_date->toDateString(),
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'source_system' => $batch->source_system,
            'import_file_reference' => $batch->import_file_reference,
            'hash' => $batch->hash,
            'validated_at' => $batch->validated_at?->toIso8601String(),
            'locked_at' => $batch->locked_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
            'is_editable' => $batch->isEditable(),
            'is_deletable' => $batch->isDeletable(),
            'can_lock' => $batch->canLock(),
            'is_locked' => $batch->isLocked(),
        ];
    }
}
