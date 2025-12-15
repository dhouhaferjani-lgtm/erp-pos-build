<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
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
    public function index(string $companyId): JsonResponse
    {
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
    public function show(string $companyId, string $batchId): JsonResponse
    {
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
    public function destroy(string $companyId, string $batchId): JsonResponse
    {
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
    public function status(string $companyId): JsonResponse
    {
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
                'rows.*.debit' => ['nullable', 'numeric', 'min:0'],
                'rows.*.credit' => ['nullable', 'numeric', 'min:0'],
                'rows.*.description' => ['nullable', 'string', 'max:255'],
            ],
            OpeningBatchType::Inventory => [
                'rows.*.product_code' => ['required', 'string'],
                'rows.*.location_code' => ['required', 'string'],
                'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
                'rows.*.unit_cost' => ['required', 'numeric', 'min:0'],
            ],
            OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems => [
                'rows.*.partner_code' => ['required', 'string'],
                'rows.*.document_date' => ['required', 'date'],
                'rows.*.due_date' => ['required', 'date'],
                'rows.*.total' => ['required', 'numeric', 'gt:0'],
                'rows.*.open_amount' => ['required', 'numeric', 'min:0'],
                'rows.*.external_invoice_number' => ['nullable', 'string', 'max:100'],
                'rows.*.document_type' => ['nullable', 'string', 'in:invoice,credit_note,inv,cn'],
                'rows.*.currency' => ['nullable', 'string', 'size:3'],
                'rows.*.notes' => ['nullable', 'string', 'max:500'],
            ],
        };

        $validated = $request->validate(array_merge($baseRules, $typeRules));

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
    public function validateBatch(string $companyId, string $batchId): JsonResponse
    {
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
    public function preview(string $companyId, string $batchId): JsonResponse
    {
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
