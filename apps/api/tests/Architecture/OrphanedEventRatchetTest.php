<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Contracts\Events\Dispatcher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spatie\EventSourcing\Projectionist;
use SplFileInfo;
use Tests\TestCase;

/**
 * ES wave A0, M5 — the ORPHANED-EVENT RATCHET.
 *
 * Handover §5, verbatim: *"an **orphaned-event ratchet** in CI (emitted with
 * zero registered listeners → fail on new drift, baseline the existing 14)"*.
 *
 * # What this test asserts
 *
 * Every domain event class that is DISPATCHED somewhere in `app/` and has ZERO
 * explicitly-registered listeners is enumerated **by name** in
 * {@see self::BASELINE}. The assertion is a strict equality, so:
 *
 *   - **adding** a new orphan (dispatching an event nobody listens to) turns
 *     this test RED — that is the ratchet;
 *   - **removing** one (wiring a listener, or deleting the event class) ALSO
 *     turns it red, and the fix is to DELETE the line from the baseline. That
 *     direction is always allowed and is the point: the list may only shrink.
 *
 * A baseline that is a COUNT rather than a LIST is not a ratchet (brief R-10),
 * which is why every entry is a fully-qualified class name.
 *
 * # The number is 75, not 14 — and the handover's "14" was never a measurement
 *
 * The handover says "baseline the existing 14". That 14 is a count of **ES
 * REGISTER ROWS** carrying the `DEAD-EVENT` class, not a count of event
 * classes: the sentence it comes from is
 * `HANDOVER-event-sourcing-remediation-2026-08-11.md`'s V2 requirement — *"An
 * event with no consumer is not a fix, it is a new dead event (this register
 * already contains 14 of those)"*. Today `ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md`
 * carries **16** such rows (ES-12, ES-14, ES-20, ES-21, ES-25, ES-48, ES-75,
 * ES-77, ES-78, ES-79, ES-81, ES-82, ES-85, ES-86, ES-87, ES-88), and several
 * of those rows name MANY classes each — ES-75 alone names ten, ES-86 names
 * six.
 *
 * Measured in the code, under the handover's own definition ("emitted with zero
 * registered listeners"), the population is **75**. Roughly half of them map
 * onto a register row; the rest were never registered at all. That gap is
 * itself worth reading before anyone plans the retirement work. The baseline
 * below is the MEASUREMENT, and each entry that has a register row is annotated
 * with it — that row is what will eventually delete the line.
 *
 * # Mechanism, and its two deliberate exclusions
 *
 * **Dispatched** — resolved from source text, not from runtime behaviour
 * (dispatching every event to find out would be a test that mutates the world).
 * For each PHP file under `app/` the namespace and `use` map are parsed, then
 * every `new X(`, `X::dispatch(`, `X::dispatchIf(`, `X::dispatchUnless(` token
 * is resolved through that map to a fully-qualified name. The event's OWN
 * defining file is excluded. This is the same source-text discipline
 * {@see SweepScannerRegistrationTest} uses, and for the same reason.
 *
 * **Registered listener** — read from the live dispatcher's explicit listener
 * map by reflection, NOT from `Event::hasListeners()`. Two exclusions, both
 * deliberate and both load-bearing:
 *
 *   1. **The Spatie global wildcard.** `spatie/laravel-event-sourcing`
 *      registers `Spatie\EventSourcing\StoredEvents\EventSubscriber@handle`
 *      against the pattern `*`, which makes `Event::hasListeners()` return
 *      TRUE for **every** string — including
 *      `Event::hasListeners('Totally\Fake\Class')`. A ratchet built on
 *      `hasListeners()` would report zero orphans forever. The subscriber is
 *      infrastructure that PERSISTS events implementing `ShouldBeStored`; it is
 *      not a consumer of any particular event's meaning.
 *   2. **Spatie projectors/reactors.** Checked at runtime rather than assumed:
 *      `Projectionist::getProjectors()` and `getReactors()` are both EMPTY, so
 *      no event in this codebase is consumed that way. If that ever changes,
 *      this test must union them in — see `test_the_projectionist_is_still_empty`.
 *
 * # What this ratchet does NOT catch — named, not hidden
 *
 *   - **The opposite orphan: a listener with no emitter.** ES-21 is exactly
 *     that — `ReceiptVoided` has live consumers wired and no production emitter,
 *     so a documented behaviour silently no longer exists. It is therefore NOT
 *     in the baseline below (it has listeners) and this test would not have
 *     caught it. Covering that direction is a second ratchet and a second
 *     decision about what counts as an emitter; A0 did not take it.
 *   - **The never-emitted class.** An event class that is dispatched NOWHERE is
 *     not an orphan by this definition and is not in the baseline. `PointsExpired`
 *     is the concrete case, and it corrects a record: `ES-REGISTER-CORRECTIONS-2026-08-11.md:61`
 *     states the three sampled ES-88 events "exist and are emitted", citing
 *     `PointsExpired.php:9-45` — which is the class's OWN definition file, not an
 *     emitter. Grepped across the whole backend including tests, `PointsExpired`
 *     has exactly two references, both inside its own file. It is never emitted.
 *   - **Indirect consumption.** An event consumed by something other than a
 *     registered listener (a manual `Event::listen` inside a request lifecycle,
 *     a queue worker reading the stored-event table) reads as an orphan here.
 *   - **Dispatch from outside `app/`** — a console command in `bootstrap/`, a
 *     package, or a test-only dispatch is not counted as an emission.
 */
