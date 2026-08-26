<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use ReflectionClass;
use Tests\Architecture\ProjectorEmissionFixtures\FixtureProjectorWithCommentedEmission;
use Tests\Architecture\ProjectorEmissionFixtures\FixtureProjectorWithRealEmission;
use Tests\TestCase;

/**
 * ES wave A0, M5 — the PROJECTOR-EMISSION RATCHET.
 *
 * Handover §5, verbatim: *"a **projector-emission** architecture test (every
 * registered `FiscalEventProjector` that writes a POS projection emits its
 * corresponding domain event) — this is the regression guard that would have
 * prevented T1 entirely."*
 *
 * T1 is the **v3 cutover event blackout**: six legacy server write paths were
 * 409/410-retired for `fiscal_schema_version >= 3` and replaced by six
 * projectors, **not one of which emits a domain event**. Tier-1 (the hash
 * chain) got stronger; Tier-2 (NF525 `audit_events`, fraud alerts, GL variance)
 * went dark. Register cluster T1 = ES-01, ES-03, ES-04, ES-11, ES-14, ES-15,
 * ES-19, ES-20, ES-22, ES-44, ES-47.
 *
 * # Why this is a RATCHET and not a hard assertion (brief R-10)
 *
 * **A1 has not run.** Measured on 2026-08-19: of the six registered projectors
 * that write POS projections, **zero** emit any domain event. A hard assertion
 * would therefore be a red wall on `dev` from the moment it lands — six
 * failures that nobody can fix inside this lane, which is how a check gets
 * `@group`-ed out and forgotten. So the check ships with an **enumerated
 * skip-list**, each entry naming the register row that will delete it. A1
 * deletes ENTRIES; it never edits this test's logic.
 *
 * The assertion is a strict equality against {@see self::NOT_EMITTING_BASELINE},
 * so it bites in both directions:
 *
 *   - a NEW registered POS projector that does not emit → the discovered list
 *     grows → RED. That is the T1 regression guard.
 *   - a baselined projector that STARTS emitting → the discovered list shrinks
 *     → RED, and the fix is to delete the line. Shrink-only, deliberately
 *     manual, so the register row gets closed at the same time.
 *
 * # "Writes a POS projection" — the criterion, stated rather than assumed
 *
 * A registered projector counts as a POS-projection writer when its class lives
 * in the **POS module** (`App\Modules\POS\`). That is this codebase's ownership
 * rule, not a guess: module boundaries are sacred (rule 6) and the POS module
 * owns the POS projection tables, while the Treasury and Document bridges write
 * their OWN module's rows against the same fiscal events —
 * `TreasuryReceiptBridge` for instance READS `pos_receipts` and writes Treasury
 * `Payment` rows plus the GL post, which is why it runs at `priority=150`
 * behind the POS-core projectors at `priority=50`
 * (`FiscalEventProjectionRegistry`'s class docblock).
 *
 * A table-name grep cannot make this distinction — reading `pos_receipts` and
 * writing it look identical in source text — which is why the criterion is
 * ownership. {@see test_the_registered_projector_set_is_unchanged} pins the
 * FULL registered set on both sides of the partition, so a new projector in
 * either module is noticed rather than silently classified.
 *
 * # "Emits its corresponding domain event" — detection, and its blind spots
 *
 * Source-text on the projector's OWN class file: `event(new …)`,
 * `X::dispatch(`, `->dispatch(new …)`, `Event::dispatch(`. This matches the fix
 * shape the register prescribes for the whole T1 cluster — *"emit inside the
 * projector's `DB::transaction`, after the write, **inside** the existing
 * idempotency guard so Horizon redelivery cannot double-emit"* — i.e. emission
 * belongs in the projector, next to the write it describes.
 *
 * **Comments and doc-blocks are stripped before matching** (`token_get_all()`,
 * dropping `T_COMMENT` / `T_DOC_COMMENT`). M5 round 1, F-4 proved why that is
 * not a nicety: appending the single line `// PROBE: event(new Something());`
 * to `ZReportProjection.php` was enough to drop it from the discovered list and
 * fire the failure message that instructs a maintainer to *"delete its line AND
 * close the register row"* — i.e. a docblock edit on a 2 000-line projector
 * could talk someone into closing **ES-04**. It cannot now;
 * {@see test_a_commented_out_emission_does_not_count_as_emitting} pins that.
 *
 * **Blind spots — BOTH directions, named. The error is NOT one-directional.**
 *
 *   - **Under-report (reads as emitting when it is not).** Pattern 2 matches any
 *     `Symbol::dispatch(`, so a projector that dispatches a **queued job**
 *     (`SomeJob::dispatch(...)`) and emits no domain event is classified as
 *     emitting and never enters the baseline. Not live today — all six POS
 *     projectors contain no `::dispatch`, no `->dispatch` and no `event(` call
 *     at all — but it is the direction that loses coverage silently, so it is
 *     the one to fix first if it ever becomes live. The fix shape: resolve the
 *     dispatched symbol through the file's `use` map and require it to land
 *     under a `Domain\Events\` / `Shared\Events\` namespace.
 *   - **Under-report, second vector: STRING LITERALS are not stripped.**
 *     {@see self::sourceWithoutComments()} drops `T_COMMENT` / `T_DOC_COMMENT`
 *     but leaves `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`, so
 *     a string containing `event(new …)` — a log line, an exception message, a
 *     heredoc code sample — would read as an emission and silently retire a
 *     baseline entry. There is already a NEAR-MISS in one of the baselined
 *     files: `ZReportProjection.php:326` carries the text `event(s)` inside its
 *     `missingDependency` message. Harmless today only because pattern 1
 *     requires `new` after the parenthesis. Recorded at M5 round 2 (N-8) as a
 *     known limitation rather than fixed. **Fix shape:** add
 *     `|| $token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE`
 *     to the strip condition and extend
 *     {@see FixtureProjectorWithCommentedEmission}
 *     with a string-literal line, so the widening is pinned the way the comment
 *     stripping is.
 *   - **Over-report (reads as non-emitting when it emits).** A projector that
 *     emits by delegating to a collaborator service reads as silent here. A fix
 *     in that shape will make this test RED for the right list and the wrong
 *     reason — widen the detection deliberately rather than silently dropping
 *     the entry.
 */
