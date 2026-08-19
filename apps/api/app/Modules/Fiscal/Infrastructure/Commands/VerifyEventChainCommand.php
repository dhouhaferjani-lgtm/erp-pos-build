<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Application\DTOs\ParseResult;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Application\Services\StrictCanonicalParser;
use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Throwable;

/**
 * `fiscal:verify-event-chain` — spec v7 §12 (plan §31).
 *
 * Operator + CI command that walks `fiscal_events` for a single terminal in
 * `sequence_number` order and asserts the hash chain is intact end-to-end.
 *
 * For each event the command:
 *
 *   1. Re-hashes `canonical_bytes` via the bound
 *      `FiscalIntegrityProvider` (Task 6 — `HashChainIntegrityProvider` in
 *      Phase 1) and asserts the recomputed digest matches the stored
 *      `current_hash`. A mismatch means the row's canonical bytes or its
 *      stored hash were corrupted post-insert (Task 8 trigger forbids
 *      both columns from changing on UPDATE, so the corruption necessarily
 *      predates the row's commit).
 *
 *   2. Asserts `previous_hash` of event N equals `current_hash` of event
 *      N-1, and the first event's `previous_hash` equals the terminal's
 *      `pos_terminals.genesis_seed`.
 *
 *   3. Also queries `fiscal_event_quarantine` for the terminal and reports
 *      each unresolved row as a chain incident, surfacing the spec §8
 *      column set: `claimed_sequence_number`, `envelope_event_id`,
 *      `current_hash`, `conflicting_event_id`.
 *
 * **Exit codes.**
 *   - 0 — chain verified, no incidents
 *   - 1 — a chain break OR a quarantine incident, OR permission denied /
 *         validation error (missing actor, unknown user, missing
 *         `--tenant`/`--terminal`)
 *   - 2 — transient failure (DB unreachable / query raised)
 *
 * **Permission gate.** Spec §12 names `fiscal.events.verify_chain`.
 * Console commands run system-scoped without an authenticated request user,
 * so the command requires `--actor-id={uuid}` and checks the permission
 * against that user. Mirrors the convention established by
 * `EnqueueResolvedEventProjectionsCommand` (Task 24). Spatie permissions
 * are tenant-team-scoped (`config/permission.php`); the registrar is
 * re-scoped to the actor's `tenant_id` inside a try/finally before calling
 * `can()`, then restored — the round-2 lesson from Task 24 (T24-P1).
 *
 * **Fail-closed on downstream-service exceptions (Task 18 F1 standing
 * pattern).** If the DB query raises mid-walk, the command logs critical,
 * surfaces a transient failure (exit 2), and exits — never crashes the
 * operator's terminal mid-report.
 *
 * **Tenant-isolation: cat-(a-per-tenant-iter) with a REQUIRED single-tenant
 * filter, converted 2026-08-05 (cat-(b) wave 2).** `--tenant` used to be a
 * WHERE predicate only: the command stayed on the console's CENTRAL connection
 * and added `where('tenant_id', …)` to queries against `fiscal_events`,
 * `fiscal_event_quarantine`, `pos_terminals` and `users` — all TENANT tables.
 * After the 2026-05-28 database-per-tenant flip none of them exist there, so
 * the launch program's canonical chain verifier raised 42P01 on every run. The
 * option now BINDS tenancy (which is what the operator always believed it
 * did); the `tenant_id` predicates are kept because they are load-bearing in
 * single-schema compatibility mode.
 *
 * The actor lookup and the Spatie permission check moved INSIDE the tenancy
 * binding — `users` is a tenant table, so the gate itself was unreadable from
 * central.
 *
 * This command deliberately remains single-chain: the gate is anchored on an
 * actor who exists in exactly one tenant, so "verify every tenant with this
 * actor" has no coherent meaning. Fleet coverage is provided separately by
 * `fiscal:verify-event-chain-fleet`, whose explicit tenant-to-actor manifest
 * delegates every enumerated chain back to this command without weakening the
 * actor gate or tenant binding.
 */
