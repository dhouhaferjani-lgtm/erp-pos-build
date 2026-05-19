<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\TerminalRegistrySnapshotPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * Server-authored `TERMINAL_REGISTRY_SNAPSHOT` emitter (spec v7 §11).
 *
 * **Why server-authored.** Spec §11 names `TERMINAL_REGISTRY_SNAPSHOT`
 * and `COMPANY_DAY_CLOSURE_MANIFEST` "company-level integrity record
 * types" — they are operator/company-level facts (the authoritative
 * roster of terminals expected for a company, the day-closure manifest),
 * not per-terminal transactions. They sit alongside the device-authority
 * pattern (§1 / D1) which governs `SALE_RECEIPT` + chain-recovery events;
 * the snapshot can be emitted at terminal provisioning or on demand from
 * a server-side operator path, so a server-authored emission path is
 * required. The emitted event still lands in `fiscal_events` and links
 * forensically into the terminal chain (sequence_number, previous_hash,
 * current_hash) so the per-terminal verifier (Task 31) can prove the
 * snapshot's place in the timeline.
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
 * `FiscalServiceProvider::register()`). Both are Laravel-resolvable, so
 * no provider edit needed (Task 26 plan note: "if `TerminalRegistrySnapshotService`
 * needs an explicit binding, add it, otherwise no provider edit").
 */
final class TerminalRegistrySnapshotService
{
    /** `not_required` is the Phase 1 default — no signature provider until later phases. */
    private const SERVER_AUTHORED_SIGNATURE_VERSION = 'hash-chain-integrity-v1';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalIntegrityProvider $integrity,
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
        /** @var stdClass|null $terminal */
        $terminal = $this->db->table('pos_terminals')
            ->where('id', $terminalId)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->first(['id', 'genesis_seed']);

        if ($terminal === null) {
            throw new RuntimeException(sprintf(
                'TerminalRegistrySnapshotService: cannot emit snapshot — authoring terminal_id %s not found '.
                'in pos_terminals for tenant=%s company=%s. Resolve a real terminal before calling emitInitialSnapshot().',
                $terminalId,
                $tenantId,
                $companyId,
            ));
        }
        $genesisSeed = is_string($terminal->genesis_seed)
            ? $terminal->genesis_seed
            : (string) $terminal->genesis_seed;

        // --- Step 1: build the authoritative terminals list (deterministic order).
        $terminals = $this->loadTerminalsForCompany($tenantId, $companyId);

        // --- Step 2: hash the canonical terminals list — the §11 snapshot anchor.
        $snapshotHash = $this->integrity->computeHash($this->canonicalEncode($terminals));

        // --- Step 3: link to the prior snapshot for this company (if any).
        $priorSnapshotLink = $this->findPriorSnapshotHash($tenantId, $companyId);

        $payloadDto = new TerminalRegistrySnapshotPayload(
            terminals: $terminals,
            snapshotHash: $snapshotHash,
            priorSnapshotLink: $priorSnapshotLink,
        );
        $payloadArray = $payloadDto->toArray();

        // --- Step 4: resolve chain placement on the authoring terminal.
        [$sequenceNumber, $previousHash] = $this->resolveChainPlacement(
            tenantId: $tenantId,
            terminalId: $terminalId,
            genesisSeed: $genesisSeed,
        );

        // --- Step 5: build the canonical envelope bytes per spec §4 (14 keys).
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

        // --- Step 6: persist the row.
        /** @var FiscalEvent $event */
        $event = FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $operatorId,
            'event_type' => FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
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
     * @return array{0: int, 1: string} [sequenceNumber, previousHash]
     */
    private function resolveChainPlacement(string $tenantId, string $terminalId, string $genesisSeed): array
    {
        /** @var stdClass|null $prior */
        $prior = $this->db->table('fiscal_events')
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->orderByDesc('sequence_number')
            ->first(['sequence_number', 'current_hash']);

        if ($prior === null) {
            return [1, $genesisSeed];
        }

        $sequenceNumber = is_int($prior->sequence_number)
            ? $prior->sequence_number + 1
            : ((int) $prior->sequence_number) + 1;
        $previousHash = is_string($prior->current_hash)
            ? $prior->current_hash
            : (string) $prior->current_hash;

        return [$sequenceNumber, $previousHash];
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