final class ProjectorEmissionRatchetTest extends TestCase
{
    /**
     * Registered POS-projection projectors that emit NO domain event, as
     * measured on 2026-08-19. **Zero of six emit** — A1 has not run.
     *
     * Each entry names the register row that will delete it. This list may
     * only SHRINK.
     *
     * @var list<class-string<FiscalEventProjector>>
     */
    private const NOT_EMITTING_BASELINE = [
        // — NO REGISTER ROW (the three ACCOUNT_*/DEPOSIT projections below).
        // These were never registered as emission gaps; the measurement records
        // them here for the first time. Whether an ACCOUNT_PAYMENT /
        // ACCOUNT_CHARGE / DEPOSIT_RECEIPT projection OWES a domain event is an
        // OPEN QUESTION for A1, not an established defect: unlike ES-01/03/04
        // there is no named consumer waiting for one. Do not delete these lines
        // by inventing an event — delete them when A1 rules that an emission is
        // owed and ships it, or move them to an explicit "deliberately silent"
        // list with the ruling cited.
        'App\Modules\POS\Application\Projections\AccountChargeReceiptProjection',
        'App\Modules\POS\Application\Projections\AccountPaymentReceiptProjection',
        'App\Modules\POS\Application\Projections\DepositReceiptProjection',

        // ES-01 (GAP-CRITICAL, LAUNCH) — v3 SALE_RECEIPT projection emits no
        // receipt lifecycle event (no ReceiptDrafted/ReceiptCreated/
        // ReceiptCompleted), so NO NF525 `TICKET` audit_events row exists for
        // any device-authored receipt: Tier-2 is empty for the entire fiscal
        // era. Would-be consumer: DomainEventSubscriber.
        // Also carries ES-47 (two sequence authorities on
        // pos_terminals.current_sequence) and ES-48 (the retired
        // ReceiptCompleted emitters), which are not emission gaps but land on
        // the same class.
        'App\Modules\POS\Application\Projections\PosCoreReceiptProjection',

        // ES-04 (GAP-CRITICAL, LAUNCH) — v3 Z_REPORT raises no
        // ZReportGenerated, so there is no NF525 `RAPPORT_Z` audit row and
        // pos_grandtotal_events is empty for the fiscal era (the exporter
        // compensates by deriving the grand total).
        // ES-11 and ES-44 also land on this class but are projection-
        // completeness / dual-writer rows, not emission rows.
        'App\Modules\POS\Application\Projections\ZReportProjection',

        // ES-03 (GAP-CRITICAL, LAUNCH) — v3 SESSION_OPEN/SESSION_CLOSE raise no
        // ShiftOpened/ShiftClosed, so no OUVERTURE_CAISSE / FERMETURE_CAISSE
        // rows exist for any cutover terminal.
        // ES-02 (GAP-CRITICAL, owner-RULED that the event must be emitted on
        // v3) — shift close raises no CashCountRecorded, so
        // OpenFraudAlertForShiftVariance never runs and
        // PostShiftCashVarianceAdjustment never books the variance JE.
        // ES-05 (GAP-CRITICAL) — CASH_IN/CASH_OUT write only a z_session_events
        // row: no pos_cash_drawer_operations row and no
        // CashDrawerOperationRecorded, so calculateExpectedCash() is blind.
        // Three register rows, one class.
        //
        // ⚠️ THIS LINE IS TRUE ABOUT ES-03/ES-02/ES-05, AND NO LONGER TRUE ABOUT
        // THE PROJECTOR AS A WHOLE (LEDGER O-30, 2026-08-26). Applying a
        // SESSION_CLOSE to a shift an operator closed with
        // `pos:shift:close-orphaned` DOES emit a domain event —
        // `OrphanedShiftDeviceCloseApplied` — but it emits it by delegating to
        // `OrphanedShiftDeviceCloseReconciler`, so the file scan above cannot
        // see it. That is the "Over-report" blind spot this test's own docblock
        // names, hit deliberately: emitting inline would shrink the discovered
        // list, turn this test red, and offer exactly one "fix" — deleting this
        // line, which would silently claim ES-03/ES-02/ES-05 are closed. They
        // are not.
        //
        // DO NOT DELETE THIS LINE on the strength of the O-30 emission. It comes
        // out when A1 ships ShiftOpened/ShiftClosed, CashCountRecorded and
        // CashDrawerOperationRecorded — the three events it actually names.
        'App\Modules\POS\Application\Projections\ZSessionLifecycleProjection',
    ];

