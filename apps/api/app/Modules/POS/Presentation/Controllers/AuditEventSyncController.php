<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Domain\AuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ingests client-authored POS audit events into the tenant `audit_events`
 * store.
 *
 * POST /api/v1/pos/audit-events/sync
 *
 * Design (Sub-Spec C §Backend):
 * - Authorized by the dedicated `pos.audit_sync` ability (NOT
 *   `pos.operate_terminal`).
 * - The client `event_id` is preserved as the primary key and the client
 *   `occurred_at` as the business timestamp (via
 *   {@see AuditEvent::fromClientEnvelope()}).
 * - Idempotent per-event: a duplicate `event_id` is counted, not an error.
 *   There is NO batch-wide transaction — a single duplicate must never roll
 *   back the valid rows already written in the same batch.
 * - Tenant-guarded: any event whose `tenant_id` differs from the token's
 *   tenant is rejected 422 BEFORE anything is written.
 */
final class AuditEventSyncController extends Controller
{
    /**
     * Maximum serialized byte size for each of `payload` and `metadata`.
     */
    private const MAX_FIELD_BYTES = 8192;

    public function sync(Request $request): JsonResponse
    {
        Gate::authorize('pos.audit_sync');

        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.event_type' => ['required', 'string', 'max:100'],
            'events.*.aggregate_type' => ['required', 'string', 'max:100'],
            'events.*.aggregate_id' => ['required', 'string', 'max:100'],
            'events.*.tenant_id' => ['required', 'uuid'],
            'events.*.company_id' => ['nullable', 'uuid'],
            'events.*.operator_id' => ['nullable', 'uuid'],
            'events.*.payload' => ['required', 'array'],
            'events.*.metadata' => ['required', 'array'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);

        $user = $request->user();
        $tenantId = $user?->getAttribute('tenant_id');

        /** @var list<array<string, mixed>> $events */
        $events = $validated['events'];

        // Validate the whole batch BEFORE writing anything: a foreign tenant or
        // an oversized field must reject the request with zero side effects.
        foreach ($events as $event) {
            if ($event['tenant_id'] !== $tenantId) {
                throw ValidationException::withMessages([
                    'events' => ['Event tenant mismatch.'],
                ]);
            }

            $payloadBytes = strlen((string) json_encode($event['payload']));
            $metadataBytes = strlen((string) json_encode($event['metadata']));
            if ($payloadBytes > self::MAX_FIELD_BYTES || $metadataBytes > self::MAX_FIELD_BYTES) {
                throw ValidationException::withMessages([
                    'events' => ['Event payload or metadata too large.'],
                ]);
            }
        }

        $created = 0;
        $duplicates = 0;

        foreach ($events as $event) {
            /** @var array<string, mixed> $metadata */
            $metadata = $event['metadata'];
            $event['metadata'] = array_merge($metadata, [
                'ingested_at' => now()->toIso8601String(),
                'client_clock_skew_ms' => now()->diffInMilliseconds(
                    Carbon::parse((string) $event['occurred_at']),
                    false,
                ),
                'ip' => $request->ip(),
            ]);

            try {
                AuditEvent::fromClientEnvelope($event)->saveOrFail();
                $created++;
            } catch (UniqueConstraintViolationException) {
                $duplicates++;
            }
        }

        return response()->json([
            'data' => [
                'created' => $created,
                'duplicates' => $duplicates,
            ],
        ]);
    }
}
