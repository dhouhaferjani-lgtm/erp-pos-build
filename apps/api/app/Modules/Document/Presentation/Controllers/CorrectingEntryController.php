<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\CorrectingEntryLegData;
use App\Modules\Document\Application\DTOs\CorrectingEntryPayload;
use App\Modules\Document\Application\Services\CorrectingEntryService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Presentation\Requests\CreateCorrectingEntryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/**
 * The correcting-entry document's HTTP surface (R2-F4, owner ruling c4).
 *
 * A correction is always created FROM the document it repairs
 * (`POST /documents/{document}/correcting-entries`) — there is deliberately no
 * "create a standalone correcting entry" route, because the mandatory link is
 * the ruling.
 *
 * EVERY route here is gated on `documents.correct` alone, including the reads.
 * A correcting entry names general-ledger accounts and amounts; that is
 * accounting-grade information which `documents.view` (held by cashiers,
 * technicians and viewers) must not expose. The write gate is likewise its own
 * permission rather than `invoices.cancel` or `documents.update`: posting an
 * arbitrary pair of GL legs is strictly more powerful than either.
 */
class CorrectingEntryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CorrectingEntryService $service,
    ) {}

    /**
     * Every correcting entry raised against a document.
     */
    public function index(Request $request, string $document): JsonResponse
    {
        $target = $this->findDocument($document);

        if ($target === null) {
            return $this->notFound('Document not found');
        }

        $corrections = Document::query()
            ->where('company_id', $target->company_id)
            ->where('type', DocumentType::CorrectingEntry)
            ->where('source_document_id', $target->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $corrections
                ->map(fn (Document $correction): array => $this->present($correction))
                ->all(),
        ]);
    }

    public function show(Request $request, string $correctingEntry): JsonResponse
    {
        $correction = $this->findCorrection($correctingEntry);

        if ($correction === null) {
            return $this->notFound('Correcting entry not found');
        }

        return response()->json(['data' => $this->present($correction)]);
    }

    public function store(CreateCorrectingEntryRequest $request, string $document): JsonResponse
    {
        $target = $this->findDocument($document);

        if ($target === null) {
            return $this->notFound('Document not found');
        }

        $validated = $request->validated();

        /** @var list<array{account_id: string, debit: string, credit: string, description?: string|null, partner_id?: string|null}> $legs */
        $legs = $validated['legs'];

        try {
            // The DTO carries the per-leg invariants (XOR debit/credit, no
            // negatives) that no Laravel rule expresses cleanly. Its refusal is a
            // user input error, so it becomes a 422 here rather than escaping as
            // a 500.
            $payload = new CorrectingEntryPayload(
                (string) $validated['reason'],
                array_map(
                    static fn (array $leg): CorrectingEntryLegData => CorrectingEntryLegData::of(
                        $leg['account_id'],
                        $leg['debit'],
                        $leg['credit'],
                        $leg['description'] ?? null,
                        $leg['partner_id'] ?? null,
                    ),
                    $legs,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CORRECTING_ENTRY_LEG',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        $correction = $this->service->create(
            $this->companyContext->requireCompany(),
            $target,
            $payload,
        );

        return response()->json(['data' => $this->present($correction)], 201);
    }

    public function confirm(Request $request, string $correctingEntry): JsonResponse
    {
        $correction = $this->findCorrection($correctingEntry);

        if ($correction === null) {
            return $this->notFound('Correcting entry not found');
        }

        return response()->json(['data' => $this->present($this->service->confirm($correction))]);
    }

    public function post(Request $request, string $correctingEntry): JsonResponse
    {
        $correction = $this->findCorrection($correctingEntry);

        if ($correction === null) {
            return $this->notFound('Correcting entry not found');
        }

        return response()->json(['data' => $this->present($this->service->post($correction))]);
    }

    public function destroy(Request $request, string $correctingEntry): JsonResponse
    {
        $correction = $this->findCorrection($correctingEntry);

        if ($correction === null) {
            return $this->notFound('Correcting entry not found');
        }

        $this->service->delete($correction);

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function findDocument(string $id): ?Document
    {
        /** @var Document|null */
        return Document::query()
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->whereKey($id)
            ->first();
    }

    private function findCorrection(string $id): ?Document
    {
        /** @var Document|null */
        return Document::query()
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('type', DocumentType::CorrectingEntry)
            ->whereKey($id)
            ->first();
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => $message,
            ],
        ], 404);
    }

    /**
     * Rule 3 — no `mixed`. The shape is spelled out so PHPStan (and any future
     * TypeScript transform of this envelope) sees the real contract instead of
     * an opaque `array<string, mixed>`.
     *
     * @return array{
     *     id: string,
     *     type: string,
     *     status: string,
     *     fiscal_status: string,
     *     document_number: string|null,
     *     document_date: string,
     *     currency: string,
     *     source_document_id: string|null,
     *     reference: string|null,
     *     correcting_entry: array{reason: string, legs: list<array{account_id: string, debit: string, credit: string, description: string|null, partner_id: string|null}>},
     *     created_at: string|null
     * }
     */
    private function present(Document $correction): array
    {
        return [
            'id' => $correction->id,
            'type' => $correction->type->value,
            'status' => $correction->status->value,
            'fiscal_status' => $correction->fiscal_status->value,
            'document_number' => $correction->document_number,
            'document_date' => $correction->document_date->toDateString(),
            'currency' => $correction->currency,
            'source_document_id' => $correction->source_document_id,
            'reference' => $correction->reference,
            'correcting_entry' => CorrectingEntryPayload::fromDocumentPayload($correction->payload)->toArray(),
            'created_at' => $correction->created_at?->toIso8601String(),
        ];
    }
}
