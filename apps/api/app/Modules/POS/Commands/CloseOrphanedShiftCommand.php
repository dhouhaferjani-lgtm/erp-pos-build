<?php

declare(strict_types=1);

namespace App\Modules\POS\Commands;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\OrphanedShiftDeviceCloseReconciler;
use App\Modules\POS\Application\Services\ShiftExpectedCashService;
use App\Modules\POS\Domain\DTOs\ShiftExpectedCashBreakdown;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\OrphanedShiftClosedByOperator;
use App\Modules\POS\Domain\Exceptions\OrphanCloseProvenanceLostException;
use App\Modules\POS\Domain\Exceptions\UnattributableAccountCollectionException;
use App\Modules\POS\Domain\Exceptions\UnsignableCashMovementException;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `pos:shift:close-orphaned` — the resolution surface for LEDGER O-30 /
 * Q7-OWES-1.
 *
 * `POST /api/v1/pos/terminals/{id}/release` with `force=true` deliberately
 * ORPHANS the terminal's OPEN `pos_shifts` row: the device that could close it
 * is gone (lost, stolen, bricked), and every server-side close refuses a
 * `fiscal_schema_version >= 3` terminal without its authoring device
 * (`ShiftController::close()`, `SyncController::syncCloseShift()` →
 * `SHIFT_DEVICE_AUTHORITY_REQUIRED`). Until the orphan is resolved the
 * REPLACEMENT device is invisible to every server projection: its `SESSION_OPEN`
 * is silently dropped by
 * `ZSessionLifecycleProjection::projectPosShiftOpen()`
 * because a different shift is already OPEN on the terminal, its `SESSION_CLOSE`
 * then retries to exhaustion, and its Z report cannot land at all because
 * `pos_z_reports.shift_id` is FK-RESTRICTed to a row that will never exist.
 *
 * WHAT MAKES THIS NARROW, AND WHY IT MUST STAY NARROW. This command is the only
 * operator-reachable writer of `ShiftStatus::Closed`. If it would close any
 * OPEN shift on request it would be a device-less shift close by the back door,
 * and the v3 device-authority refusal — the thing that keeps `pos_shifts`
 * honest as a PROJECTION of device-authored facts — would become a formality
 * anyone with shell access could step around. So the authorisation is not a
 * permission or a flag: it is EVIDENCE. The shift must be named as
 * `payload.open_shift_id` by a `terminal.released` audit row with
 * `payload.forced = true` on that same terminal. No such row, no close.
 *
 * THE MONEY (owner ruling, O-30; derivation corrected at gate r1).
 * `expected_cash` comes from {@see ShiftExpectedCashService}, the SAME
 * derivation the Z path uses — never computed here. `actual_cash` = the same
 * number; `variance` = zero, with the operator's written reason recorded in
 * `pos_shifts.notes` and in the audit event.
 *
 * Why the shared service and not a local sum: the movement table depends on the
 * terminal's `fiscal_schema_version`, and getting that wrong does not fail — it
 * returns a plausible figure from an EMPTY set. A v3 device shift books every
 * drawer movement as a fiscal event in `pos_z_session_events` and writes no
 * `pos_cash_drawer_operations` row at all (LEDGER ES-05), so the first version
 * of this command — which summed the drawer table — produced a number that
 * could never include the day's cash takings or the device's own drops. That
 * number is not private to the row: `Nf525DataProvider::mapShift()` exports it
 * to the NF525 JET as `EspecesAttendues` with `Ecart = 0`, attributed to the
 * ORIGINAL cashier. A wrong figure there is not a labelling problem.
 *
 * Nobody counted this drawer — it left the building with the device — so
 * inventing a count would fabricate a shortage or an overage against a cashier
 * who was never asked for one, and leaving the pair NULL is not available
 * either: `pos_shifts_closed_logic` demands `closed_at`/`closed_by`, and a
 * NULL/NULL money pair would make the row claim it closed with no cash position
 * at all. Zero variance against the derived position is the honest shape.
 *
 * A NEGATIVE derived position is refused outright
 * ({@see self::EXIT_EXPECTED_CASH_NEGATIVE}): `pos_shifts_positive_amounts`
 * would reject it as a raw 23514, and a drawer holding less than nothing means
 * the movement set is incomplete or mis-signed — a question for a human, not a
 * number to round up to zero.
 *
 * THE FISCAL CONSEQUENCE, stated plainly. Once `closed_at` is set, the NF525
 * JET export gains a `FERMETURE_CAISSE` for this shift
 * (`Nf525XmlBuilder::addTechnicalEvents()`
 * emits one for every shift with a non-null `closed_at`), and the JET has no
 * field that can say "closed administratively". That is still strictly better
 * than the alternative — a shift that is OPEN forever, with an `OUVERTURE_CAISSE`
 * and no closure, on a terminal that has since been re-homed. The provenance
 * survives in the `shift.orphan_closed` audit row and in `pos_shifts.notes`.
 * Whether the JET itself needs a marker is owner ruling O-30(c).
 *
 * The command does NOT dispatch the device's own `ShiftClosed` event.
 * That event is the device's own closure fact and feeds the register as
 * `shift.closed`; forging one here would tell an auditor a cashier counted a
 * drawer that no longer exists.
 *
 * THE DEVICE STILL WINS IF IT COMES BACK. A close written here is provisional
 * in one specific sense: if the "lost" device later syncs its own
 * `SESSION_CLOSE`, `OrphanedShiftDeviceCloseReconciler` replaces the derived
 * pair with the device's counted figures and records the replacement as
 * `shift.orphan_device_close_applied`. Before gate r1 that was asserted here and
 * was FALSE — the projection no-oped on an already-CLOSED shift and the device's
 * real count was silently discarded.
 *
 * INVOCATION — DRY RUN BY DEFAULT. `--apply` is what writes.
 *
 *   php artisan tenants:run pos:shift:close-orphaned \
 *       --tenants=<tenant-uuid> \
 *       --argument=shift=<shift-uuid> \
 *       --option=reason="device stolen 2026-08-20, police report 1234" \
 *       --option=closed-by=<user-uuid>
 *
 * and again with `--option=apply=1` once the preview reads correctly. See
 * `docs/handoff/RUNBOOK-orphaned-shift.md` for the detection query, the
 * accountable role, and what the owner still owes.
 *
 * EXIT CODES are typed so a runbook reader (and a deploy script) can branch:
 * 0 success or idempotent no-op · {@see self::INVALID} (2) usage error ·
 * {@see self::EXIT_SHIFT_NOT_FOUND} (3) · {@see self::EXIT_NOT_ORPHANED} (4) ·
 * {@see self::EXIT_CLOSED_BY_UNKNOWN} (5) ·
 * {@see self::EXIT_EXPECTED_CASH_NEGATIVE} (6) ·
 * {@see self::EXIT_AUDIT_WRITE_FAILED} (7) ·
 * {@see self::EXIT_MOVEMENTS_UNUSABLE} (8) ·
 * {@see self::EXIT_COLLECTION_UNATTRIBUTABLE} (9). Note that `tenants:run` DISCARDS the
 * child exit code, so the codes are for a direct invocation under an
 * already-bound tenant; under `tenants:run` read the printed verdict line.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `pos_shifts`,
 *   `pos_terminals`, `pos_cash_drawer_operations`, `users` and `audit_events`
 *   are all TENANT tables, so post-2026-05-28 (database-per-tenant) each
 *   invocation reads and writes ONLY the tenant database bound around it. There
 *   is no fleet-wide mode on purpose: resolving an orphan is a per-incident act
 *   with a named accountable human, not a sweep. A bare run against CENTRAL
 *   raises 42P01.
 */
