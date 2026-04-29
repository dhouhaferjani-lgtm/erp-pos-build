<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Exceptions\FiscalSchemaCutoverBlockedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use Illuminate\Support\Facades\DB;

/**
 * Per-terminal fiscal-schema cutover service (v2 → v3).
 *
 * Performs a gated transition of a terminal's `fiscal_schema_version` from 2 to 3.
 * All four pre-conditions must pass atomically (via FOR UPDATE) before the version
 * is persisted. Concurrent cutover attempts on the same terminal serialize via
 * the row-level lock so exactly one request wins.
 *
 * Pre-conditions:
 *   1. Caller holds `pos.fiscal_schema_cutover` permission (enforced by controller).
 *   2. Terminal has no open shift.
 *   3. Terminal has no un-Z-reported fiscalized receipts (all receipts since the
 *      last Z-report must be covered).
 *   4. Terminal's offline-sync queue is empty (no pos_receipts with
 *      fiscal_status = pending_seal AND synced_at IS NULL for this terminal).
 */
final class FiscalSchemaCutoverService
{
    /**
     * Attempt the v3 cutover for $terminal.
     *
     * Acquires a FOR UPDATE lock on the terminal row, runs all four gate checks,
     * then sets `fiscal_schema_version = 3` and writes an audit row.
     *
     * @throws FiscalSchemaCutoverBlockedException when any gate fails
     */
    public function cutover(Terminal $terminal, User $performedBy): void
    {
        DB::transaction(function () use ($terminal, $performedBy): void {
            // Lock the terminal row for the duration of the transaction so that
            // concurrent cutover requests serialize and exactly one wins.
            /** @var Terminal|null $locked */
            $locked = Terminal::query()
                ->where('id', $terminal->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                // Terminal was deleted concurrently — nothing to do.
                return;
            }

            // Fast-path: already at v3.
            if ((int) $locked->fiscal_schema_version >= 3) {
                throw FiscalSchemaCutoverBlockedException::alreadyAtV3((string) $locked->id);
            }

            // Gate 1: no open shift.
            $openShift = Shift::where('terminal_id', $locked->id)
                ->whereNull('closed_at')
                ->exists();

            if ($openShift) {
                throw FiscalSchemaCutoverBlockedException::openShift((string) $locked->id);
            }

            // Gate 2: no un-Z-reported fiscalized receipts.
            // Find the latest Z-report for this terminal to determine the coverage boundary.
            $lastZReport = ZReport::where('terminal_id', $locked->id)
                ->orderByDesc('z_number')
                ->first();

            $unzReportedExists = false;

            if ($lastZReport !== null) {
                // Any fiscalized receipt posted AFTER the last Z-report was generated is un-covered.
                // ZReport uses generated_at (no timestamps/created_at).
                $unzReportedExists = Receipt::where('terminal_id', $locked->id)
                    ->where('fiscal_status', FiscalStatus::Fiscalized->value)
                    ->where('is_voided', false)
                    ->where('is_training', false)
                    ->where('posted_at', '>', $lastZReport->generated_at)
                    ->exists();
            } else {
                // No Z-reports at all — any fiscalized receipt on this terminal is un-covered.
                $unzReportedExists = Receipt::where('terminal_id', $locked->id)
                    ->where('fiscal_status', FiscalStatus::Fiscalized->value)
                    ->where('is_voided', false)
                    ->where('is_training', false)
                    ->exists();
            }

            if ($unzReportedExists) {
                throw FiscalSchemaCutoverBlockedException::unzreportedReceipts((string) $locked->id);
            }

            // Gate 3: offline-sync queue must be empty.
            // The offline queue covers receipts in either pending_seal (created offline,
            // not yet synced to server) or pending_sync (on server queue, not yet fiscalized)
            // states, excluding training receipts.
            $pendingSyncExists = Receipt::where('terminal_id', $locked->id)
                ->whereIn('fiscal_status', [
                    FiscalStatus::PendingSeal->value,
                    FiscalStatus::PendingSync->value,
                ])
                ->where('is_training', false)
                ->exists();

            if ($pendingSyncExists) {
                throw FiscalSchemaCutoverBlockedException::pendingSync((string) $locked->id);
            }

            // All gates passed — upgrade the terminal.
            $locked->fiscal_schema_version = 3;
            $locked->save();

            // Write fiscal audit row for compliance trail.
            AuditEvent::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'user_id' => $performedBy->id,
                'event_type' => 'terminal.fiscal_schema_cutover',
                'aggregate_type' => 'Terminal',
                'aggregate_id' => (string) $locked->id,
                'payload' => json_encode([
                    'from_version' => 2,
                    'to_version' => 3,
                    'terminal_code' => $locked->code,
                    'performed_by' => $performedBy->id,
                ]),
                'metadata' => json_encode([]),
                'event_hash' => hash('sha256', implode('|', [
                    $locked->id,
                    'terminal.fiscal_schema_cutover',
                    '2',
                    '3',
                    now()->toIso8601String(),
                ])),
                'occurred_at' => now(),
            ]);
        });
    }
}