final class OrphanedEventRatchetTest extends TestCase
{
    /**
     * Every event class dispatched in `app/` with zero explicitly-registered
     * listeners, as measured on 2026-08-19.
     *
     * **This list may only SHRINK.** Adding an entry means a new dead event was
     * introduced; that is the drift the ratchet exists to stop.
     *
     * The `ES-nn` annotations are the register rows that will eventually delete
     * the line. Entries marked `— no register row` were never registered as a
     * dead-event finding; they are recorded here for the first time.
     *
     * @var list<class-string>
     */
    private const BASELINE = [
        // ES-25 — "emitted and consumed by nothing, not even audit. Creating or
        // editing a GL account, and posting a company's opening trial balance,
        // produce no audit row at all." Three one-line wirings.
        'App\Modules\Accounting\Domain\Events\AccountCreated',
        'App\Modules\Accounting\Domain\Events\AccountUpdated',
        'App\Modules\Accounting\Domain\Events\OpeningBalancePosted',

        // ES-85 — the dead trio. StockTransferCompleted is the consequential
        // one: Initiated and Cancelled both have Replenishment listeners, so a
        // replenishment request settled on dispatch is never confirmed on
        // receipt.
        'App\Modules\BatchExpiry\Domain\Events\BatchStockConsumed',

        // — no register row. The Catalog attribute/variant surface was never
        // registered as a dead-event finding.
        'App\Modules\Catalog\Domain\Events\ProductAttributeCreated',
        'App\Modules\Catalog\Domain\Events\ProductAttributeValueAdded',
        'App\Modules\Catalog\Domain\Events\ProductVariantCreated',

        // — no register row.
        'App\Modules\Channel\Domain\Events\ChannelOrderReceived',
        'App\Modules\Channel\Domain\Events\ChannelSyncDriftDetected',

        // — no register row.
        'App\Modules\Company\Domain\Events\FirstTransactionPosted',
        'App\Modules\Company\Domain\Events\FiscalYearValidated',

        // ES-75 — variant blindness. Dual-dispatched at ~15 sites so "new
        // subscribers read variantId"; no subscriber was ever written.
        'App\Modules\Document\Domain\Events\DraftLineAddedV2',
        'App\Modules\Document\Domain\Events\DraftLineAddedV3',
        'App\Modules\Document\Domain\Events\DraftLineModifiedV2',
        'App\Modules\Document\Domain\Events\DraftLineModifiedV3',
        'App\Modules\Document\Domain\Events\DraftLineRemovedV2',
        // ES-87 — PurchaseOrderConfirmed has zero listeners with no versioning
        // involved; ReturnNoteConfirmed's structurally identical, identically
        // sealed sibling DeliveryNoteConfirmed IS consumed (asymmetric
        // compliance coverage).
        'App\Modules\Document\Domain\Events\PurchaseOrderConfirmed',
        'App\Modules\Document\Domain\Events\ReturnNoteConfirmed',
        // ES-75.
        'App\Modules\Document\Domain\Events\SalesOrderConfirmedV2',

        // ES-75 — the reservation trio; the fraud-detection reservation audit
        // is variant-blind while variant-scoped stock_levels rows exist.
        'App\Modules\Inventory\Domain\Events\ReservationCreatedV2',
        'App\Modules\Inventory\Domain\Events\ReservationExpiredV2',
        'App\Modules\Inventory\Domain\Events\ReservationReleasedV2',
        // ES-85.
        'App\Modules\Inventory\Domain\Events\StockLevelsMigratedToDefaultVariant',
        // ES-75 — the only live stock consumer (channel sync) reads V1.
        'App\Modules\Inventory\Domain\Events\StockMovementRecordedV2',
        // ES-85.
        'App\Modules\Inventory\Domain\Events\StockTransferCompleted',

        // — no register row.
        'App\Modules\Loyalty\Domain\Events\LoyaltyAdjusted',
        // ES-88, WITH A CORRECTION. The row names "the V1 predecessors of
        // PointsRedeemed/MemberEnrolled/TierUpgraded/TierDowngraded/RewardRedeemed"
        // and says listener absence was never verified. Verified here: it is the
        // **V2** classes that are dispatched with no listener. The V1 names are
        // not in this list because they are not dispatched in app/ at all.
        'App\Modules\Loyalty\Domain\Events\MemberEnrolledV2',
        'App\Modules\Loyalty\Domain\Events\PointsEarnedV2',
        // — no register row.
        'App\Modules\Loyalty\Domain\Events\ProgramDeactivated',
        // ES-88, same correction.
        'App\Modules\Loyalty\Domain\Events\RewardRedeemedV2',
        'App\Modules\Loyalty\Domain\Events\TierDowngradedV2',
        'App\Modules\Loyalty\Domain\Events\TierUpgradedV2',

        // ES-77 — emitted at two sites, no consumer anywhere.
        'App\Modules\POS\Domain\Events\BroadCustomerSearchAlert',
        // ES-78 — emitted, not in $listen, not in the broadcast subscriber.
        'App\Modules\POS\Domain\Events\OrderClosed',

        // ES-87.
        'App\Modules\Partner\Domain\Events\PartnerCreated',
        'App\Modules\Partner\Domain\Events\PartnerUpdated',

        // — no register row. Note ProductCostPriceUpdated (same module) DOES
        // have a listener, so this is not a whole-module gap.
        'App\Modules\Product\Domain\Events\ProductCreated',
        'App\Modules\Product\Domain\Events\ProductDeleted',
        'App\Modules\Product\Domain\Events\ProductUpdated',

        // ES-86 — "the entire Replenishment event surface is dead, 6/6". The
        // module registers only INBOUND listeners on Inventory's transfer
        // events; it registers no listener for any event it emits itself.
        // Measured here as exactly six, which corroborates the row.
        'App\Modules\Replenishment\Domain\Events\ReplenishmentFulfilled',
        'App\Modules\Replenishment\Domain\Events\ReplenishmentRejected',
        'App\Modules\Replenishment\Domain\Events\ReplenishmentReopened',
        'App\Modules\Replenishment\Domain\Events\ReplenishmentRequestBumped',
        'App\Modules\Replenishment\Domain\Events\ReplenishmentRequested',
        'App\Modules\Replenishment\Domain\Events\ReplenishmentSourced',

        // — no register row. The whole Scheduling emission surface (6/6) is
        // orphaned; the appointment MIRRORING that does exist runs the other
        // way, off WorkOrder events.
        'App\Modules\Scheduling\Domain\Events\AppointmentCancelled',
        'App\Modules\Scheduling\Domain\Events\AppointmentCheckedIn',
        'App\Modules\Scheduling\Domain\Events\AppointmentConfirmed',
        'App\Modules\Scheduling\Domain\Events\AppointmentConvertedToWorkOrder',
        'App\Modules\Scheduling\Domain\Events\AppointmentRescheduled',
        'App\Modules\Scheduling\Domain\Events\AppointmentScheduled',

        // — no register row. Six fiscal-adjacent Taxation events with no
        // consumer; worth a look from whoever owns the TN withholding lane.
        'App\Modules\Taxation\Domain\Events\VatPeriodClosed',
        'App\Modules\Taxation\Domain\Events\VatPeriodFiled',
        'App\Modules\Taxation\Domain\Events\WithholdingCertificateCreated',
        'App\Modules\Taxation\Domain\Events\WithholdingCertificateIssued',
        'App\Modules\Taxation\Domain\Events\WithholdingCertificateVoided',
        'App\Modules\Taxation\Domain\Events\WithholdingSubmittedToTEJ',

        // — no register row.
        'App\Modules\Vehicle\Domain\Events\MileageAnomalyDetected',
        'App\Modules\Vehicle\Domain\Events\MileageReadingLogged',

        // ES-88 — VoucherFraudAlert and VoucherLookupSoftAlert are named in the
        // row ("listener absence was never verified"); verified here.
        'App\Modules\Voucher\Domain\Events\VoucherFraudAlert',
        // — no register row (the four lifecycle events below).
        'App\Modules\Voucher\Domain\Events\VoucherFullyRedeemed',
        'App\Modules\Voucher\Domain\Events\VoucherIssued',
        // ES-88.
        'App\Modules\Voucher\Domain\Events\VoucherLookupSoftAlert',
        // — no register row.
        'App\Modules\Voucher\Domain\Events\VoucherPartiallyRedeemed',
        'App\Modules\Voucher\Domain\Events\VoucherVoided',

        // — no register row. The Technician module's own emissions are dead;
        // its LISTENERS (on WorkOrder lifecycle events) are live and wired.
        'App\Modules\Workshop\Technician\Domain\Events\TechnicianCertificationExpiring',
        'App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryClosed',
        'App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryStarted',

        // — no register row. Seven WorkOrder lifecycle events with no listener,
        // alongside seven siblings (Started/Paused/Resumed/CompletedV2/
        // Cancelled/Closed/PartsNeeded) that DO have listeners. The split is
        // undocumented.
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderApproved',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCreated',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderDiagnosed',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderInvoiced',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderLineUpdated',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderQuoted',
        'App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderWaitingParts',
    ];