final class CloseOrphanedShiftCommand extends Command
{
    /**
     * The shift id does not resolve to a `pos_shifts` row in this tenant.
     */
    public const EXIT_SHIFT_NOT_FOUND = 3;

    /**
     * The shift exists and is OPEN, but the audit register holds no
     * `terminal.released` row with `forced=true` naming it. Refusing is the
     * whole point — see the class docblock.
     */
    public const EXIT_NOT_ORPHANED = 4;

    /**
     * `--closed-by` is not a user in this tenant. `pos_shifts.closed_by` is
     * FK-RESTRICTed to `users`, so without this check the run would die as a
     * raw 23503 that the runbook reader has to decode.
     */
    public const EXIT_CLOSED_BY_UNKNOWN = 5;

    /**
     * The derived expected cash is NEGATIVE. `pos_shifts_positive_amounts`
     * refuses to store it, and a drawer cannot hold less than nothing — so the
     * movement set is telling us something is missing or mis-signed, and
     * guessing a substitute would put a fabricated figure into the JET.
     */
    public const EXIT_EXPECTED_CASH_NEGATIVE = 6;

    /**
     * The close was rolled back because its audit provenance could not be
     * confirmed. See {@see OrphanCloseProvenanceLostException}.
     */
    public const EXIT_AUDIT_WRITE_FAILED = 7;

