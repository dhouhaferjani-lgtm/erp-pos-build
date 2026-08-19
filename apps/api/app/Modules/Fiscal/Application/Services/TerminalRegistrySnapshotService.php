<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\TerminalRegistrySnapshotPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\InvalidServerAuthoredPayloadException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Server-authored `TERMINAL_REGISTRY_SNAPSHOT` emitter (spec v7 §11 + §11.0).
 *
 * **Why server-authored (spec §11.0 carve-out — LOCKED).** The §1 device-
 * authority rule (the device is the fiscal SoT; the server is verify-only
 * `[SoT §3, §4]`) applies to per-device transaction events (SALE_RECEIPT +
 * the chain-recovery cousins CHAIN_BREAK_DETECTED / CHAIN_RESTART).
 * §11.0 explicitly carves out company-integrity event types
 * (`TERMINAL_REGISTRY_SNAPSHOT` implemented; `COMPANY_DAY_CLOSURE_MANIFEST`
 * reserved): they are operator/company-level facts (the authoritative
 * roster at provisioning time, the day-closure manifest at end of day) with
 * no per-terminal-business trigger, no per-terminal-chain linkage at the
 * business-fact layer, and (by construction) no device that knows the
 * authoritative set of OTHER devices. The carve-out is bounded to the
 * event types listed in §11 and comes with five named invariants. This
 * service implements those invariants for the implemented event type.
 *
 * The emitted event still lands in `fiscal_events` and links forensically
 * into the terminal chain (sequence_number, previous_hash, current_hash)
 * so the per-terminal verifier (Task 31) can prove the snapshot's place
 * in the timeline.
 *
 * **Payload shape (§11 + Task 14 DTO + Task 24 constraint validator):**
 *   - `terminals` — authoritative list of the company's terminals, each
 *     carrying canonical identity columns from `pos_terminals` (id, code,
 *     name, is_active, hardware_identifier, genesis_seed,
 *     current_sequence). Ordered by `code` ASC for deterministic
 *     canonical bytes — two snapshots taken from the same DB state must
 *     produce byte-identical `terminals` lists regardless of which row
 *     the query returned first.
 *   - `snapshot_hash` — SHA-256 of the canonical-JSON-encoded `terminals`
 *     list. The verifiable anchor a downstream auditor uses to prove the
 *     snapshot wasn't re-authored after the fact.
 *   - `prior_snapshot_link` — 64-char lowercase hex hash of the previous
 *     snapshot for the same (tenant, company) — pulled from the prior
 *     row's payload `snapshot_hash`. NULL when this is the first snapshot
 *     for the company.
 *
 * **Chain placement.** Server-authored events follow the same chain
 * invariants as device-authored events (Task 19 T19-B3):
 *   - First event on a terminal chain — `sequence_number=1`,
 *     `previous_hash = pos_terminals.genesis_seed`.
 *   - Subsequent events — `sequence_number = prior.sequence_number + 1`,
 *     `previous_hash = prior.current_hash`.
 *
 * **Fail-closed boundary.** An unknown `terminal_id` (no `pos_terminals`
 * row) throws a `RuntimeException` at the service boundary rather than
 * writing a `fiscal_events` row whose `previous_hash` cannot be
 * reconciled with a chain head. Caller is responsible for resolving a
 * real terminal before invoking the service.
 *
 * **Constructor injection.** `ConnectionInterface` for DB access,
 * `FiscalIntegrityProvider` for the canonical SHA-256 primitive
 * (`HashChainIntegrityProvider` at runtime — already bound by
 * `FiscalServiceProvider::register()`), and `FiscalPayloadConstraintValidator`
 * for §11.0 invariant #3 (the per-event payload-shape gate that the parse
 * path runs — server-authored events MUST run the same gate, the round-2
 * dual-review T26-P2 closure). All three are Laravel-resolvable so no
 * provider edit needed (CLAUDE.md rule 13).
 *
 * **Round-2 (T26 dual review) changes summary:**
 *   - §11.0 carve-out documented in the class docblock (T26-B1 closure
 *     via spec amendment §11.0).
 *   - `FiscalPayloadConstraintValidator` constructor dependency added;
 *     `validatePayloadKeySet()` + `validatePerEventConstraints()` run
 *     before persist (T26-P2 closure, §11.0 invariant #3).
 *   - Chain placement + persist wrapped in a `DB::transaction()` with
 *     `lockForUpdate()` on `pos_terminals` (the authoring terminal row).
 *     Concurrent emissions for the same terminal serialize cleanly — the
 *     second waits for the first to commit, re-reads the chain head, and
 *     computes a non-colliding `(sequence_number, previous_hash)` pair
 *     (T26-P1 closure, §11.0 invariant #4).
 */
final class TerminalRegistrySnapshotService
{
    /** `not_required` is the Phase 1 default — no signature provider until later phases. */
    private const SERVER_AUTHORED_SIGNATURE_VERSION = 'hash-chain-integrity-v1';

    /**
     * ES-09 — the chain context this service authors into. Every event type it
     * emits (`TERMINAL_REGISTRY_SNAPSHOT`) is an operational-chain fact; the
     * `z_session` chain is device-authored session lifecycle only.
     */
    private const CHAIN_CONTEXT = 'operational';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalIntegrityProvider $integrity,
        private readonly FiscalPayloadConstraintValidator $payloadValidator,
        private readonly ServerAuthoredChainPlacementVerifier $placementVerifier,
    ) {}

    /**
     * Emit a `TERMINAL_REGISTRY_SNAPSHOT` fiscal event for the given
     * company. Composes the authoritative terminal list, hashes it,
     * links to the prior snapshot via the prior snapshot's `snapshot_hash`,
     * and persists the row into `fiscal_events`.
     *
     * @throws RuntimeException when the authoring terminal cannot be
     *                          found (a programming bug — the caller
     *                          must resolve a real terminal first).
     */
    public function emitInitialSnapshot(
        string $tenantId,
        string $companyId,
        string $terminalId,
        string $operatorId,
    ): FiscalEvent {
        // --- Pre-flight (outside transaction): authoring-terminal existence.
        // A missing terminal is a programming error, not a race — fail
        // closed before opening a transaction so the boundary error is
        // unambiguous and no DB resources are tied up.
        /** @var stdClass|null $terminal */
        $terminal = $this->db->table('pos_terminals')
            ->where('id', $terminalId)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->first(['id']);

        if ($terminal === null) {
            throw new RuntimeException(sprintf(
                'TerminalRegistrySnapshotService: cannot emit snapshot — authoring terminal_id %s not found '.
                'in pos_terminals for tenant=%s company=%s. Resolve a real terminal before calling emitInitialSnapshot().',
                $terminalId,
                $tenantId,
                $companyId,
            ));
        }

        // --- Step 1: build the authoritative terminals list (deterministic order).
        $terminals = $this->loadTerminalsForCompany($tenantId, $companyId);

        // --- Step 2: hash the canonical terminals list — the §11 snapshot anchor.
        $snapshotHash = $this->integrity->computeHash($this->canonicalEncode($terminals));

        // --- Step 3 + 4 + 5 + 6: chain placement + envelope + persist
        // inside ONE transaction with a row-lock on `pos_terminals` for
        // the authoring terminal (§11.0 invariant #4 — T26-P1 closure).
        //
        // The row-lock serializes concurrent snapshot emissions on the
        // SAME terminal: the second `emitInitialSnapshot()` call waits at
        // the `lockForUpdate()` until the first commits, then reads the
        // fresh chain head (sequence_number, prior snapshot) and computes
        // a non-colliding placement. Without the lock, concurrent callers
        // could read the same chain head and trip the
        // `fiscal_events_tenant_terminal_sequence_unique` UNIQUE.
        //
        // The prior-snapshot lookup (§3) runs INSIDE the locked region so
        // it observes the latest committed snapshot for the company;
        // outside the lock a second emission could read a stale prior.
        return $this->db->transaction(function () use (
            $tenantId,
            $companyId,
            $terminalId,
            $operatorId,
            $terminals,
            $snapshotHash,
        ): FiscalEvent {
            // Acquire the terminal-row lock — serializes concurrent
            // snapshot emissions on this terminal. We re-read `genesis_seed`
            // inside the lock so the seed value seen here is the committed
            // value (a parallel provisioning rotation could theoretically
            // change it; the lock pins the visible row for the duration).
            /** @var stdClass|null $lockedTerminal */
            $lockedTerminal = $this->db->table('pos_terminals')
                ->where('id', $terminalId)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first(['id', 'genesis_seed']);

            if ($lockedTerminal === null) {
                // Defense — the terminal existed during the pre-flight
                // check but disappeared before the lock. Treat identically
                // to the pre-flight miss for a uniform boundary error.
                throw new RuntimeException(sprintf(
                    'TerminalRegistrySnapshotService: authoring terminal_id %s vanished between pre-flight and lock acquisition '.
                    'for tenant=%s company=%s.',
                    $terminalId,
                    $tenantId,
                    $companyId,
                ));
            }
            $genesisSeed = is_string($lockedTerminal->genesis_seed)
                ? $lockedTerminal->genesis_seed
                : (string) $lockedTerminal->genesis_seed;

            // Prior-snapshot link (read inside the locked region so it
            // observes the most recent committed snapshot).
            $priorSnapshotLink = $this->findPriorSnapshotHash($tenantId, $companyId);

            $payloadDto = new TerminalRegistrySnapshotPayload(
                terminals: $terminals,
                snapshotHash: $snapshotHash,
                priorSnapshotLink: $priorSnapshotLink,
            );
            $payloadArray = $payloadDto->toArray();

            // --- §11.0 invariant #3: validate the payload BEFORE persist.
            // T26-P2 closure: server-authored events MUST run the same
            // per-event payload-shape gate the parse path runs (`StrictCanonicalParser`
            // for ingested device events, `ParseFailureResolutionService` for
            // corrected payloads). Otherwise a drift in DTO shape would
            // silently write `payload_parse_status=parsed` on a payload the
            // canonical-bytes parse path would reject.
            $this->validateServerAuthoredPayload(
                FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
                $payloadArray,
            );

            // Resolve chain placement INSIDE the lock — the prior-event
            // query observes the committed state at lock-acquisition time.
            [$sequenceNumber, $previousHash, $priorHead] = $this->resolveChainPlacement(
                tenantId: $tenantId,
                companyId: $companyId,
                terminalId: $terminalId,
                chainContext: self::CHAIN_CONTEXT,
                genesisSeed: $genesisSeed,
            );

            // Build the canonical envelope bytes per spec §4 (14 keys).
            $now = Carbon::now('UTC');
            $envelope = [
                'business_date' => $now->copy()->startOfDay()->toDateString(),
                'company_id' => $companyId,
                'event_time_device' => $now->format('Y-m-d\TH:i:s\Z'),
                'event_type' => FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value,
                'event_version' => 1,
                'operator_id' => $operatorId,
                'payload' => $payloadArray,
                'previous_hash' => $previousHash,
                'reference_document_id' => null,
                'reference_event_id' => null,
                'sequence_number' => $sequenceNumber,
                'signature_version' => self::SERVER_AUTHORED_SIGNATURE_VERSION,
                'tenant_id' => $tenantId,
                'terminal_id' => $terminalId,
            ];
            $canonicalBytes = $this->canonicalEncode($envelope);
            $currentHash = $this->integrity->computeHash($canonicalBytes);

            // ES-09 defect (b) — DERIVE the integrity verdict instead of
            // stamping it. Runs the same hash → linkage → clock checks
            // `OutboxIngestor` runs on every device envelope, against the head
            // read under this terminal's row lock. On a legitimate append the
            // verdict is `Verified` and the write proceeds; anything else
            // throws and the surrounding transaction rolls back, so no row
            // ever claims a verdict it did not earn.
            $this->placementVerifier->assertAdmissible(
                prior: $priorHead,
                genesisSeed: $genesisSeed,
                sequenceNumber: $sequenceNumber,
                previousHash: $previousHash,
                canonicalBytes: $canonicalBytes,
                currentHash: $currentHash,
                eventTimeDevice: $envelope['event_time_device'],
                serverReceivedAt: CarbonImmutable::instance($now),
                eventType: FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value,
                chainContext: self::CHAIN_CONTEXT,
            );

            // Persist the row. The Eloquent `create()` is fine here because
            // the row-lock on `pos_terminals` already serializes concurrent
            // emissions on this terminal — no UNIQUE-violation race window
            // remains. The DB-level UNIQUE on
            // `(tenant_id, terminal_id, sequence_number)` is the belt-and-
            // braces final guard (would surface as `QueryException`).
            /** @var FiscalEvent $event */
            $event = FiscalEvent::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'terminal_id' => $terminalId,
                'operator_id' => $operatorId,
                'event_type' => FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
                // ES-09: written EXPLICITLY rather than left to the column
                // default. The head read above is scoped by chain_context, so
                // the row must state the context it was scoped against —
                // relying on a DB default while scoping the read explicitly is
                // the implicit coupling that produced this defect.
                'chain_context' => self::CHAIN_CONTEXT,
                'event_version' => 1,
                'signature_version' => self::SERVER_AUTHORED_SIGNATURE_VERSION,
                'sequence_number' => $sequenceNumber,
                'event_time_device' => $now,
                'business_date' => $now->copy()->startOfDay(),
                'last_server_time_seen' => null,
                'server_received_at' => $now,
                'reference_event_id' => null,
                'reference_document_id' => null,
                'source_event_class' => null,
                'source_event_id' => null,
                'partner_id' => null,
                'partner_identity_snapshot' => null,
                'canonical_bytes' => $canonicalBytes,
                'previous_hash' => $previousHash,
                'current_hash' => $currentHash,
                'signature_status' => SignatureStatus::NotRequired,
                'integrity_status' => IntegrityStatus::Verified,
                'integrity_exception_class' => null,
                'integrity_exception_reason' => null,
                'payload' => $payloadArray,
                'payload_parse_status' => PayloadParseStatus::Parsed,
            ]);

            return $event->refresh();
        });
    }

    /**
     * §11.0 invariant #3 — run the same per-event payload-shape gate the
     * parse path runs (`StrictCanonicalParser` /
     * `ParseFailureResolutionService`) BEFORE writing
     * `payload_parse_status = Parsed`.
     *
     * Wraps any validator throw as `InvalidServerAuthoredPayloadException`
     * so the caller boundary sees a typed error. Returns nothing on
     * success; on failure the surrounding `DB::transaction()` rolls back
     * — no `fiscal_events` row is written.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidServerAuthoredPayloadException
     */
    private function validateServerAuthoredPayload(FiscalEventType $type, array $payload): void
    {
        // Server-authored TERMINAL_REGISTRY_SNAPSHOT is a v1-only contract
        // (FiscalEventPayloadRegistry::PHASE_1_MAP), and the authoring path
        // above stamps `event_version => 1`. Passed explicitly so the version
        // threading is auditable at every call site.
        $keySetError = $this->payloadValidator->validatePayloadKeySet($type, $payload, 'operational', 1);
        if ($keySetError !== null) {
            throw new InvalidServerAuthoredPayloadException(sprintf(
                'Server-authored %s payload failed key-set validation: %s',
                $type->value,
                $keySetError,
            ));
        }

        try {
            $this->payloadValidator->validatePerEventConstraints($type, $payload);
        } catch (Throwable $previous) {
            throw new InvalidServerAuthoredPayloadException(sprintf(
                'Server-authored %s payload failed per-event constraint validation: %s',
                $type->value,
                $previous->getMessage(),
            ), $previous);
        }
    }

    /**
     * Load the authoritative terminal list for the (tenant, company) pair,
     * ordered by `code` ASC so the canonical bytes are deterministic.
     *
     * Excludes soft-deleted rows (a hard-deleted terminal is no longer
     * part of the roster) but INCLUDES inactive (`is_active = false`)
     * terminals — a deactivated-but-still-on-the-books terminal is part
     * of the roster's history. The `is_active` flag is carried in the
     * payload entry so downstream verifiers can reconstruct which
     * terminals were live.
     *
     * The raw `db->table()` path means soft-delete filtering is explicit
     * here (`whereNull('deleted_at')`) rather than implicit via the
     * Eloquent model's `SoftDeletes` global scope — keeps the service
     * boundary-clean (no dependency on the POS module's Terminal model).
     *
     * @return list<array<string, mixed>>
     */
    private function loadTerminalsForCompany(string $tenantId, string $companyId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->table('pos_terminals')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get([
                'id',
                'code',
                'name',
                'is_active',
                'hardware_identifier',
                'genesis_seed',
                'current_sequence',
            ])
            ->all();

        return array_map(static function (stdClass $row): array {
            return [
                'terminal_id' => is_string($row->id) ? $row->id : (string) $row->id,
                'code' => is_string($row->code) ? $row->code : (string) $row->code,
                'name' => is_string($row->name) ? $row->name : (string) $row->name,
                'is_active' => (bool) $row->is_active,
                'hardware_identifier' => $row->hardware_identifier === null
                    ? null
                    : (is_string($row->hardware_identifier) ? $row->hardware_identifier : (string) $row->hardware_identifier),
                'genesis_seed' => is_string($row->genesis_seed) ? $row->genesis_seed : (string) $row->genesis_seed,
                'current_sequence' => is_int($row->current_sequence)
                    ? $row->current_sequence
                    : (int) $row->current_sequence,
            ];
        }, $rows);
    }

    /**
     * Look up the most recent `TERMINAL_REGISTRY_SNAPSHOT` event for the
     * (tenant, company) pair and return its payload `snapshot_hash` —
     * the §11 prior-snapshot link.
     *
     * Returns `null` when no prior snapshot exists for the company (this
     * is the first snapshot — the linked sub-chain starts here).
     */
    private function findPriorSnapshotHash(string $tenantId, string $companyId): ?string
    {
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('event_type', FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value)
            ->where('integrity_status', IntegrityStatus::Verified->value)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence_number')
            ->first(['payload']);

        if ($prior === null) {
            return null;
        }

        $rawPayload = $prior->payload;
        if (is_resource($rawPayload)) {
            $rawPayload = stream_get_contents($rawPayload);
        }
        if (! is_string($rawPayload)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }
        $snapshotHash = $decoded['snapshot_hash'] ?? null;

        return is_string($snapshotHash) ? $snapshotHash : null;
    }

    /**
     * Resolve `sequence_number` + `previous_hash` for the new event on
     * the authoring terminal chain — server-authored events follow the
     * same Task 19 T19-B3 invariants as device-authored events.
     *
     * **ES-09 (M4).** The head read is scoped by
     * `(tenant_id, company_id, terminal_id, chain_context)`, matching
     * `OutboxIngestor`'s prior-row read verbatim and the UNIQUE the schema has
     * enforced since `2026_05_24_100000_add_chain_context_to_fiscal_events.php`
     * (`(tenant_id, company_id, terminal_id, chain_context, sequence_number)`).
     * It previously keyed on `(tenant_id, terminal_id)` alone, so on any
     * two-context terminal — which is EVERY v3 terminal, carrying both
     * `operational` and `z_session` — `orderByDesc('sequence_number')` returned
     * the deepest chain's head regardless of context. The resulting row hashed
     * correctly, did not collide with the per-context UNIQUE, and was therefore
     * INSERTed silently: a numeric gap in this context plus a `previous_hash`
     * pointing into the other chain, permanently unverifiable and invisible
     * until someone ran the verifier.
     *
     * The prior row is RETURNED, not just consumed, because the caller needs it
     * to derive the integrity verdict (ES-09 defect (b)) rather than assert one.
     *
     * @return array{0: int, 1: string, 2: stdClass|null} [sequenceNumber, previousHash, priorHead]
     */
    private function resolveChainPlacement(
        string $tenantId,
        string $companyId,
        string $terminalId,
        string $chainContext,
        string $genesisSeed,
    ): array {
        /** @var stdClass|null $prior */
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('terminal_id', $terminalId)
            ->where('chain_context', $chainContext)
            ->orderByDesc('sequence_number')
            ->first(['sequence_number', 'current_hash', 'event_time_device']);

        if ($prior === null) {
            return [1, $genesisSeed, null];
        }

        $sequenceNumber = is_int($prior->sequence_number)
            ? $prior->sequence_number + 1
            : ((int) $prior->sequence_number) + 1;
        $previousHash = is_string($prior->current_hash)
            ? $prior->current_hash
            : (string) $prior->current_hash;

        return [$sequenceNumber, $previousHash, $prior];
    }

    /**
     * Canonical-JSON encode a value per spec §4 (RFC 8785 / JCS shape):
     * sort object keys at every depth, preserve list ordering, no
     * insignificant whitespace, JSON_UNESCAPED_SLASHES + UNESCAPED_UNICODE.
     *
     * Local to this service rather than reaching across to the POS
     * module's `CanonicalJsonEncoder` — the POS encoder is internal to
     * POS's v3 receipt-hash path and the Fiscal module deliberately owns
     * no inbound coupling to POS operational code (SoT §13.6/D16). The
     * terminals list values are scalars (string / bool / int / null), so
     * the JSON-encode produces byte-identical output to a full JCS
     * implementation for this payload shape.
     */
    private function canonicalEncode(mixed $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