final class VerifyEventChainCommand extends AuthorizedFiscalChainCommand
{
    /**
     * Exact legacy server-authored envelope shape. These services shipped
     * canonical bytes before `chain_context` became a sealed envelope field.
     * No device-authored type and no other omission is compatible.
     *
     * @var list<string>
     */
    private const LEGACY_SERVER_ENVELOPE_KEYS = [
        'business_date',
        'company_id',
        'event_time_device',
        'event_type',
        'event_version',
        'operator_id',
        'payload',
        'previous_hash',
        'reference_document_id',
        'reference_event_id',
        'sequence_number',
        'signature_version',
        'tenant_id',
        'terminal_id',
    ];

    /** @var string */
    protected $signature = 'fiscal:verify-event-chain '.
        '{--tenant= : tenant_id of the chain to verify (required)} '.
        '{--terminal= : terminal_id of the chain to verify (required)} '.
        '{--chain-context=operational : fiscal chain context to verify} '.
        '{--from-sequence= : start the walk at this sequence_number (default: 1)} '.
        '{--actor-id= : authenticated user id performing the action (required for the permission gate)}';

    /** @var string */
    protected $description = 'Verify the fiscal-events hash chain for one terminal (spec §12).';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DatabaseManager $databaseManager,
        private readonly FiscalIntegrityProvider $integrityProvider,
        PermissionRegistrar $permissionRegistrar,
        private readonly StrictCanonicalParser $canonicalParser,
        private readonly FiscalPayloadConstraintValidator $payloadValidator,
    ) {
        parent::__construct($companyContext, $permissionRegistrar);
    }

    /**
     * The connection resolved at CALL time.
     *
     * A `ConnectionInterface` captured in the constructor is pinned to the
     * central connection: `tenancy()->initialize()` purges the `tenant`
     * connection and re-points `database.default`, so only a resolution made
     * after the switch reaches the tenant's database.
     */
    private function db(): ConnectionInterface
    {
        return $this->databaseManager->connection();
    }

    protected function executeCommand(): int
    {
        // ---- Option validation (no DB access; every one of these is a
        // validation error, exit 1, before any tenancy is bound) ----
        $actorId = $this->option('actor-id');
        if (! is_string($actorId) || $actorId === '') {
            $this->error('Missing --actor-id flag; required for fiscal.events.verify_chain gate.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        if (! is_string($tenantId) || $tenantId === '') {
            $this->error('Missing --tenant option; required to scope the chain walk.');

            return self::FAILURE;
        }

        $terminalId = $this->option('terminal');
        if (! is_string($terminalId) || $terminalId === '') {
            $this->error('Missing --terminal option; required to scope the chain walk.');

            return self::FAILURE;
        }

        $fromSequence = $this->resolveFromSequence();
        if ($fromSequence === false) {
            $this->error('--from-sequence must be a positive integer.');

            return self::FAILURE;
        }

        $chainContext = $this->option('chain-context');
        if (! is_string($chainContext) || ! in_array($chainContext, FiscalEventEnvelope::CHAIN_CONTEXTS, true)) {
            $this->error(sprintf(
                '--chain-context must be one of [%s].',
                implode(', ', FiscalEventEnvelope::CHAIN_CONTEXTS),
            ));

            return self::FAILURE;
        }

        // Narrowed in the DIRECTORY QUERY, not inside the closure (2026-08-05
        // wave-2 review, fiscal R3 / tenancy R1): an unrelated tenant's probe
        // or `initialize()` fault must not degrade this run's verdict, because
        // this command's exit codes distinguish "validation error" (1) from
        // "transient failure" (2) and a stranger's infra fault is neither.
        $exit = $this->forEachTenantNarrowed(
            $tenantId,
            fn (Tenant $tenant): int => $this->verifyBoundTenant(
                $actorId,
                $tenantId,
                $terminalId,
                $chainContext,
                $fromSequence,
            ),
        );

        if ($this->failIfTenantFilterUnvisited($tenantId) !== null) {
            // failIfTenantFilterUnvisited already printed the reason. Its own
            // INVALID (2) is remapped: exit 2 is reserved by this command's
            // documented contract for a TRANSIENT failure ("operator re-runs
            // to retry"), and a tenant absent from the directory is not
            // transient — it is a validation error, exit 1.
            return self::FAILURE;
        }

        return $exit;
    }

    /**
     * The permission gate and the chain walk, both running inside the bound
     * tenant's database.
     */
    private function verifyBoundTenant(
        string $actorId,
        string $tenantId,
        string $terminalId,
        string $chainContext,
        int $fromSequence,
    ): int {
        $authorizationExit = $this->authorizeBoundTenantActor($actorId, $tenantId);
        if ($authorizationExit !== self::SUCCESS) {
            return $authorizationExit;
        }

        // ---- Walk the chain ----
        try {
            // Fail closed on a terminal the bound tenant does not own
            // (2026-08-05 cat-(b) wave 2). Without this the walk simply
            // matched zero rows and the command reported
            // "chain verified — … 0 events walked" with exit 0 — a launch
            // verifier declaring a chain intact when it never found the chain.
            if (! $this->terminalExistsInBoundTenant($tenantId, $terminalId)) {
                $this->error(sprintf(
                    'Terminal %s does not exist in tenant %s; nothing was verified.',
                    $terminalId,
                    $tenantId,
                ));

                return self::FAILURE;
            }

            $incidents = $this->walkChain($tenantId, $terminalId, $chainContext, $fromSequence);
            $quarantineIncidents = $this->reportQuarantineIncidents($tenantId, $terminalId, $chainContext);
        } catch (Throwable $e) {
            // Fail-closed (Task 18 F1) — a DB outage mid-walk is a
            // transient failure. Surface as exit 2 so an automation
            // layer can distinguish "did nothing" from "did partial
            // work".
            Log::critical(
                'VerifyEventChainCommand: DB query raised mid-walk; surfacing as transient failure.',
                [
                    'tenant_id' => $tenantId,
                    'terminal_id' => $terminalId,
                    'chain_context' => $chainContext,
                    'from_sequence' => $fromSequence,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
            $this->error(sprintf('Chain walk failed: %s', $e->getMessage()));

            return 2;
        }

        $totalIncidents = count($incidents) + count($quarantineIncidents);
        if ($totalIncidents === 0) {
            $this->info(sprintf(
                'chain verified — terminal %s, tenant %s, context %s, %d events walked from sequence %d, no quarantine incidents.',
                $terminalId,
                $tenantId,
                $chainContext,
                $this->lastWalkedCount,
                $fromSequence,
            ));

            return self::SUCCESS;
        }

        // Print every chain incident first, then every quarantine
        // incident. Use `error()` for the structured break-point lines
        // so they hit stderr per the spec contract ("with the
        // sequence_number and expected-vs-actual hash on stderr").
        foreach ($incidents as $incident) {
            $this->error($incident);
        }
        foreach ($quarantineIncidents as $incident) {
            $this->error($incident);
        }

        $this->error(sprintf(
            'chain NOT verified — terminal %s, tenant %s, context %s: %d chain incidents, %d quarantine incidents.',
            $terminalId,
            $tenantId,
            $chainContext,
            count($incidents),
            count($quarantineIncidents),
        ));

        return self::FAILURE;
    }

    /** Tracks how many `fiscal_events` rows the latest walk inspected. */
    private int $lastWalkedCount = 0;

    /**
     * The `tenant_id` predicate is redundant once tenancy is bound and
     * load-bearing in single-schema compatibility mode, where one shared
     * database holds every tenant's terminals.
     */
    private function terminalExistsInBoundTenant(string $tenantId, string $terminalId): bool
    {
        return $this->db()->table('pos_terminals')
            ->where('id', $terminalId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Walk `fiscal_events` for the (tenant, terminal) in
     * `sequence_number` order and return a list of human-readable chain
     * incident strings (one per break).
     *
     * @return list<string>
     */
    private function walkChain(string $tenantId, string $terminalId, string $chainContext, int $fromSequence): array
    {
        $incidents = [];

        $rows = FiscalEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->where('chain_context', $chainContext)
            ->where('sequence_number', '>=', $fromSequence)
            ->orderBy('sequence_number')
            ->get([
                'id',
                'tenant_id',
                'company_id',
                'terminal_id',
                'operator_id',
                'event_type',
                'event_version',
                'signature_version',
                'sequence_number',
                'event_time_device',
                'business_date',
                'chain_context',
                'reference_event_id',
                'reference_document_id',
                'source_event_class',
                'source_event_id',
                'canonical_bytes',
                'previous_hash',
                'current_hash',
                'signature_status',
                'integrity_status',
                'payload',
                'payload_parse_status',
            ]);

        $this->lastWalkedCount = $rows->count();

        if ($rows->isEmpty()) {
            return $incidents;
        }

        // Resolve the "expected previous_hash" for the first row in the
        // walk. If we are starting from sequence 1, it must equal the
        // terminal's `genesis_seed`. Otherwise it must equal the
        // `current_hash` of the row at (fromSequence - 1).
        $expectedPrevious = $this->resolveExpectedPreviousHash($tenantId, $terminalId, $chainContext, $fromSequence);
        $expectedSequence = $fromSequence;

        foreach ($rows as $row) {
            // (1) Sequence coordinates are independently contiguous. Hash
            // linkage alone cannot prove this: rows 1 and 3 can link cleanly
            // while sequence 2 is absent.
            if ($row->sequence_number !== $expectedSequence) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): sequence_number gap — expected %d, stored %d',
                    $row->sequence_number,
                    $row->id,
                    $expectedSequence,
                    $row->sequence_number,
                );
            }
            $expectedSequence = $row->sequence_number + 1;

            // (2) Re-hash check.
            $canonicalBytes = $this->stringifyCanonicalBytes($row->canonical_bytes);
            $rehashed = $this->integrityProvider->computeHash($canonicalBytes);
            if (! hash_equals(strtolower($rehashed), strtolower($row->current_hash))) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): current_hash mismatch — expected %s, stored %s',
                    $row->sequence_number,
                    $row->id,
                    $rehashed,
                    $row->current_hash,
                );
            }

            // (3) A row that was quarantined or otherwise not verified is an
            // incident even when its byte hash and linkage remain intact.
            if ($row->integrity_status !== IntegrityStatus::Verified) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): integrity_status is %s, expected verified',
                    $row->sequence_number,
                    $row->id,
                    $row->integrity_status->value,
                );
            }

            // (4) Independently derive the envelope from frozen canonical
            // bytes for every row, including rows whose projection payload is
            // still pending. Projection status controls only the semantic
            // comparison against the mutable `payload` column; it cannot
            // weaken verification of the sealed coordinates.
            $parsed = $this->parseCanonicalForVerification($canonicalBytes, $row);

            if (! $parsed->ok || $parsed->envelope === null) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): sealed coordinates could not be derived from canonical_bytes (%s)',
                    $row->sequence_number,
                    $row->id,
                    $parsed->failureReason ?? 'unknown parse failure',
                );

                // ES-06 (M3). `ParseFailureResolutionService` is the only
                // post-seal payload-write surface the immutability trigger
                // permits, and every row it can touch is by definition one
                // the strict parser ALREADY rejected — so this branch, not
                // the semantic comparison below, is the branch the ES-06
                // mutation actually lands in. Emitting one fixed sentence
                // here made the verifier indiscriminate on exactly that
                // surface: a faithful correction and a wholesale money
                // rewrite produced identical output.
                //
                // The sealed payload is still recoverable whenever the frozen
                // envelope is unambiguous JSON carrying a `payload` member —
                // the strict parser can reject an envelope for a reason that
                // has nothing to do with the payload (an unknown device
                // field, a version the server does not know). When it IS
                // recoverable we say what is true; when it is not we keep the
                // original fail-closed sentence. The sealed-coordinate
                // incident above is unconditional either way, so the exit
                // code never softens.
                if ($row->payload_parse_status === PayloadParseStatus::Parsed) {
                    $sealedPayload = $this->recoverSealedPayloadFromFrozenBytes($canonicalBytes);

                    if ($sealedPayload === null) {
                        $incidents[] = sprintf(
                            'CHAIN BREAK at sequence_number %d (id %s): payload does not semantically match canonical_bytes — canonical payload could not be derived (%s)',
                            $row->sequence_number,
                            $row->id,
                            $parsed->failureReason ?? 'unknown parse failure',
                        );
                    } elseif (! is_array($row->payload) || ! $this->semanticallyEqual($row->payload, $sealedPayload)) {
                        $incidents[] = sprintf(
                            'CHAIN BREAK at sequence_number %d (id %s): payload does not semantically match canonical_bytes — the sealed payload was recovered from the frozen envelope and disagrees with the stored payload',
                            $row->sequence_number,
                            $row->id,
                        );
                    }
                }
            } else {
                foreach ($this->sealedCoordinateMismatches($row, $parsed->envelope) as $mismatch) {
                    $incidents[] = sprintf(
                        'CHAIN BREAK at sequence_number %d (id %s): sealed coordinate %s',
                        $row->sequence_number,
                        $row->id,
                        $mismatch,
                    );
                }

                if ($row->payload_parse_status === PayloadParseStatus::Parsed) {
                    if ($parsed->payload === null || ! is_array($row->payload) || ! $this->semanticallyEqual($row->payload, $parsed->payload)) {
                        $incidents[] = sprintf(
                            'CHAIN BREAK at sequence_number %d (id %s): payload does not semantically match canonical_bytes',
                            $row->sequence_number,
                            $row->id,
                        );
                    }
                }
            }

            // (5) Link check — previous_hash must equal the expected
            // chain head. The expected head is either the terminal's
            // genesis seed (first event) or the prior row's
            // `current_hash`.
            if ($expectedPrevious === null) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): expected previous_hash could not be resolved (terminal genesis_seed unavailable).',
                    $row->sequence_number,
                    $row->id,
                );
            } elseif (! hash_equals(strtolower($expectedPrevious), strtolower($row->previous_hash))) {
                $incidents[] = sprintf(
                    'CHAIN BREAK at sequence_number %d (id %s): previous_hash linkage mismatch — expected %s (%s), stored %s',
                    $row->sequence_number,
                    $row->id,
                    $expectedPrevious,
                    $row->sequence_number === 1 ? 'terminal genesis_seed' : 'prior row current_hash',
                    $row->previous_hash,
                );
            }

            // Chain forward: next row's expected previous_hash is THIS
            // row's stored current_hash (we use the stored value, not
            // the rehash — the verifier reports both kinds of break
            // independently per spec §12).
            $expectedPrevious = $row->current_hash;
        }

        return $incidents;
    }

    /**
     * Preserve strict parser semantics while recognizing the one historical
     * production shape authored by three server-side services. The original
     * envelope is returned so the coordinate comparison checks only values
     * that were actually sealed; the synthetic context exists solely to run
     * the current DTO/key-set/constraint parser over the legacy bytes.
     */
    private function parseCanonicalForVerification(string $canonicalBytes, FiscalEvent $row): ParseResult
    {
        $strict = $this->canonicalParser->parse($canonicalBytes, $row->event_type);
        if ($strict->failureReason !== 'envelope_field_missing:chain_context'
            || ! $this->isSanctionedLegacyServerRow($row)) {
            return $strict;
        }

        try {
            $legacyEnvelope = json_decode($canonicalBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $strict;
        }

        if (! is_array($legacyEnvelope) || array_is_list($legacyEnvelope)) {
            return $strict;
        }

        $actualKeys = array_keys($legacyEnvelope);
        sort($actualKeys);
        if ($actualKeys !== self::LEGACY_SERVER_ENVELOPE_KEYS) {
            return $strict;
        }

        $syntheticEnvelope = $legacyEnvelope;
        $syntheticEnvelope['chain_context'] = $row->chain_context;
        ksort($syntheticEnvelope);
        $syntheticBytes = json_encode($syntheticEnvelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $validated = $this->canonicalParser->parse($syntheticBytes, $row->event_type);

        if (! $validated->ok || $validated->payload === null) {
            if (! $this->validateLegacyDepositReceipt($row, $syntheticEnvelope, $validated)) {
                return $strict;
            }

            /** @var array<string, mixed> $payload */
            $payload = $syntheticEnvelope['payload'];

            return ParseResult::ok($payload, $legacyEnvelope);
        }

        return ParseResult::ok($validated->payload, $legacyEnvelope);
    }

    /**
     * `DEPOSIT_RECEIPT` is server-only and is intentionally absent from the
     * device parser's operational event allowlist. Apply its current DTO and
     * constraint validators directly rather than weakening that device gate.
     *
     * @param  array<string, mixed>  $syntheticEnvelope
     */
    private function validateLegacyDepositReceipt(
        FiscalEvent $row,
        array $syntheticEnvelope,
        ParseResult $validated,
    ): bool {
        if ($row->event_type !== FiscalEventType::DEPOSIT_RECEIPT
            || $validated->failureReason !== 'envelope_chain_context_event_type_mismatch:event_type=DEPOSIT_RECEIPT,chain_context=operational') {
            return false;
        }

        $payload = $syntheticEnvelope['payload'] ?? null;
        if (! is_array($payload) || array_is_list($payload)) {
            return false;
        }

        try {
            DepositReceiptPayload::fromArray($payload);
            $keySetError = $this->payloadValidator->validatePayloadKeySet(
                FiscalEventType::DEPOSIT_RECEIPT,
                $payload,
                'operational',
                1,
            );
            if ($keySetError !== null) {
                return false;
            }
            $this->payloadValidator->validatePerEventConstraints(
                FiscalEventType::DEPOSIT_RECEIPT,
                $payload,
                'operational',
                1,
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function isSanctionedLegacyServerRow(FiscalEvent $row): bool
    {
        return in_array($row->event_type, [
            FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT,
            FiscalEventType::ACCOUNT_STATUS_CHANGED,
            FiscalEventType::DEPOSIT_RECEIPT,
        ], true)
            && $row->event_version === 1
            && $row->chain_context === 'operational'
            && $row->signature_status === SignatureStatus::NotRequired
            && $row->integrity_status === IntegrityStatus::Verified
            && $row->payload_parse_status === PayloadParseStatus::Parsed
            && $row->reference_event_id === null
            && $row->reference_document_id === null
            && $row->source_event_class === null
            && $row->source_event_id === null;
    }

    /**
     * ES-06 detection support — recover the SEALED payload from frozen
     * canonical bytes the strict parser rejected.
     *
     * Recovery is only trusted when the bytes are UNAMBIGUOUS: they must
     * decode as a JSON object AND re-encode byte-identically. That
     * round-trip is what rules out the one way a lenient decode could
     * disagree with the strict parser about what the bytes say — duplicate
     * keys, where `json_decode` silently keeps the last occurrence while the
     * strict parser rejects the document outright (`duplicate_key:*`). If the
     * bytes are ambiguous in any way, or carry no `payload` object, this
     * returns null and the caller keeps the original fail-closed incident.
     *
     * The re-encode uses the CANONICAL flag set (`CanonicalJsonEncoder.php:106-108`,
     * RFC 8785 §3.2.3 — raw UTF-8 for U+0080+). The acceptance test is byte
     * identity against THIS re-encode, nothing more: any byte form this PHP
     * `json_encode` cannot reproduce returns null. State that precisely, because
     * the converse ("canonical forms recover") is what a maintainer would read
     * into a looser sentence, and it is false in both directions (M3 round-2
     * finding N-2, both counterexamples probed on PHP 8.4):
     *
     *   - Refused, and NOT canonical: `\uXXXX` escapes of U+0080+, insignificant
     *     whitespace, escaped slashes.
     *   - Refused, but IS canonical: an empty JSON object anywhere in the
     *     envelope. `CanonicalJsonEncoder::encode([])` deliberately emits `{}`
     *     (that is why `encode()`/`encodeList()` are split at all,
     *     `CanonicalJsonEncoder.php:13-16`), but `json_decode('{}', true)` yields
     *     `[]`, which re-encodes as `[]` — so the round trip cannot reproduce it.
     *     Fail-closed and narrow (the strict parser requires non-empty
     *     sub-objects, `StrictCanonicalParser.php:44-49`), but real.
     *   - Recovered, and IS canonical despite carrying an escape: U+2028 / U+2029.
     *     PHP escapes those two even under `JSON_UNESCAPED_UNICODE` unless
     *     `JSON_UNESCAPED_LINE_TERMINATORS` is added, so the real encoder emits
     *     the six-byte escape sequence for each of them and the guard
     *     reproduces it byte-for-byte. A `\uXXXX` escape is therefore not
     *     per se non-canonical. (Described rather than quoted on purpose: a
     *     literal U+2028 in this source file is itself a line terminator to
     *     some tooling.)
     *
     * This never widens what the verifier accepts: it only decides WHICH
     * incident sentence is true. The sealed-coordinate incident is raised
     * unconditionally before this is consulted.
     *
     * @return array<string, mixed>|null
     */
    private function recoverSealedPayloadFromFrozenBytes(string $canonicalBytes): ?array
    {
        try {
            $envelope = json_decode($canonicalBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($envelope) || array_is_list($envelope)) {
            return null;
        }

        try {
            // The flag set MUST mirror `CanonicalJsonEncoder::encodeString()`
            // (`CanonicalJsonEncoder.php:106-108`): RFC 8785 §3.2.3 seals U+0080+ as
            // RAW UTF-8, and PHP's default escapes it as `\uXXXX`. Omitting
            // `JSON_UNESCAPED_UNICODE` here made the byte-identity test below
            // unsatisfiable for every envelope carrying one accented or Arabic
            // character — i.e. most French and Tunisian receipts — so the
            // discriminating branch was dead on exactly the data it exists for.
            // Widening to the canonical flag set does not make the guard lenient:
            // byte identity is still the whole acceptance test, so bytes that carry
            // literal `\uXXXX` escapes now refuse instead, which is correct — the
            // canonical encoder cannot emit them.
            $roundTrip = json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (Throwable) {
            return null;
        }

        if ($roundTrip !== $canonicalBytes) {
            return null;
        }

        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload) || array_is_list($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Compare JSON-object semantics without treating object key order as data.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $sealed
     */
    private function semanticallyEqual(array $stored, array $sealed): bool
    {
        return $this->normalizeJsonObject($stored) === $this->normalizeJsonObject($sealed);
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function normalizeJsonObject(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeJsonObject($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $sealedEnvelope
     * @return list<string>
     */
    private function sealedCoordinateMismatches(FiscalEvent $row, array $sealedEnvelope): array
    {
        $isLegacyServerEnvelope = ! array_key_exists('chain_context', $sealedEnvelope);
        $storedCoordinates = [
            'business_date' => $row->business_date->format('Y-m-d'),
            'chain_context' => $row->chain_context,
            'company_id' => $row->company_id,
            // The legacy server services passed a UTC Carbon to a timestampTz
            // connection whose session timezone may be non-UTC. Eloquent
            // serialized the wall clock without an offset, so the historical
            // row retains the sealed wall time with the connection offset.
            // Compare that exact authored representation only on the narrowly
            // identified legacy path; current/device envelopes compare instants.
            'event_time_device' => $isLegacyServerEnvelope
                ? $row->event_time_device->format('Y-m-d\\TH:i:s\\Z')
                : $row->event_time_device->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'event_type' => $row->event_type->value,
            'event_version' => $row->event_version,
            'operator_id' => $row->operator_id,
            'previous_hash' => $row->previous_hash,
            'reference_document_id' => $row->reference_document_id,
            'reference_event_id' => $row->reference_event_id,
            'sequence_number' => $row->sequence_number,
            'signature_version' => $row->signature_version,
            'tenant_id' => $row->tenant_id,
            'terminal_id' => $row->terminal_id,
        ];

        $mismatches = [];
        foreach ($storedCoordinates as $field => $storedValue) {
            if (! array_key_exists($field, $sealedEnvelope)) {
                continue;
            }

            $sealedValue = $sealedEnvelope[$field] ?? null;
            if ($storedValue !== $sealedValue) {
                $mismatches[] = sprintf(
                    '%s mismatch — sealed %s, stored %s',
                    $field,
                    $this->formatCoordinateValue($sealedValue),
                    $this->formatCoordinateValue($storedValue),
                );
            }
        }

        return $mismatches;
    }

    private function formatCoordinateValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    /**
     * Resolve the expected `previous_hash` for the first row in the walk.
     *
     *   - If starting from sequence 1 (or no events earlier exist): the
     *     terminal's `pos_terminals.genesis_seed`.
     *   - Otherwise: the `current_hash` of the row at (fromSequence - 1).
     *
     * Returns `null` if the terminal cannot be located AND no prior row
     * exists — the caller surfaces that as a chain incident on the first
     * row.
     */
    private function resolveExpectedPreviousHash(string $tenantId, string $terminalId, string $chainContext, int $fromSequence): ?string
    {
        if ($fromSequence > 1) {
            /** @var stdClass|null $prior */
            $prior = $this->db()->table('fiscal_events')
                ->where('tenant_id', $tenantId)
                ->where('terminal_id', $terminalId)
                ->where('chain_context', $chainContext)
                ->where('sequence_number', $fromSequence - 1)
                ->first(['current_hash']);

            if ($prior !== null && is_string($prior->current_hash)) {
                return $prior->current_hash;
            }

            // No prior row at fromSequence-1: the operator asked for a
            // range that starts in the middle of nothing. Fall through
            // to the genesis seed as a best-effort anchor, which will
            // mismatch and surface as a chain incident — exactly the
            // signal the operator needs.
        }

        /** @var stdClass|null $terminal */
        $terminal = $this->db()->table('pos_terminals')
            ->where('id', $terminalId)
            ->first(['genesis_seed']);

        if ($terminal === null || ! is_string($terminal->genesis_seed)) {
            return null;
        }

        return $terminal->genesis_seed;
    }

    /**
     * Query `fiscal_event_quarantine` for the (tenant, terminal) and
     * return a list of human-readable incident strings (one per
     * unresolved row). Resolved rows (`resolved_at IS NOT NULL`) are
     * excluded — the verifier reports the live incident surface.
     *
     * @return list<string>
     */
    private function reportQuarantineIncidents(string $tenantId, string $terminalId, string $chainContext): array
    {
        $incidents = [];

        $rows = FiscalEventQuarantine::query()
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->where('chain_context', $chainContext)
            ->whereNull('resolved_at')
            ->orderBy('claimed_sequence_number')
            ->get([
                'envelope_event_id',
                'claimed_sequence_number',
                'chain_context',
                'current_hash',
                'conflicting_event_id',
                'integrity_exception_class',
                'integrity_exception_reason',
            ]);

        foreach ($rows as $row) {
            $incidents[] = sprintf(
                'QUARANTINE INCIDENT at chain_context %s claimed_sequence_number %d: class=%s, envelope_event_id=%s, current_hash=%s, conflicting_event_id=%s, reason=%s',
                $row->chain_context,
                $row->claimed_sequence_number,
                $row->integrity_exception_class->value,
                $row->envelope_event_id,
                $row->current_hash,
                $row->conflicting_event_id,
                $row->integrity_exception_reason,
            );
        }

        return $incidents;
    }

    /**
     * Parse the `--from-sequence` option to a positive int, defaulting
     * to 1 if absent. Returns `false` if a value was supplied that
     * cannot be coerced to a positive integer — the caller surfaces
     * that as a validation error.
     */
    private function resolveFromSequence(): int|false
    {
        $raw = $this->option('from-sequence');
        if ($raw === null || $raw === '') {
            return 1;
        }

        // Laravel's `option()` PHPDoc declares `string|null` for value-typed
        // options, but `Illuminate\Testing\PendingCommand` forwards numeric
        // option values as raw PHP scalars — so a test
        // `'--from-sequence' => 3` arrives as `int(3)`, not `"3"`. `strval`
        // is the load-bearing primitive here: it coerces both shapes to the
        // same digit string for `ctype_digit` without using a cast purely
        // to silence PHPStan (per the project's no-type-casts-to-silence
        // discipline; `strval` is a real coercion across both runtime
        // shapes).
        $rawString = strval($raw);
        if (! ctype_digit($rawString)) {
            // ctype_digit returns false for the empty string too, so this
            // branch also covers $raw shapes that strval'd to "".
            return false;
        }

        $value = (int) $rawString;

        return $value >= 1 ? $value : false;
    }

    /**
     * `canonical_bytes` is BYTEA on PG and BLOB on SQLite. Driver
     * upcasts vary — coerce to string for the hash primitive.
     */
    private function stringifyCanonicalBytes(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }
}