    /**
     * The shift carries a cash movement the server cannot sign, so its expected
     * cash cannot be derived at all. See {@see UnsignableCashMovementException}.
     */
    public const EXIT_MOVEMENTS_UNUSABLE = 8;

    /**
     * A customer account collection on this terminal, inside the shift's window,
     * carries no `shift_id` — so its cash cannot be attributed and the figure
     * cannot be derived. See {@see UnattributableAccountCollectionException}.
     */
    public const EXIT_COLLECTION_UNATTRIBUTABLE = 9;

    /**
     * @var string
     */
    protected $signature = 'pos:shift:close-orphaned
        {shift : UUID of the OPEN pos_shifts row orphaned by a forced terminal release}
        {--reason= : Written reason, recorded on the shift and in the audit register (required)}
        {--closed-by= : UUID of the user accountable for the close (required; must exist in this tenant)}
        {--apply : Write the close. Without it the command previews and writes nothing}';

    /**
     * @var string
     */
    protected $description = 'Close a pos_shifts row orphaned by a forced terminal release (LEDGER O-30). Dry run unless --apply.';

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ShiftExpectedCashService $expectedCashService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shiftId = $this->stringArgument('shift');
        $reason = $this->stringOption('reason');
        $closedBy = $this->stringOption('closed-by');
        $apply = $this->applyRequested();

        if ($shiftId === null || ! Str::isUuid($shiftId)) {
            $this->error('The `shift` argument must be a UUID (the pos_shifts.id of the orphaned row).');

            return self::INVALID;
        }
        if ($reason === null) {
            $this->error('--reason is required: the register must record WHY a shift was closed without its device.');

            return self::INVALID;
        }
        if ($closedBy === null || ! Str::isUuid($closedBy)) {
            $this->error('--closed-by is required and must be the UUID of the user accountable for the close.');

            return self::INVALID;
        }

        $shift = Shift::query()->whereKey($shiftId)->first();
        if ($shift === null) {
            $this->error(sprintf('No pos_shifts row %s in this tenant database. Is the right tenant bound?', $shiftId));

            return self::EXIT_SHIFT_NOT_FOUND;
        }

        // IDEMPOTENCY FIRST, before the orphan proof. A re-run of a successful
        // apply must be a no-op, and after the close the authorising audit row
        // is still there — so an orphan-proof-first order would happily "close"
        // an already-closed shift a second time and write a second audit row
        // claiming an act that did not happen.
        if ($shift->status === ShiftStatus::Closed) {
            $this->info(sprintf(
                'Shift %s is already CLOSED (closed_at %s, closed_by %s). Nothing to do.',
                $shift->id,
                $shift->closed_at?->toIso8601String() ?? 'unknown',
                $shift->closed_by ?? 'unknown',
            ));

            return self::SUCCESS;
        }

        // `withTrashed()` (gate r1). `Terminal` uses SoftDeletes and
        // `archive()` only refuses while an OPEN shift exists, so the late-sync
        // population — a SESSION_OPEN synced after the release, projecting a
        // shift onto a terminal that has since been archived — reaches here with
        // `$shift->terminal` resolving to NULL through the default relation. The
        // command's own job is to make that terminal usable again, so an
        // archived row must still be READABLE; what must not happen is a
        // TypeError where a typed refusal belongs.
        /** @var Terminal|null $terminal */
        $terminal = $shift->terminal()->withTrashed()->first();
        if ($terminal === null) {
            $this->error(sprintf(
                'Shift %s points at terminal %s, which does not exist in this tenant even including archived '
                .'rows. Nothing can authorise closing it.',
                $shift->id,
                $shift->terminal_id,
            ));

            return self::EXIT_NOT_ORPHANED;
        }

        $release = $this->authorisingRelease($shift, $terminal);
        if ($release === null) {
            $this->error(sprintf(
                'Shift %s is OPEN but NOTHING in the audit register says a forced release orphaned it: no '
                .'`terminal.released` row on terminal %s carries forced=true with open_shift_id=%s. This command '
                .'closes ONLY shifts a forced release orphaned — it is not a general shift-close surface. If the '
                .'device still exists, close the shift from the device.',
                $shift->id,
                $terminal->id,
                $shift->id,
            ));

            return self::EXIT_NOT_ORPHANED;
        }

