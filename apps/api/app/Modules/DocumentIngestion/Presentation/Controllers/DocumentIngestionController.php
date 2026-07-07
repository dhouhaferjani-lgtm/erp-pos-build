<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\DocumentIngestion\Application\Exceptions\DuplicateDocumentException;
use App\Modules\DocumentIngestion\Application\Services\IngestionCommitterRegistry;
use App\Modules\DocumentIngestion\Application\Services\IngestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\DocumentIngestion\Presentation\Requests\CommitDocumentIngestionRequest;
use App\Modules\DocumentIngestion\Presentation\Requests\StoreDocumentIngestionRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\Services\MediaUrlResolver;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DocumentIngestionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly IngestionService $service,
        private readonly MediaUrlResolver $mediaUrlResolver,
        private readonly IngestionCommitterRegistry $committerRegistry,
    ) {}

    public function store(StoreDocumentIngestionRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $file = $request->file('file');
        if ($file === null) {
            abort(422);
        }

        try {
            $ingestion = $this->service->createFromUpload(
                tenantId: $company->tenant_id,
                companyId: $company->id,
                userId: $user->id,
                file: $file,
                kind: DocumentKind::from((string) $request->validated('kind')),
            );
        } catch (DuplicateDocumentException $exception) {
            // Typed code so clients (mobile scan capture) don't infer duplicates
            // from validation-field presence; errors.file kept for back-compat.
            return response()->json([
                'error' => [
                    'code' => 'DUPLICATE_DOCUMENT',
                    'message' => $exception->getMessage(),
                    'errors' => ['file' => [$exception->getMessage()]],
                ],
            ], 422);
        }

        return response()->json(['data' => $this->resource($ingestion)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $query = DocumentIngestion::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id);

        $status = $request->query('status');
        if (is_string($status) && $status !== '' && IngestionStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $kind = $request->query('kind');
        if (is_string($kind) && $kind !== '' && DocumentKind::tryFrom($kind) !== null) {
            $query->where('kind', $kind);
        }

        $page = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (DocumentIngestion $ingestion): array => $this->resource($ingestion))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $ingestion = $this->findScoped($id);
        if (! $ingestion instanceof DocumentIngestion) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->resource($ingestion, includeSourceUrl: true)]);
    }

    public function extract(string $id): JsonResponse
    {
        $ingestion = $this->findScoped($id);
        if (! $ingestion instanceof DocumentIngestion) {
            return $this->notFound();
        }

        try {
            $updated = $this->service->reExtract($ingestion);
        } catch (\DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }

        return response()->json(['data' => $this->resource($updated)], 202);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $ingestion = $this->findScoped($id);
        if (! $ingestion instanceof DocumentIngestion) {
            return $this->notFound();
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $updated = $this->service->reject($ingestion, $user->id);
        } catch (\DomainException $exception) {
            return $this->conflict($exception->getMessage());
        }

        return response()->json(['data' => $this->resource($updated)]);
    }

    public function commit(CommitDocumentIngestionRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        // Crash-recovery branch (explicit, BEFORE the claim): a Committing row
        // whose committer already persisted its result crashed before the
        // finalize step — finalize it and return the existing result without
        // re-running the committer. A Committing row with NO committed result
        // is an in-flight (or hard-crashed) run: never re-claim it, 409.
        $recovery = $this->recoverCommittingIngestion($company->tenant_id, $company->id, $id);
        if ($recovery instanceof JsonResponse) {
            return $recovery;
        }

        // Single-winner atomic claim: ONLY NeedsReview may be claimed. Adding
        // Committing to the prior-state set would let a concurrent second
        // commit re-evaluate against the winner's new Committing row under
        // READ COMMITTED and also claim it — running the committer twice.
        $claimed = DocumentIngestion::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->where('status', IngestionStatus::NeedsReview->value)
            ->update([
                'status' => IngestionStatus::Committing->value,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            $existing = $this->findScoped($id);
            if (! $existing instanceof DocumentIngestion) {
                return $this->notFound();
            }

            if ($existing->status === IngestionStatus::Committed) {
                return $this->conflict('This ingestion has already been committed.');
            }

            if ($existing->status === IngestionStatus::Committing) {
                return $this->conflict('This ingestion is already being committed.');
            }

            return $this->conflict('Only reviewable ingestions can be committed.');
        }

        $ingestion = $this->findScoped($id);
        if (! $ingestion instanceof DocumentIngestion) {
            return $this->notFound();
        }

        if ($ingestion->committed_type !== null && $ingestion->committed_id !== null) {
            $ingestion->forceFill(['status' => IngestionStatus::Committed])->save();

            return response()->json(['data' => $this->commitResource($ingestion)]);
        }

        try {
            $this->assertReviewOnlyFlagsAreAbsent($ingestion);
            $result = $this->committerRegistry
                ->for($ingestion->kind)
                ->commit($ingestion, $request->payload(), $user->id);

            DB::transaction(function () use ($ingestion, $result): void {
                $ingestion->forceFill([
                    'committed_type' => $result->committedType,
                    'committed_id' => $result->committedId,
                    'status' => IngestionStatus::Committed,
                    'error' => null,
                ])->save();
            });

            return response()->json(['data' => $result->toResponseArray()], 201);
        } catch (\Throwable $exception) {
            $ingestion->forceFill([
                'status' => IngestionStatus::NeedsReview,
                'error' => [
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                ],
            ])->save();

            if ($exception instanceof \DomainException) {
                return response()->json([
                    'error' => [
                        'code' => 'COMMIT_FAILED',
                        'message' => $exception->getMessage(),
                    ],
                ], 422);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(DocumentIngestion $ingestion, bool $includeSourceUrl = false): array
    {
        $data = [
            'id' => $ingestion->id,
            'tenant_id' => $ingestion->tenant_id,
            'company_id' => $ingestion->company_id,
            'kind' => $ingestion->kind->value,
            'status' => $ingestion->status->value,
            'media_asset_id' => $ingestion->media_asset_id,
            'checksum' => $ingestion->checksum,
            'provider' => $ingestion->provider,
            'provider_model' => $ingestion->provider_model,
            'extraction' => $ingestion->extraction,
            'confidence_summary' => $ingestion->confidence_summary,
            'suggestions' => $ingestion->suggestions,
            'committed_type' => $ingestion->committed_type,
            'committed_id' => $ingestion->committed_id,
            'error' => $ingestion->error,
            'created_by' => $ingestion->created_by,
            'created_at' => $ingestion->created_at?->toIso8601String(),
            'updated_at' => $ingestion->updated_at?->toIso8601String(),
        ];

        if ($includeSourceUrl) {
            $data['source_url'] = $this->sourceUrl($ingestion);
        }

        return $data;
    }

    private function sourceUrl(DocumentIngestion $ingestion): ?string
    {
        $attachment = MediaAttachment::query()
            ->with('mediaAsset')
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('owner_type', MediaOwnerType::DocumentIngestion)
            ->where('owner_id', $ingestion->id)
            ->orderBy('sort_order')
            ->first();

        return $attachment instanceof MediaAttachment
            ? $this->mediaUrlResolver->forAttachment($attachment, null)
            : null;
    }

    /**
     * Finalize a Committing ingestion that already holds its committed result,
     * or surface a conflict for an in-flight Committing run. Returns null when
     * the row is not in the Committing state (normal claim path proceeds).
     */
    private function recoverCommittingIngestion(string $tenantId, string $companyId, string $id): ?JsonResponse
    {
        return DB::transaction(function () use ($tenantId, $companyId, $id): ?JsonResponse {
            $row = DocumentIngestion::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof DocumentIngestion || $row->status !== IngestionStatus::Committing) {
                return null;
            }

            if ($row->committed_type === null) {
                // In-flight or crashed with no persisted result: the failure
                // catch-path resets Committing -> NeedsReview; never re-run
                // the committer from here.
                return $this->conflict('This ingestion is already being committed.');
            }

            $row->forceFill(['status' => IngestionStatus::Committed, 'error' => null])->save();

            return response()->json(['data' => $this->commitResource($row)]);
        });
    }

    private function findScoped(string $id): ?DocumentIngestion
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $company = $this->companyContext->requireCompany();

        /** @var Builder<DocumentIngestion> $query */
        $query = DocumentIngestion::query();

        return $query
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'Document ingestion not found.',
            ],
        ], 404);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INGESTION_CONFLICT',
                'message' => $message,
            ],
        ], 409);
    }

    /**
     * @return array{committed_type: string|null, committed_id: string|null, goods_receipt_number: null}
     */
    private function commitResource(DocumentIngestion $ingestion): array
    {
        return [
            'committed_type' => $ingestion->committed_type,
            'committed_id' => $ingestion->committed_id,
            'goods_receipt_number' => null,
        ];
    }

    private function assertReviewOnlyFlagsAreAbsent(DocumentIngestion $ingestion): void
    {
        $summary = $ingestion->confidence_summary;
        $reconciliation = is_array($summary) && is_array($summary['reconciliation'] ?? null)
            ? $summary['reconciliation']
            : null;

        $consistent = is_array($reconciliation) ? ($reconciliation['consistent'] ?? true) : true;
        $flags = is_array($reconciliation) && is_array($reconciliation['flags'] ?? null)
            ? $reconciliation['flags']
            : [];

        $hasUnparseable = false;
        foreach ($flags as $flag) {
            if (is_string($flag) && str_starts_with($flag, 'field_unparseable:')) {
                $hasUnparseable = true;
                break;
            }
        }

        if ($consistent === false || $hasUnparseable) {
            throw new \DomainException('This ingestion has reconciliation flags that require manual review before commit.');
        }
    }
}
