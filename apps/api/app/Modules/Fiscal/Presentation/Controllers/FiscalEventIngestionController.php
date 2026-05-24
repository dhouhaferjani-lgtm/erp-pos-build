<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\IngestionResult;
use App\Modules\Fiscal\Application\Services\OutboxIngestor;
use App\Modules\Fiscal\Presentation\Requests\IngestFiscalEventsRequest;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Task 20 — `POST /api/v1/pos/sync/fiscal-events` (spec v7 §7.1, plan §1484).
 *
 * The single device→server fiscal-event ingestion endpoint. Accepts a batch
 * of wire envelopes (outer
 * `{ envelope_id, type: 'FISCAL_EVENT', payload_version, idempotency_key, payload }`
 * carrying an inner spec §4 fourteen-key envelope plus `canonical_bytes` +
 * `current_hash`); returns one ingestion result per envelope.
 *
 * Two-stage rejection model:
 *   - **Pre-flight** (this controller). A malformed field (422) or
 *     cross-tenant envelope (403) anywhere in the batch aborts the entire
 *     batch BEFORE any ingest call — zero rows persist. Both signals are
 *     "stop and resend a fixed batch" from the device, not partial-batch
 *     noise to be observed in the per-envelope response.
 *   - **Per-envelope ingest** ({@see OutboxIngestor}). Once the pre-flight
 *     passes, each envelope is verified-then-inserted independently; a
 *     `sequence_conflict` or in-table quarantine is a per-envelope outcome
 *     reported in `results[]`.
 *
 * Defense-in-depth tenant boundary:
 *   - The middleware tuple (`auth:sanctum` + `SetPermissionsTeam` +
 *     `EnforceTokenTenantClaim`) verifies the bearer token's `tenant:`
 *     ability matches the live `User::tenant_id`.
 *   - This controller additionally verifies every envelope's `tenant_id`
 *     matches the authenticated user's `tenant_id`. The middleware enforces
 *     token-vs-user; the controller enforces envelope-vs-user.
 */
final class FiscalEventIngestionController extends Controller
{
    public function __construct(
        private readonly OutboxIngestor $ingestor,
    ) {}

    public function store(IngestFiscalEventsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            // Unreachable under the `auth:sanctum` middleware (it 401s before
            // this controller runs), but PHPStan level 8 wants the narrowing
            // and we honour it as a fail-closed defense (Task 18 standing
            // pattern 6 — fail-closed on downstream-service exception class).
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authenticated user could not be resolved.',
                ],
            ], 401);
        }
        $userTenantId = $user->tenant_id;

        /** @var array<int, array<string, mixed>> $wireEnvelopes */
        $wireEnvelopes = $request->validated()['envelopes'];

        // Pre-flight Stage 1 — typed-DTO construction. `fromArray()` regex-
        // validates every free-form field (hashes, UUIDs, timestamps) per
        // round-2 T19-B4. A malformed field anywhere in the batch returns
        // 422 with the offending index BEFORE any row is persisted.
        $envelopes = [];
        foreach ($wireEnvelopes as $index => $wire) {
            $flat = $this->mergeOuterIntoPayload($wire);
            try {
                $envelopes[] = FiscalEventEnvelope::fromArray($flat);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'error' => [
                        'code' => 'MALFORMED_ENVELOPE',
                        'message' => 'Envelope failed shape validation.',
                        'envelope_index' => $index,
                        'detail' => $e->getMessage(),
                    ],
                ], 422);
            }
        }

        // Pre-flight Stage 2 — envelope-vs-user tenant boundary. A single
        // cross-tenant envelope in a batch is a security event, not a
        // partial-batch outcome: the entire batch is rejected with 403 and
        // zero rows persist.
        foreach ($envelopes as $index => $envelope) {
            if ($envelope->tenantId !== $userTenantId) {
                return response()->json([
                    'error' => [
                        'code' => 'TENANT_MISMATCH',
                        'message' => 'Envelope tenant_id does not match authenticated user tenant.',
                        'envelope_index' => $index,
                    ],
                ], 403);
            }
        }

        // Per-envelope ingest. The OutboxIngestor's verify-then-insert core
        // (Task 19) is fail-closed for chain anomalies: every hash / linkage
        // / clock / parse anomaly is captured in `IngestionResult` (stored /
        // sequence_conflict / in-table quarantine via `exception_class`) and
        // returned to the caller. Two exception paths remain — both intentional:
        //   - `InvalidArgumentException` from `FiscalEventEnvelope::assertWireShape()`
        //     is unreachable here because pre-flight Stage 1 above already
        //     called `FiscalEventEnvelope::fromArray()`, which runs the
        //     assertion. The ingestor calls it again at its own boundary,
        //     but that path is dead in this controller's call chain. No
        //     catch is added — the Task 19 F3 standing pattern removes
        //     dead defensive wrappers.
        //   - `QueryException` from a `(source_event_class, source_event_id)`
        //     uniqueness violation is the Task 19 round-2 T19-B2 contract:
        //     re-thrown as a programming bug for the caller (Log::critical
        //     fires inside the ingestor). The controller lets it propagate
        //     to a 500 — the device's authoring logic must not reuse a
        //     source-event-id across different fiscal events.
        $results = [];
        foreach ($envelopes as $index => $envelope) {
            $result = $this->ingestor->ingest($envelope);
            if ($result->rejectionCode === 'SERVER_ONLY_EVENT_TYPE') {
                return response()->json([
                    'error' => [
                        'code' => 'SERVER_ONLY_EVENT_TYPE',
                        'message' => 'Server-only fiscal event types cannot be ingested from a POS device.',
                        'envelope_index' => $index,
                    ],
                ], 422);
            }

            $results[] = $this->resultToWire($result);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Flatten the wire envelope (outer + nested `payload`) into the single
     * array shape `FiscalEventEnvelope::fromArray()` consumes.
     *
     * The outer `type` key is the wire discriminator (validated by the
     * FormRequest); the DTO ignores it because the inner `event_type` is
     * the authoritative fiscal-event-type identifier.
     *
     * @param  array<string, mixed>  $wire
     * @return array<string, mixed>
     */
    private function mergeOuterIntoPayload(array $wire): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($wire['payload'] ?? null) ? $wire['payload'] : [];

        return array_merge($payload, [
            'envelope_id' => $wire['envelope_id'] ?? null,
            'idempotency_key' => $wire['idempotency_key'] ?? null,
            'payload_version' => $wire['payload_version'] ?? null,
        ]);
    }

    /**
     * @return array{
     *   stored: bool,
     *   fiscal_event_id: ?string,
     *   sequence_conflict: bool,
     *   exception_class: ?string,
     * }
     */
    private function resultToWire(IngestionResult $result): array
    {
        return [
            'stored' => $result->stored,
            'fiscal_event_id' => $result->fiscalEventId,
            'sequence_conflict' => $result->sequenceConflict,
            'exception_class' => $result->exceptionClass?->value,
        ];
    }
}