        if (! User::query()->whereKey($closedBy)->exists()) {
            $this->error(sprintf(
                'No user %s in this tenant. pos_shifts.closed_by is FK-RESTRICTed to `users`, and the close must '
                .'name a real accountable human.',
                $closedBy,
            ));

            return self::EXIT_CLOSED_BY_UNKNOWN;
        }

        // Rule 19 — console context: the scale comes from the ENTITY's currency,
        // never from a bare getScale(). There is no CompanyContext bound under
        // `tenants:run`, so a no-arg call would throw
        // UnboundCompanyContextException here.
        $currency = (string) $terminal->company->currency;
        $scale = $this->scaleResolver->getScale($currency);

        // NOT computed here (gate r1, CRITICAL 1). The one number this command
        // invents comes from the SHARED derivation the Z path uses, which reads
        // the movement table the terminal's fiscal schema version actually
        // populates — `pos_z_session_events` for a v3 device shift,
        // `pos_cash_drawer_operations` for a v2 one.
        // THE WINDOW ENDS AT THE RELEASE, not at now() (gate r2). Nothing stops a
        // REPLACEMENT device claiming this terminal and selling for weeks while
        // the orphan sits OPEN — `pos_receipts` carries no shift id and
        // `PosCoreReceiptProjection` never consults `pos_shifts`, so the
        // replacement's receipts project normally even while its SESSION_OPEN is
        // dropped. An unbounded window would sweep that till's takings into this
        // orphan's expected cash, and the JET would export the total as
        // `EspecesAttendues` with `Ecart = 0` against the ORIGINAL cashier.
        //
        // The release's own `occurred_at` is the right bound and is already in
        // hand: after a forced release the orphan's device no longer holds the
        // terminal, and `pos_receipts.posted_at` is DEVICE time, so the dead
        // device's late-synced receipts still fall inside while the
        // replacement's never do.
        $window = Carbon::parse($release['occurred_at']);

        try {
            $breakdown = $this->expectedCashService->breakdown($shift, $terminal, $currency, $window);
        } catch (UnattributableAccountCollectionException $e) {
            $this->error($e->getMessage());

            return self::EXIT_COLLECTION_UNATTRIBUTABLE;
        } catch (UnsignableCashMovementException $e) {
            // A typed refusal, not a stack trace: the operator reading this is
            // mid-incident with a till out of service, and "which movement, and
            // what do I do about it" is the only useful answer.
            $this->error($e->getMessage());

            return self::EXIT_MOVEMENTS_UNUSABLE;
        }

        if ($breakdown->isNegative()) {
            $this->error(sprintf(
                'Expected cash for shift %s derives NEGATIVE (%s) — %s. A drawer cannot hold less than nothing, '
                .'and `pos_shifts_positive_amounts` refuses to store it, so the movement set is incomplete or '
                .'mis-signed rather than merely surprising. Inspect the movements in %s for this shift before '
                .'closing it; nothing was written.',
                $shift->id,
                $breakdown->expectedCash,
                $breakdown->describe(),
                $breakdown->movementSource->tableName(),
            ));

            return self::EXIT_EXPECTED_CASH_NEGATIVE;
        }

        $expectedCash = $breakdown->expectedCash;
        $countedCash = $expectedCash;
        $variance = CurrencyScale::bcformatStrict('0', $scale);

        $this->renderPlan($shift, $terminal, $release, $breakdown, $countedCash, $variance, $reason, $closedBy);

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --apply to close the shift.');