    public function test_no_new_orphaned_event_has_been_introduced(): void
    {
        $this->assertSame(
            self::BASELINE,
            $this->discoverOrphanedEvents(),
            "ORPHANED-EVENT RATCHET (ES wave A0, M5).\n\n"
            ."An event class is 'orphaned' when it is dispatched somewhere in app/ and has ZERO explicitly-registered "
            .'listeners — it fires into nothing, and whatever behaviour it was supposed to trigger silently does not '
            ."exist.\n\n"
            .'IF THE DIFF ADDED A NAME: you dispatched an event nobody listens to. Either register a listener (and '
            .'write the test that proves the listener runs), or — if the event is genuinely fire-and-forget — add it '
            ."to BASELINE with a one-line justification. Do NOT add it silently.\n\n"
            .'IF THE DIFF REMOVED A NAME: good. Delete that line from BASELINE. This list may only shrink.',
        );
    }

    /**
     * The ratchet's listener lookup deliberately ignores Spatie's global `*`
     * subscriber and assumes the Projectionist is empty. The second half of
     * that is a runtime fact, not a design decision, so it is asserted rather
     * than trusted: the day a projector or reactor is registered, some of the
     * names in BASELINE may have acquired a real consumer and the ratchet's
     * definition of "registered listener" has to widen to include them.
     */
    public function test_the_projectionist_is_still_empty_so_the_listener_lookup_is_complete(): void
    {
        /** @var Projectionist $projectionist */
        $projectionist = $this->app->make(Projectionist::class);

        $this->assertSame(
            [],
            array_map(
                static fn (object $p): string => $p::class,
                [...$projectionist->getProjectors(), ...$projectionist->getReactors()],
            ),
            'ORPHANED-EVENT RATCHET: a Spatie projector or reactor is now registered. Those consume events by '
            .'handler-method name, NOT through the dispatcher\'s listener map, so discoverOrphanedEvents() can no '
            .'longer see every consumer. Union their handled events into the listener lookup before trusting '
            .'BASELINE again.',
        );
    }

