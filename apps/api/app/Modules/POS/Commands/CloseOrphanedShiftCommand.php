<?php

declare(strict_types=1);

namespace App\Modules\POS\Commands;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\OrphanedShiftClosedByOperator;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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
 * THE MONEY (owner ruling, O-30). `expected_cash` = the shift's opening float
 * plus the cash movements booked to it; `actual_cash` = the same number;
 * `variance` = zero, with the operator's written reason recorded in
 * `pos_shifts.notes` and in the audit event. Nobody counted this drawer —
 * the drawer left the building with the device — so inventing a count would
 * fabricate a shortage or an overage against a cashier who was never asked for
 * one, and leaving the pair NULL is not available either: `pos_shifts_closed_logic`
 * demands `closed_at`/`closed_by`, and a NULL/NULL money pair would make the
 * shift's own row claim it closed with no cash position at all. Zero variance
 * with a written reason is the honest shape: it says "the books were left where
 * the drawer left them".
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
 * {@see self::EXIT_CLOSED_BY_UNKNOWN} (5). Note that `tenants:run` DISCARDS the
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $shiftId = $this->stringArgument('shift');
        $reason = $this->stringOption('reason');
        $closedBy = $this->stringOption('closed-by');
        $apply = (bool) $this->option('apply');

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

        /** @var Shift|null $shift */
        $shift = Shift::query()->with('terminal.company')->whereKey($shiftId)->first();
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

        /** @var Terminal $terminal */
        $terminal = $shift->terminal;

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

        $expectedCash = $this->expectedCash($shift, $scale);
        $countedCash = $expectedCash;
        $variance = CurrencyScale::bcformatStrict('0', $scale);

        $this->renderPlan($shift, $terminal, $release, $currency, $expectedCash, $countedCash, $variance, $reason, $closedBy);

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --apply to close the shift.');

            return self::SUCCESS;
        }

        $closedAt = $this->write($shift, $reason, $closedBy, $expectedCash, $countedCash, $variance, $release);

        if ($closedAt === null) {
            $this->info(sprintf('Shift %s was closed by another writer while this run was working. Nothing to do.', $shift->id));

            return self::SUCCESS;
        }

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
     * Opening float + the cash movements booked to the shift.
     *
     * NOT `CashDrawerService::calculateExpectedCash()`,
     * for two independent reasons. First, that method resolves its scale via a
     * bare `getScale()` and would throw under `tenants:run`, where no
     * CompanyContext is bound (rule 19). Second, it derives the float from an
     * `OPENING` cash-drawer OPERATION, and a device-authored (v3) shift is
     * projected straight into `pos_shifts` from `SESSION_OPEN` — the float
     * lands in `pos_shifts.opening_cash` and there may be no `OPENING` row at
     * all, so that formula would silently drop the float for exactly the
     * population this command exists for.
     *
     * So: `opening_cash` is the float, and `OPENING` is EXCLUDED from the
     * movement sum (counting both would double it on a legacy shift that has
     * one). `CLOSING` is excluded because it is the closing count, not a
     * movement — and an orphan has none by definition.
     *
     * @return numeric-string
     */
    private function expectedCash(Shift $shift, int $scale): string
    {
        // Intermediates run one digit wider than the column (rule 19) and are
        // rounded once, at the end.
        $working = CurrencyScale::bcformatStrict((string) $shift->opening_cash, $scale + 1);

        /** @var Collection<int, CashDrawerOperation> $operations */
        $operations = CashDrawerOperation::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('operation_type', ['OPENING', 'CLOSING'])
            ->get();

        foreach ($operations as $operation) {
            $amount = CurrencyScale::bcformatStrict((string) $operation->amount, $scale + 1);

            $working = match ($operation->operation_type) {
                'SALE' => bcadd($working, $amount, $scale + 1),
                'REFUND', 'DEPOSIT', 'PAYOUT' => bcsub($working, $amount, $scale + 1),
                default => $working,
            };
        }

        return CurrencyScale::bcformatStrict($working, $scale);
    }

    /**
     * The write, under a row lock, re-checking the status inside the
     * transaction.
     *
     * The re-check is not ceremony: a device that was merely OFFLINE (not
     * destroyed) can reconnect at any moment and its `SESSION_CLOSE` will close
     * this very row through the projection. That close is the AUTHORITATIVE one
     * — it carries a real counted drawer — so if it wins the race this run must
     * stand down rather than overwrite it with a synthetic zero-variance pair.
     *
     * @param  numeric-string  $expectedCash
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance
     * @param  array{id: string, reason: string|null, occurred_at: string}  $release
     * @return Carbon|null the timestamp written, or null if another writer closed it first
     */
    private function write(
        Shift $shift,
        string $reason,
        string $closedBy,
        string $expectedCash,
        string $countedCash,
        string $variance,
        array $release,
    ): ?Carbon {
        return DB::transaction(function () use ($shift, $reason, $closedBy, $expectedCash, $countedCash, $variance, $release): ?Carbon {
            /** @var Shift|null $locked */
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === ShiftStatus::Closed) {
                return null;
            }

            $closedAt = Carbon::now();

            $marker = sprintf(
                '[O-30 orphan close %s] Closed without the authoring device after a forced terminal release '
                .'(audit_events.id %s). Cash was NOT counted: expected/counted are the opening float plus booked '
                .'movements, variance 0. Reason: %s',
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

            return $closedAt;
        });
    }

    /**
     * @param  numeric-string  $expectedCash
     * @param  array{id: string, reason: string|null, occurred_at: string}  $release
     * @param  numeric-string  $countedCash
     * @param  numeric-string  $variance
     */
    private function renderPlan(
        Shift $shift,
        Terminal $terminal,
        array $release,
        string $currency,
        string $expectedCash,
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
            ['company_id', $terminal->company_id],
            ['cashier_id', $shift->cashier_id],
            ['opened_at', $shift->opened_at->toIso8601String()],
            ['authorising release', $release['id']],
            ['release occurred_at', $release['occurred_at']],
            ['release reason', $releaseReason ?? '(none recorded)'],
            ['currency', $currency],
            ['expected_cash (to write)', $expectedCash],
            ['actual_cash (to write)', $countedCash],
            ['variance (to write)', $variance],
            ['closed_by (to write)', $closedBy],
            ['reason (to record)', $reason],
        ]);
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