            return self::SUCCESS;
        }

        try {
            $closedAt = $this->write($shift, $terminal, $reason, $closedBy, $expectedCash, $countedCash, $variance, $release);
        } catch (OrphanCloseProvenanceLostException $e) {
            $this->error($e->getMessage());

            return self::EXIT_AUDIT_WRITE_FAILED;
        }

        if ($closedAt === null) {
            $this->info(sprintf('Shift %s was closed by another writer while this run was working. Nothing to do.', $shift->id));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'CLOSED orphaned shift %s on terminal %s (%s) at %s. The replacement device can now open a shift on '
            .'this terminal; its Z reports will project.',
            $shift->id,
            $terminal->code,
            $terminal->id,
            $closedAt->toIso8601String(),
        ));

        return self::SUCCESS;
    }

    /**
     * The `terminal.released` audit row that AUTHORISES closing this shift, or
     * null when there is none.
     *
     * Matched on THREE predicates, not one: the terminal the shift belongs to
     * (so a release on some other till cannot authorise this one), the shift id
     * carried in `payload.open_shift_id` (so a forced release that orphaned a
     * DIFFERENT shift on the same terminal cannot be reused), and
     * `payload.forced` being literally true (an ordinary release never orphans
     * anything — it is refused while a shift is open).
     *
     * `payload.forced` is compared in PHP rather than in SQL on purpose: the
     * JSON predicate that reads a BOOLEAN out of a `jsonb` column is written
     * differently on PostgreSQL and SQLite, and the candidate set here is one
     * row per forced release on one terminal.
     *
     * Read through the query builder rather than Compliance's `AuditEvent`
     * model: a POS-module file must not import another module's model (rule 6),
     * and `PosCoreReceiptProjection` already reads `audit_events` this way.
     *
     * @return array{id: string, reason: string|null, occurred_at: string}|null
     */
    private function authorisingRelease(Shift $shift, Terminal $terminal): ?array
    {
        $candidates = DB::table('audit_events')
            ->select(['id', 'payload', 'occurred_at'])
            ->where('company_id', $terminal->company_id)
            ->where('event_type', 'terminal.released')
            ->where('aggregate_type', 'Terminal')
            ->where('aggregate_id', $terminal->id)
            ->where('payload->open_shift_id', $shift->id)
            ->orderByDesc('occurred_at')
            ->get();

        foreach ($candidates as $candidate) {
            $rawPayload = $candidate->payload ?? null;
            $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : null;

            if (! is_array($payload) || ($payload['forced'] ?? null) !== true) {
                continue;
            }

            $reason = $payload['reason'] ?? null;

            return [
                'id' => (string) $candidate->id,
                'reason' => is_string($reason) ? $reason : null,
                'occurred_at' => (string) $candidate->occurred_at,
            ];
        }

        return null;
    }

    /**
     * The write, under a row lock, re-checking the status inside the
     * transaction — and the audit row written in that SAME transaction.
     *
     * TWO guarantees, both learned from the r1 gate:
     *
     * 1. The status re-check is not ceremony. A device that was merely OFFLINE
     *    (not destroyed) can reconnect at any moment, and its `SESSION_CLOSE`
     *    carries a REAL counted drawer. If it wins the race this run stands down
     *    rather than overwriting it with a synthetic zero-variance pair. If it
     *    arrives AFTER this close, {@see OrphanedShiftDeviceCloseReconciler}
     *    upgrades the row to the device's figures — the device is authoritative
     *    either way.
     *
     * 2. The provenance is written HERE, not after the commit.
     *    `DomainEventSubscriber::persistEvent()` catches every `Throwable` and
     *    only logs, so a post-commit dispatch cannot report its own failure and
     *    the command would print success over a closed fiscal shift with no
     *    record of who closed it or why. The event is dispatched inside the
     *    transaction (the subscriber is synchronous), the row is READ BACK, and
     *    an absent row rolls the whole close back. An orphan that is still OPEN
     *    is recoverable; an unexplained closed shift in a certified export is
     *    not.
     *
     * @param  numeric-string  $expectedCash
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance
     * @param  array{id: string, reason: string|null, occurred_at: string}  $release
     * @return Carbon|null the timestamp written, or null if another writer closed it first
     *
     * @throws OrphanCloseProvenanceLostException when the audit row cannot be confirmed
     */
    private function write(
        Shift $shift,
        Terminal $terminal,
        string $reason,
        string $closedBy,
        string $expectedCash,
        string $countedCash,
        string $variance,
        array $release,
    ): ?Carbon {
        return DB::transaction(function () use ($shift, $terminal, $reason, $closedBy, $expectedCash, $countedCash, $variance, $release): ?Carbon {
            /** @var Shift|null $locked */
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === ShiftStatus::Closed) {
                return null;
            }

            $closedAt = Carbon::now();

            $marker = sprintf(
                '[O-30 orphan close %s] Closed without the authoring device after a forced terminal release '
                .'(audit_events.id %s). Cash was NOT counted: expected/counted are the shift\'s derived cash '
                .'position, variance 0. Reason: %s',
                $closedAt->toIso8601String(),
                $release['id'],
                $reason,
            );

            $existingNotes = $locked->notes;
            $notes = $existingNotes === null || trim($existingNotes) === ''
                ? $marker
                : $existingNotes."\n".$marker;

            $locked->update([
                'status' => ShiftStatus::Closed,
                'expected_cash' => $expectedCash,
                'actual_cash' => $countedCash,
                'variance' => $variance,
                'closed_at' => $closedAt,
                'closed_by' => $closedBy,
                'notes' => $notes,
            ]);

            event(new OrphanedShiftClosedByOperator(
                shiftId: $shift->id,
                terminalId: $terminal->id,
                terminalCode: $terminal->code,
                companyId: $terminal->company_id,
                cashierId: $shift->cashier_id,
                reason: $reason,
                closedBy: $closedBy,
                releaseAuditEventId: $release['id'],
                expectedCash: $expectedCash,
                countedCash: $countedCash,
                variance: $variance,
                closedAt: $closedAt->toIso8601String(),
            ));

            $provenanceLanded = DB::table('audit_events')
                ->where('company_id', $terminal->company_id)
                ->where('event_type', 'shift.orphan_closed')
                ->where('aggregate_type', 'Shift')
                ->where('aggregate_id', $shift->id)
                ->exists();

            if (! $provenanceLanded) {
                throw OrphanCloseProvenanceLostException::forShift($shift->id);
            }

            return $closedAt;
        });
    }

    /**
     * @param  array{id: string, reason: string|null, occurred_at: string}  $release
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance
     */
    private function renderPlan(
        Shift $shift,
        Terminal $terminal,
        array $release,
        ShiftExpectedCashBreakdown $breakdown,
        string $countedCash,
        string $variance,
        string $reason,
        string $closedBy,
    ): void {
        $releaseReason = $release['reason'];

        $this->line('ORPHANED SHIFT — resolution plan (LEDGER O-30)');
        $this->table(['field', 'value'], [
            ['shift_id', $shift->id],
            ['shift_number', (string) $shift->shift_number],
            ['terminal', sprintf('%s (%s)', $terminal->code, $terminal->id)],
            ['terminal fiscal_schema_version', (string) $terminal->fiscal_schema_version],
            ['company_id', $terminal->company_id],
            ['cashier_id', $shift->cashier_id],
            ['opened_at', $shift->opened_at->toIso8601String()],
            ['authorising release', $release['id']],
            ['release occurred_at', $release['occurred_at']],
            ['release reason', $releaseReason ?? '(none recorded)'],
            ['currency', $breakdown->currencyCode],
            ['opening float', $breakdown->openingFloat],
            ['cash sales (net of returns/change)', $breakdown->cashSales],
            ['drawer movements', sprintf('%s (%d row(s))', $breakdown->movementsNet, $breakdown->movementCount)],
            ['movement source', $breakdown->movementSource->tableName()],
            ['cash account collections', sprintf('%s (%d row(s))', $breakdown->accountCollections, $breakdown->accountCollectionCount)],
            ['window ends at (release time)', $breakdown->windowEnd],
            ['expected_cash (to write)', $breakdown->expectedCash],
            ['actual_cash (to write)', $countedCash],
            ['variance (to write)', $variance],
            ['closed_by (to write)', $closedBy],
            ['reason (to record)', $reason],
        ]);

        if ($breakdown->movementCount === 0) {
            $this->warn(sprintf(
                'No cash movements found for this shift in %s. That is normal for a shift that never had one — '
                .'but if the till DID take drops or payouts, the figure above is short by whatever is missing. '
                .'Check before applying.',
                $breakdown->movementSource->tableName(),
            ));
        }
    }

    /**
     * `--apply` is a VALUE_NONE flag, but under `tenants:run` it arrives as the
     * STRING the wrapper built from `--option=apply=1`
     * (`Stancl\Tenancy\Commands\Run` turns `apply=1` into `['--apply' => '1']`
     * and Symfony's `ArrayInput` stores it verbatim). A bare `(bool)` cast would
     * therefore read `apply=false` and `apply=no` as TRUE and write the close —
     * the one direction this flag must never fail in.
     */
    private function applyRequested(): bool
    {
        // Read through the raw input, not `$this->option()`: larastan types the
        // latter from the SIGNATURE (VALUE_NONE ⇒ `bool`) and then narrows the
        // string branch below away as dead code — which is precisely the branch
        // that runs under `tenants:run`.
        $value = $this->input->getOption('apply');

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value === true;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
