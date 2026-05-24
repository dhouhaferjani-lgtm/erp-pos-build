<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Throwable;

/**
 * `fiscal:verify-event-chain` — spec v7 §12 (plan §31).
 *
 * @cross-tenant-by-design Operator/CI chain verification command is intentionally system-scoped and requires explicit --tenant and --terminal arguments before reading fiscal event chain rows.
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
 */
final class VerifyEventChainCommand extends Command
{
    /** @var string */
    protected $signature = 'fiscal:verify-event-chain '.
        '{--tenant= : tenant_id of the chain to verify (required)} '.
        '{--terminal= : terminal_id of the chain to verify (required)} '.
        '{--from-sequence= : start the walk at this sequence_number (default: 1)} '.
        '{--actor-id= : authenticated user id performing the action (required for the permission gate)}';

    /** @var string */
    protected $description = 'Verify the fiscal-events hash chain for one terminal (spec §12).';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalIntegrityProvider $integrityProvider,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ---- Permission gate (Task 24 standing pattern) ----
        $actorId = $this->option('actor-id');
        if (! is_string($actorId) || $actorId === '') {
            $this->error('Missing --actor-id flag; required for fiscal.events.verify_chain gate.');

            return self::FAILURE;
        }

        $actor = User::query()->find($actorId);
        if ($actor === null) {
            $this->error(sprintf('Unknown actor user id %s.', $actorId));

            return self::FAILURE;
        }

        // Re-scope the Spatie registrar to the actor's tenant before
        // checking `can()`. Mirrors EnqueueResolvedEventProjectionsCommand
        // (Task 24 R2 — T24-P1). Always restored in finally per the
        // Task 18 F1 / Task 23 R3-F2 try/finally discipline.
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        try {
            $this->permissionRegistrar->setPermissionsTeamId($actor->tenant_id);

            if (! $actor->can('fiscal.events.verify_chain')) {
                $this->error(sprintf(
                    'Actor %s lacks the fiscal.events.verify_chain permission.',
                    $actorId,
                ));

                return self::FAILURE;
            }
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
        }

        // ---- Required identifiers ----
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

        // ---- Walk the chain ----
        try {
            $incidents = $this->walkChain($tenantId, $terminalId, $fromSequence);
            $quarantineIncidents = $this->reportQuarantineIncidents($tenantId, $terminalId);
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
                'chain verified — terminal %s, tenant %s, %d events walked from sequence %d, no quarantine incidents.',
                $terminalId,
                $tenantId,
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
            'chain NOT verified — terminal %s, tenant %s: %d chain incidents, %d quarantine incidents.',
            $terminalId,
            $tenantId,
            count($incidents),
            count($quarantineIncidents),
        ));

        return self::FAILURE;
    }

    /** Tracks how many `fiscal_events` rows the latest walk inspected. */
    private int $lastWalkedCount = 0;

    /**
     * Walk `fiscal_events` for the (tenant, terminal) in
     * `sequence_number` order and return a list of human-readable chain
     * incident strings (one per break).
     *
     * @return list<string>
     */
    private function walkChain(string $tenantId, string $terminalId, int $fromSequence): array
    {
        $incidents = [];

        $rows = FiscalEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->where('sequence_number', '>=', $fromSequence)
            ->orderBy('sequence_number')
            ->get([
                'id',
                'sequence_number',
                'canonical_bytes',
                'previous_hash',
                'current_hash',
            ]);

        $this->lastWalkedCount = $rows->count();

        if ($rows->isEmpty()) {
            return $incidents;
        }

        // Resolve the "expected previous_hash" for the first row in the
        // walk. If we are starting from sequence 1, it must equal the
        // terminal's `genesis_seed`. Otherwise it must equal the
        // `current_hash` of the row at (fromSequence - 1).
        $expectedPrevious = $this->resolveExpectedPreviousHash($tenantId, $terminalId, $fromSequence);

        foreach ($rows as $row) {
            // (1) Re-hash check.
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

            // (2) Link check — previous_hash must equal the expected
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
    private function resolveExpectedPreviousHash(string $tenantId, string $terminalId, int $fromSequence): ?string
    {
        if ($fromSequence > 1) {
            /** @var stdClass|null $prior */
            $prior = $this->db->table('fiscal_events')
                ->where('tenant_id', $tenantId)
                ->where('terminal_id', $terminalId)
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
        $terminal = $this->db->table('pos_terminals')
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
    private function reportQuarantineIncidents(string $tenantId, string $terminalId): array
    {
        $incidents = [];

        $rows = FiscalEventQuarantine::query()
            ->where('tenant_id', $tenantId)
            ->where('terminal_id', $terminalId)
            ->whereNull('resolved_at')
            ->orderBy('claimed_sequence_number')
            ->get([
                'envelope_event_id',
                'claimed_sequence_number',
                'current_hash',
                'conflicting_event_id',
                'integrity_exception_class',
                'integrity_exception_reason',
            ]);

        foreach ($rows as $row) {
            $incidents[] = sprintf(
                'QUARANTINE INCIDENT at claimed_sequence_number %d: class=%s, envelope_event_id=%s, current_hash=%s, conflicting_event_id=%s, reason=%s',
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