    /**
     * The full registered set, both partitions, pinned by class name. A new
     * projector must be classified deliberately.
     *
     * @var array<string, list<class-string<FiscalEventProjector>>>
     */
    private const REGISTERED_PROJECTORS = [
        'writes_pos_projections' => [
            'App\Modules\POS\Application\Projections\AccountChargeReceiptProjection',
            'App\Modules\POS\Application\Projections\AccountPaymentReceiptProjection',
            'App\Modules\POS\Application\Projections\DepositReceiptProjection',
            'App\Modules\POS\Application\Projections\PosCoreReceiptProjection',
            'App\Modules\POS\Application\Projections\ZReportProjection',
            'App\Modules\POS\Application\Projections\ZSessionLifecycleProjection',
        ],
        'writes_other_modules_projections' => [
            'App\Modules\Document\Application\Projections\DocumentAccountChargeFactureBridge',
            'App\Modules\Treasury\Application\Projections\TreasuryAccountChargeBridge',
            'App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge',
            'App\Modules\Treasury\Application\Projections\TreasuryDepositBridge',
            'App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge',
        ],
    ];

    public function test_no_new_silent_pos_projector_has_been_registered(): void
    {
        $this->assertSame(
            self::NOT_EMITTING_BASELINE,
            $this->posProjectorsThatEmitNothing(),
            "PROJECTOR-EMISSION RATCHET (ES wave A0, M5).\n\n"
            .'A registered FiscalEventProjector that writes a POS projection but emits no domain event is the T1 '
            .'defect: the projection row lands, the hash chain is fine, and every Tier-2 consumer downstream of it — '
            ."the NF525 audit_events row, the fraud alert, the GL variance entry — silently does not happen.\n\n"
            .'IF THE DIFF ADDED A NAME: you registered a POS projector that writes a projection and emits nothing. '
            ."Emit the corresponding domain event inside the projector's DB::transaction, after the write, INSIDE the "
            .'existing idempotency guard so Horizon redelivery cannot double-emit — and write the consumer test. If '
            ."the projection genuinely owes no event, add the class here WITH the ruling that says so.\n\n"
            .'IF THE DIFF REMOVED A NAME: a projector started emitting. Delete its line AND close the register row '
            .'named in the comment above it. This list may only shrink.',
        );
    }

    /**
     * The skip-list is only meaningful while its entries are actually
     * registered. A projector that is de-registered but left in the baseline
     * would make the ratchet quietly weaker.
     */
    public function test_the_registered_projector_set_is_unchanged(): void
    {
        $registered = $this->registeredProjectorClasses();

        $pos = array_values(array_filter($registered, $this->isPosProjector(...)));
        $other = array_values(array_filter($registered, fn (string $c): bool => ! $this->isPosProjector($c)));

        $this->assertSame(
            self::REGISTERED_PROJECTORS,
            ['writes_pos_projections' => $pos, 'writes_other_modules_projections' => $other],
            'PROJECTOR-EMISSION RATCHET: the registered FiscalEventProjector set changed. Classify the new or removed '
            .'projector deliberately: a POS-module projector owes a domain event (or a skip-list entry naming the '
            .'ruling), a projector in another module writes that module\'s own rows and is outside the handover\'s '
            .'clause. Registration happens via `$this->app->tag([...], FiscalEventProjector::class)` in the owning '
            .'module\'s provider.',
        );
    }

