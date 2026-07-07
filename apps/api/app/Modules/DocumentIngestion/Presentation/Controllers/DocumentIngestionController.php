<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\DocumentIngestion\Application\Services\IngestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\DocumentIngestion\Presentation\Requests\StoreDocumentIngestionRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Media\Application\Services\MediaUrlResolver;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Media\MediaAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DocumentIngestionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly IngestionService $service,
        private readonly MediaUrlResolver $mediaUrlResolver,
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

        $ingestion = $this->service->createFromUpload(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            userId: $user->id,
            file: $file,
            kind: DocumentKind::from((string) $request->validated('kind')),
        );

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
}
