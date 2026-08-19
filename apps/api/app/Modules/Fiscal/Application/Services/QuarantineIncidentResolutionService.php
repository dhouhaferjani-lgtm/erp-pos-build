<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Exceptions\QuarantineAlreadyResolvedException;
use App\Modules\Fiscal\Domain\Exceptions\QuarantineIncidentNotFoundException;
use App\Modules\Fiscal\Domain\Models\FiscalEventQuarantine;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * ES-17 — the missing writer for `fiscal_event_quarantine.resolved_at` /
 * `resolved_by`.
 *
 * **What this service records.** That an authorised operator has ADJUDICATED a
 * quarantine incident. `FiscalEventQuarantine.php:20-22` has always documented
 * the table as MUTABLE for exactly this ("the resolution flow writes
 * `resolved_at` / `resolved_by` when an admin clears the incident"), and both
 * columns were declared, cast and deliberately non-fillable — but nothing in
 * `app/` ever wrote them. `VerifyEventChainCommand.php:856` filters on
 * `whereNull('resolved_at')`, so a single `sequence_conflict` envelope made
 * `fiscal:verify-event-chain` return exit 1 for that terminal FOREVER: the only
 * condition that cleared the incident was a column with no writer. This service
 * is that writer, and nothing more.
 *
 * **What the stamp does NOT mean.** It is not a correctness claim about the
 * envelope. `sequence_conflict` means two different events claimed one sequence
 * slot; recording that a human looked at it says nothing about which was right.
 * Accordingly:
 *
 *   - The quarantined envelope is **never** admitted into `fiscal_events`. That
 *     would be a chain write into a slot that is by definition already
 *     occupied. ES-17 is adjudication, not ingestion.
 *   - The envelope columns (`canonical_bytes`, `current_hash`,
 *     `claimed_sequence_number`, `raw_envelope`) and the classification columns
 *     are untouched — the UPDATE below names exactly two columns.
 *   - The conflicting event occupying the slot is untouched.
 *   - The underlying sequence conflict is NOT resolved. Only the incident's
 *     adjudication is recorded.
 *
 * **Single-actor by design.** No approval / second-approver flow is built here.
 * That question is owner gate **D-8** and is out of this wave; if a lane later
 * rules that quarantine adjudication needs a second approver, this service is
 * where that lands — as a new decision, ruled first, not designed provisionally.
 *
 * **Explicit lifecycle code, never mass assignment** (clause 17-B). The two
 * columns stay out of `FiscalEventQuarantine::$fillable` (`:84-90`, the Task 9
 * boundary discipline); this service writes them through a targeted UPDATE that
 * names them, so no request-bound array can ever reach them.
 */
final class QuarantineIncidentResolutionService
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Stamp `resolved_at` + `resolved_by` on one quarantine row.
     *
     * Both columns are written in a SINGLE UPDATE (clause 17-A: "both non-null
     * in the same write, or neither — a half-stamped row is an incident state
     * nothing describes"), inside a transaction with `lockForUpdate()` so two
     * operators racing on the same incident cannot both pass the
     * already-resolved check and have the second silently overwrite the first's
     * `resolved_by`.
     *
     * @param  string  $quarantineId  the `fiscal_event_quarantine` row's UUID.
     *                                The CALLER is responsible for having resolved this id within the
     *                                acting user's tenant (clause 17-F) — the controller does so before
     *                                calling, and re-checks tenancy here as defence in depth.
     *
     * @throws QuarantineIncidentNotFoundException when no such row exists in
     *                                             the acting user's tenant (clause 17-F)
     * @throws QuarantineAlreadyResolvedException when the row already carries a
     *                                            resolution stamp (clause 17-G — refuse, never overwrite)
     */
    public function resolve(string $quarantineId, User $resolverUser): void
    {
        $this->db->transaction(function () use ($quarantineId, $resolverUser): void {
            /** @var FiscalEventQuarantine|null $incident */
            $incident = FiscalEventQuarantine::query()
                ->lockForUpdate()
                ->where('id', $quarantineId)
                ->where('tenant_id', $resolverUser->tenant_id)
                ->first();

            if ($incident === null) {
                throw new QuarantineIncidentNotFoundException(sprintf(
                    'fiscal_event_quarantine row %s was not found in this tenant.',
                    $quarantineId,
                ));
            }

            if ($incident->resolved_at !== null || $incident->resolved_by !== null) {
                throw new QuarantineAlreadyResolvedException(sprintf(
                    'fiscal_event_quarantine row %s was already adjudicated by %s; the original resolution stamp is not overwritten.',
                    $quarantineId,
                    $incident->resolved_by ?? 'an unknown operator',
                ));
            }

            // Exactly two columns, named explicitly. Bound to `$this->db` so the
            // UPDATE joins the surrounding transaction rather than the DEFAULT
            // connection — the same round-2 lesson `ParseFailureResolutionService`
            // carries (Task 24 Opus F3).
            $this->db->table('fiscal_event_quarantine')
                ->where('id', $quarantineId)
                ->update([
                    'resolved_at' => Carbon::now('UTC'),
                    'resolved_by' => $resolverUser->id,
                ]);
        });
    }
}