    /**
     * Every baselined class must still BE a registered projector — otherwise
     * the skip-list is documenting something that no longer runs.
     */
    public function test_every_baselined_projector_is_still_registered(): void
    {
        $registered = $this->registeredProjectorClasses();

        $stale = array_values(array_filter(
            self::NOT_EMITTING_BASELINE,
            static fn (string $class): bool => ! in_array($class, $registered, true),
        ));

        $this->assertSame(
            [],
            $stale,
            'PROJECTOR-EMISSION RATCHET: these classes are skip-listed but are no longer registered projectors. A '
            .'de-registered projector emits nothing because it never runs, which is not the same as the gap this '
            .'baseline records. Delete the lines.',
        );
    }

    /**
     * M5 round 1, F-4 — the detector used to match raw source text, so a single
     * commented-out `event(new …)` line dropped a projector out of the
     * discovered list and fired the "a projector started emitting, delete its
     * line AND close the register row" message. On a 2 000-line projector that
     * is a doc-block edit away from closing ES-04.
     *
     * Both directions are pinned: comments do not count, and stripping them does
     * not also blind the detector to real code.
     */
    public function test_a_commented_out_emission_does_not_count_as_emitting(): void
    {
        $this->assertFalse(
            $this->emitsADomainEvent(FixtureProjectorWithCommentedEmission::class),
            'PROJECTOR-EMISSION RATCHET: a commented-out emission must NOT retire a baseline entry. Comments and '
            .'doc-blocks are stripped with token_get_all() before matching — if this fails, that stripping regressed.',
        );

        $this->assertTrue(
            $this->emitsADomainEvent(FixtureProjectorWithRealEmission::class),
            'PROJECTOR-EMISSION RATCHET: the positive control. Stripping comments must not blind the detector to a '
            .'real emission, or the whole ratchet reads every projector as silent and can never go red.',
        );
    }

    // =================================================================
    // Discovery
    // =================================================================

    /**
     * @return list<class-string<FiscalEventProjector>>
     */
    private function posProjectorsThatEmitNothing(): array
    {
        $silent = [];
        foreach ($this->registeredProjectorClasses() as $class) {
            if ($this->isPosProjector($class) && ! $this->emitsADomainEvent($class)) {
                $silent[] = $class;
            }
        }

        sort($silent);

        return $silent;
    }

    /**
     * Read the LIVE registry rather than the provider source — the tag is what
     * decides whether a projector runs, and a class that exists but is never
     * tagged is not "registered".
     *
     * @return list<class-string<FiscalEventProjector>>
     */
    private function registeredProjectorClasses(): array
    {
        /** @var FiscalEventProjectionRegistry $registry */
        $registry = $this->app->make(FiscalEventProjectionRegistry::class);

        $classes = [];
        foreach ($registry->all() as $projector) {
            $classes[] = $projector::class;
        }
        sort($classes);

        /** @var list<class-string<FiscalEventProjector>> $classes */
        return $classes;
    }

    private function isPosProjector(string $class): bool
    {
        return str_starts_with($class, 'App\\Modules\\POS\\');
    }

    /**
     * @param  class-string  $class
     */
    private function emitsADomainEvent(string $class): bool
    {
        $file = (new ReflectionClass($class))->getFileName();
        if ($file === false) {
            $this->fail(sprintf('PROJECTOR-EMISSION RATCHET: cannot locate the source file for %s.', $class));
        }

        $source = $this->sourceWithoutComments((string) file_get_contents($file));

        foreach ([
            '/\bevent\s*\(\s*new\s+/',
            '/\b[A-Za-z_][A-Za-z0-9_\\\\]*::dispatch(?:If|Unless)?\s*\(/',
            '/->dispatch\s*\(\s*new\s+/',
        ] as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip comments and doc-blocks so a commented-out emission cannot retire a
     * baseline entry (M5 round 1, F-4).
     *
     * Tokenising is the smallest honest fix: a regex that tries to skip
     * comments has to understand strings, heredocs and escaping, and getting
     * that subtly wrong is the same class of silent-miss the ratchet exists to
     * prevent. PHP's own lexer already knows.
     */
    private function sourceWithoutComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep a newline so line-oriented constructs either side do
                    // not accidentally fuse into one another.
                    $stripped .= "\n";

                    continue;
                }

                $stripped .= $token[1];

                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }
}