    /**
     * The wildcard exclusion is the single assumption that makes this ratchet
     * possible, so it is asserted too. If Spatie ever stops registering it, the
     * exclusion becomes dead code — and if something else registers a `*`
     * listener that IS a real consumer, the exclusion becomes wrong.
     */
    public function test_the_only_wildcard_listener_is_the_spatie_stored_event_subscriber(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);
        $wildcards = (new ReflectionClass($dispatcher))->getProperty('wildcards');
        $wildcards->setAccessible(true);

        /** @var array<string, list<mixed>> $registered */
        $registered = $wildcards->getValue($dispatcher);

        $named = [];
        foreach ($registered as $pattern => $listeners) {
            foreach ($listeners as $listener) {
                $named[] = $pattern.' => '.(is_string($listener) ? $listener : get_debug_type($listener));
            }
        }
        sort($named);

        $this->assertSame(
            ['* => Spatie\EventSourcing\StoredEvents\EventSubscriber@handle'],
            $named,
            'ORPHANED-EVENT RATCHET: the set of wildcard listeners changed. This test excludes wildcards from the '
            .'"has a listener" test because Spatie\'s `*` subscriber makes Event::hasListeners() true for literally '
            .'every string, including a class that does not exist. A NEW wildcard listener may be a real consumer — '
            .'decide explicitly whether it counts before leaving the exclusion in place.',
        );
    }

    /**
     * Every baselined name must still resolve to a class that exists. Without
     * this, a deleted event class would linger in BASELINE forever and the
     * shrink-only property would rot into a wish.
     */
    public function test_every_baselined_event_class_still_exists(): void
    {
        $missing = array_values(array_filter(
            self::BASELINE,
            static fn (string $fqcn): bool => ! class_exists($fqcn),
        ));

        $this->assertSame(
            [],
            $missing,
            'ORPHANED-EVENT RATCHET: these names are in BASELINE but no longer exist as classes. Delete their lines '
            .'— the baseline must track reality, not history.',
        );
    }

    // =================================================================
    // Discovery
    // =================================================================

    /**
     * @return list<class-string>
     */
    private function discoverOrphanedEvents(): array
    {
        $eventClasses = $this->discoverEventClasses();
        $dispatched = $this->discoverDispatchedClasses($eventClasses);
        $listened = $this->explicitlyListenedEvents();

        $orphans = array_values(array_filter(
            array_keys($dispatched),
            static fn (string $fqcn): bool => ! isset($listened[$fqcn]),
        ));

        sort($orphans);

        /** @var list<class-string> $orphans */
        return $orphans;
    }

    /**
     * Concrete event classes, keyed by FQCN with their defining file as value.
     *
     * "Event class" is defined structurally: a class living under a
     * `Domain/Events/` or `Shared/Events/` directory. That is this codebase's
     * own convention (23 such directories) and it is what the register's
     * dead-event rows enumerate.
     *
     * @return array<class-string, string>
     */
    private function discoverEventClasses(): array
    {
        $classes = [];

        foreach ($this->phpFilesUnderApp() as $path => $source) {
            if (preg_match('#/(?:Domain/Events|Shared/Events)/#', $path) !== 1) {
                continue;
            }
            $fqcn = $this->classNameIn($source);
            if ($fqcn === null) {
                continue;
            }
            $classes[$fqcn] = $path;
        }

        /** @var array<class-string, string> $classes */
        return $classes;
    }

    /**
     * Which of `$eventClasses` are dispatched somewhere in `app/`, mapped to the
     * files that dispatch them.
     *
     * @param  array<class-string, string>  $eventClasses
     * @return array<class-string, list<string>>
     */
    private function discoverDispatchedClasses(array $eventClasses): array
    {
        $dispatched = [];

        foreach ($this->phpFilesUnderApp() as $path => $source) {
            $aliases = $this->useMap($source);
            $namespace = $this->namespaceIn($source);

            foreach ($this->instantiatedOrDispatchedShortNames($source) as $short) {
                $candidates = [];
                if (isset($aliases[$short])) {
                    $candidates[] = $aliases[$short];
                } elseif ($namespace !== null) {
                    $candidates[] = $namespace.'\\'.$short;
                }
                // A fully-qualified `new \App\...\Foo(` writes the leading
                // separator; normalise it away.
                $candidates[] = ltrim($short, '\\');

                foreach ($candidates as $fqcn) {
                    if (! isset($eventClasses[$fqcn]) || $eventClasses[$fqcn] === $path) {
                        continue;
                    }
                    $dispatched[$fqcn][] = $path;
                    break;
                }
            }
        }

        /** @var array<class-string, list<string>> $dispatched */
        return $dispatched;
    }

    /**
     * The dispatcher's EXPLICIT listener map — wildcards excluded (see the class
     * docblock for why that exclusion is the whole point).
     *
     * @return array<string, true>
     */
    private function explicitlyListenedEvents(): array
    {
        $dispatcher = $this->app->make(Dispatcher::class);
        $property = (new ReflectionClass($dispatcher))->getProperty('listeners');
        $property->setAccessible(true);

        /** @var array<string, mixed> $listeners */
        $listeners = $property->getValue($dispatcher);

        $keys = [];
        foreach (array_keys($listeners) as $event) {
            $keys[(string) $event] = true;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function instantiatedOrDispatchedShortNames(string $source): array
    {
        $names = [];

        if (preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*\(/', $source, $m) > 0) {
            $names = array_merge($names, $m[1]);
        }
        if (preg_match_all('/\b(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::dispatch(?:If|Unless)?\s*\(/', $source, $m) > 0) {
            $names = array_merge($names, $m[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, string> alias => FQCN
     */
    private function useMap(string $source): array
    {
        $map = [];

        if (preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mi', $source, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $fqcn = $match[1];
                $alias = ($match[2] ?? '') !== '' ? $match[2] : substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $map[$alias] = $fqcn;
            }
        }

        return $map;
    }

    private function namespaceIn(string $source): ?string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1 ? trim($m[1]) : null;
    }

    private function classNameIn(string $source): ?string
    {
        $namespace = $this->namespaceIn($source);
        if ($namespace === null) {
            return null;
        }
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $m) !== 1) {
            return null;
        }

        return $namespace.'\\'.$m[1];
    }

    /**
     * Read every PHP file under `app/` exactly once per test run.
     *
     * @return array<string, string> absolute path => source
     */
    private function phpFilesUnderApp(): array
    {
        static $files = null;

        if ($files !== null) {
            return $files;
        }

        $files = [];
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
