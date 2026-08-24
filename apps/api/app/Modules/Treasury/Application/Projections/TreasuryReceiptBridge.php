<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\DTOs\PosRevenueVatSplit;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Accounting\Domain\Services\PosReceiptVatAllocator;
use App\Modules\Compliance\Services\AuditService;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Application\DTOs\PosPaymentPolicyDTO;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Application\DTOs\MaturityLegContext;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Application\Projections\Concerns\ResolvesTerminalLocation;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\CancellationShape;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CashRoundingCutover;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Treasury-operational projector for `SALE_RECEIPT` fiscal events —
 * spec v7 §7.4 + §13 + SoT §13.6/D16.
 *
 * **Module-gated.** `requiresModule() === 'Treasury'` — the registry
 * (Task 18) only dispatches this projector for events whose
 * `(tenant_id, company_id)` has the Treasury operational module active
 * per `ModuleActivationResolver`. A deployment with Treasury inactive
 * leaves the POS-core projection complete and writes ZERO `payments`
 * rows + ZERO GL entries — exactly the asymmetric bounded-modules seam
 * (POS-core depends only on mirrored reference data; Treasury operational
 * is the outbound dependency that the bridge + resolver gate).
 *
 * **Bridge scope.** Owns *only* the Treasury-operational effects relocated
 * from `ReceiptPaymentService::processReceiptPayments()`:
 *   - one `payments` row per `payload['payment_lines'][]` entry, stamped
 *     `origin = PaymentOrigin::Pos` and `fiscal_event_id = $event->id`
 *     (spec §13 writer row 1).
 *   - one POS-payment GL entry per row via
 *     `GeneralLedgerService::createPOSPaymentEntry()`, posted immediately
 *     (no draft state — POS payments are DIRECT TO REVENUE).
 *   - the back-link `payments.journal_entry_id` populated post-post.
 *
 * **Training containment (LEDGER gate G-3).** A receipt whose sealed canonical
 * payload carries `training_flag = true` is a rehearsal, not a sale: `apply()`
 * returns before ANY Treasury effect — no `payments` row, no GL post, no
 * `repository_movements` movement, no instrument lifecycle write, no §4.6
 * rounding/tolerance entry — for sales and refunds/voids alike. The projection
 * still completes cleanly so the queue row reaches `applied`. Pinned by
 * `tests/Feature/Treasury/TrainingReceiptTreasuryContainmentTest.php`.
 *
 * This is DEFENSE-IN-DEPTH rather than a live-path fix: no producer can
 * currently author a training event on ANY operational chain. `FiscalEventEngine`
 * defaults `chain_context` to `'operational'` (`FiscalEventEngine.ts:565`) and
 * throws on a `training_flag` outside a `training_*` context (`:815-818`), and
 * no service passes a training context. That refusal covers SALE_RECEIPT and
 * ACCOUNT_PAYMENT alike — both sit in `OPERATIONAL_CHAIN_EVENT_TYPES` (`:219-220`)
 * — so the sibling guard in `TreasuryAccountPaymentBridge` is in exactly the
 * same position, not a stronger one. These guards cover legacy rows, replays,
 * quarantine repairs, directly-inserted rows, and the moment training authoring
 * is switched on.
 *
 * **Pre-enable census.** Because authoring is blocked on every operational
 * chain, the expected count of already-written training-created money rows is
 * ZERO — but that must be CONFIRMED per tenant at deploy, never assumed. The
 * four money-side census queries live in
 * `docs/superpowers/tickets/2026-08-21-training-latent-surfaces-deposit-and-exchange.md`
 * (§ Census — pre-enable verification).
 *
 * **Change netting (spec §4.6, `event_version >= 3`).** From the cash-rounding
 * cutover on, `payments.amount` is the RETAINED amount, not the tendered one:
 * a pre-pass in `apply()` subtracts the over-tender from the last cash leg
 * (cascading backwards) and a leg netted to zero writes NOTHING at all. See
 * {@see computeNettedAmounts()} for the full contract, including why v1/v2
 * must stay byte-identical on replay and not merely on first apply.
 *
 * **Out of scope.** `pos_receipt_payments` rows + voucher redemption +
 * stock movement + the `pos_receipts` projection — those live in
 * `PosCoreReceiptProjection` (Task 21). The bridge imports ZERO POS
 * write surfaces beyond the `Receipt` read model + the inbound mirrored
 * `PaymentRepository`.
 *
 * **Idempotency.** Unlike `pos_receipts` (where a UNIQUE on
 * `fiscal_event_id` is the projection idempotency anchor for the POS-core
 * projector), `payments.fiscal_event_id` is **NOT UNIQUE** — Task 12
 * explicitly chose this because one fiscal event has N Payment rows
 * (one per split-payment line). The idempotency guard is therefore a
 * projector-level check-then-insert:
 *
 *   `if (Payment::where('fiscal_event_id', $event->id)
 *          ->where('origin', PaymentOrigin::Pos)->exists()) return;`
 *
 * **Race-safety contract (Task 22 round-2 — Codex F1 P1).** `apply()` is
 * single-flight via two complementary fences:
 *   1. **PG transaction-scoped advisory lock** acquired inside the
 *      wrapping `DB::transaction()` keyed on
 *      `hashtext($event->id . ':treasury_receipt_bridge')`. The lock
 *      is held until the transaction commits or rolls back, then
 *      released automatically. Concurrent `apply()` invocations for the
 *      same event serialize at the lock — the second waiter sees the
 *      first's committed rows and short-circuits via the inner re-check
 *      below. The hash key is bridge-scoped so other projectors for the
 *      same event run in parallel. SQLite has no advisory locks; the
 *      `pg_advisory_xact_lock` call is wrapped in a PG-driver guard.
 *   2. **Task 23 projection-row `lockForUpdate()`** (forward-promise) —
 *      `ApplyFiscalEventProjectionJob` is expected to take a row lock
 *      on the `fiscal_event_projections` row keyed on
 *      `(fiscal_event_id, 'treasury_receipt_bridge')` BEFORE invoking
 *      the projector. The Task 23 plan does not currently spell this
 *      out; the bridge's advisory lock is belt-and-braces insurance
 *      that doesn't depend on Task 23's exact lifecycle design.
 *
 * Manual operator replay paths (e.g.,
 * `fiscal:enqueue-resolved-event-projections`) inherit the advisory
 * lock semantics automatically — two concurrent replays serialize at
 * the lock, the second sees the first's idempotency-probe hit and
 * returns cleanly.
 *
 * **Bounded-modules guardrail (SoT §13.6/D16).** This class imports
 * ZERO Fiscal-engine internals; it depends only on:
 *   - `FiscalEvent` (read-only — the verified event row)
 *   - `FiscalPayloadArrayGuards` + `FiscalEventProjector` interface
 * The outbound operational dependency on Accounting (`GeneralLedgerService`)
 * stays here, NOT in `PosCoreReceiptProjection` — Accounting + Treasury
 * sit on the same side of the asymmetric seam (both are operational
 * modules the POS-core projection is permitted to remain ignorant of).
 *
 * The inbound POS surface stays read-only and is exactly two classes: the
 * `Receipt` read model, and `PosPaymentPolicyResolver` — a public POS
 * Application service consulted ONLY to decide whether a tolerance write-off
 * exceeded the live effective ceiling (telemetry, never a gate). No POS write
 * surface is imported.
 *
 * **§14 retention disposition.** The legacy
 * `ReceiptPaymentService::processReceiptPayments()` still writes a
 * Treasury `Payment` + GL entry inline when invoked from
 * `ReceiptController::storePayments()` (`POST /pos/receipts/{id}/payments`).
 * The retired `/pos/receipts/sync` path no longer exists. Per spec v7
 * §14.1 (receipt sync retirement, Task 27B Pass 2B) + §14.2 (new-sale
 * authoring disposition, Task 29) + §14.3 (two-chokepoint CI grep
 * gate, Task 30) that legacy write is "knowingly retained
 * no-new-writers" through the rollout window. DO NOT physically
 * remove the legacy `Payment::create` call — the documented
 * disposition keeps it functional until Tasks 28-30 land. The legacy
 * call gets stamped `origin = PaymentOrigin::Pos` + `fiscal_event_id
 * = null` (no fiscal event was authored device-side for this server-
 * recompute code path).
 */
final class TreasuryReceiptBridge implements FiscalEventProjector
{
    use ResolvesTerminalLocation;

    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly PaymentMethodResolver $paymentMethodResolver,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly HandlesMaturityTenderLeg $maturityLegHandler,
        private readonly InstrumentLifecycleService $instrumentLifecycle,
        private readonly AuditService $auditService,
        // Read-only POS Application service (no write surface) — the SAME
        // fail-closed effective-policy gate that decided what the device was
        // allowed to cache, and the only worker-safe way to reach it (explicit
        // company id, no CompanyContext, no no-arg getScale()).
        private readonly PosPaymentPolicyResolver $posPaymentPolicyResolver,
        // DPA lane G3 requirement 4 — the shared tender→repository rule, so the
        // shift-variance listener and this projection cannot drift apart.
        private readonly TenderRepositoryResolver $tenderRepositoryResolver,
        // W4-9 — apportions the SEALED `pos_receipt_vat_details` across this
        // event's tender legs. Injected (never `app()`), and it takes the
        // currency scale as an argument because this projector runs with no
        // CompanyContext bound.
        private readonly PosReceiptVatAllocator $vatAllocator,
    ) {}

    public function name(): string
    {
        return 'treasury_receipt_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): string
    {
        // Canonical PascalCase token — `CompanyConfig::hasModule()` strict-
        // compares against the `config/verticals.php` `default_modules`
        // identifier set (read via `VerticalConfigService`);
        // a lowercase `'treasury'` would silently always-deactivate (locked
        // by `FiscalEventProjectionRegistryTest::test_canonical_pascalcase_token_required`).
        return 'Treasury';
    }

    public function priority(): int
    {
        // Task 22 round-2 (Codex T22-B1 / Opus F3 — convergent BLOCKER):
        // the bridge depends on PosCoreReceiptProjection having written
        // the `pos_receipts` row for the same fiscal event (it scopes
        // its Treasury Payment writes to that receipt). The registry
        // sorts projectors by (priority ASC, name ASC); PosCoreReceiptProjection
        // declares 50 and runs first. The bridge declares 150 and runs
        // after. Before this round-2 change, dispatch order accidentally
        // tracked `bootstrap/providers.php` registration order — Treasury
        // is registered BEFORE POS at L65/L76, so the bridge ran first,
        // hit the deferred-bail-out branch, returned cleanly, and was
        // marked `applied` by Task 23 — silently skipping Treasury Payment +
        // GL writes for every event. See the class docblock for the
        // full race-safety contract.
        return 150;
    }

    public function apply(FiscalEvent $event): void
    {
        // Task 20 (complete-set replay, review F3/F4): the pre-spine "any
        // pos-origin Payment exists for this event" probe is GONE — it was a
        // partial-replay hole (if only SOME tender legs were written, the
        // whole event was skipped and the remaining legs never landed).
        // Idempotency is now PER-LEG (see projectPaymentLineFromCanonical):
        // every canonical tender leg is validated to exist, missing legs are
        // written, present legs are idempotent hits. So a partial prior write
        // is COMPLETED, not skipped.

        // The verified-event payload is always present on a successfully-
        // parsed SALE_RECEIPT. Defensive bail-out kept symmetric with
        // PosCoreReceiptProjection so a quarantine row whose parse failed
        // is treated as a no-op rather than crashing the projector job.
        $payload = $event->payload;
        if (! is_array($payload)) {
            return;
        }

        // The Treasury bridge writes rows scoped to the receipt row that
        // PosCoreReceiptProjection (Task 21) wrote for the same event.
        // Task 22 round-2 (Codex T22-B1 / Opus F3 — convergent BLOCKER):
        // dispatch order is now self-described by `priority()` —
        // PosCoreReceiptProjection declares 50, the bridge declares 150;
        // the registry sorts the tagged set by (priority ASC, name ASC)
        // at boot. So the POS-core projector's `fiscal_event_projections`
        // row is enqueued ahead of the bridge's by `OutboxIngestor`
        // (Task 19).
        //
        // Task 23 round-2 (Codex T23-B2 BLOCKER) — `priority()` only
        // constrains the ENQUEUE order. Under multiple Horizon workers
        // the EXECUTION order is whatever order workers reserve jobs off
        // the queue. The Treasury worker can run first; if it does, the
        // pos_receipts row is not yet committed and the lookup below
        // returns null. Round-1 returned cleanly (`Log::warning + return`)
        // and `ApplyFiscalEventProjectionJob` then marked the row
        // `applied` — silently skipping Treasury Payment + GL writes for
        // every event that hit that race window.
        //
        // Round-2 fix: throw `ProjectionDependencyMissingException` on
        // null. The job's fail-closed `catch (Throwable)` advances
        // `attempts` accounting + re-throws → Horizon retries with
        // backoff → POS-core's sibling job lands first → the bridge's
        // next attempt sees the receipt → succeeds. The `QueryException`
        // path stays a clean return — the driver-layer error has its own
        // backoff via the surrounding job's retry; throwing here would
        // double-count attempts.
        try {
            $receipt = Receipt::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->where('fiscal_event_id', $event->id)
                ->first();
        } catch (QueryException $e) {
            Log::warning('TreasuryReceiptBridge: receipt lookup failed', [
                'fiscal_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($receipt === null) {
            throw new ProjectionDependencyMissingException(
                projectorName: $this->name(),
                fiscalEventId: $event->id,
                missingDependency: 'pos_receipts row (PosCoreReceiptProjection not yet committed)',
            );
        }

        // $payload was used by the pre-Pass-2A.PHP.2 read path (direct
        // `FiscalPayloadArrayGuards::requireArray($payload, 'payment_lines')`);
        // the canonical reader now resolves the same data via
        // `forSaleReceipt($event)`. Keep the null-check on $event->payload
        // above as defensive bail-out for quarantine rows.
        unset($payload);

        $terminalLocationId = $this->resolveTerminalLocationId($event);

        DB::transaction(function () use ($event, $receipt, $terminalLocationId): void {
            // Task 22 round-2 (Codex F1 P1) — PG transaction-scoped
            // advisory lock keyed on (event_id, projector_name). The lock
            // is held until the transaction commits or rolls back, then
            // released. Serializes concurrent apply() calls for the same
            // event — the second waiter blocks until the first commits,
            // then short-circuits at the inner re-check below. Other
            // projectors for the same event are NOT blocked because the
            // hash includes the projector name. SQLite has no advisory
            // locks; the driver-guard makes the SQLite test runner skip
            // the statement without disabling the inner re-check (which
            // still defends against the manual-replay race window).
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtext(?))',
                    [$event->id.':treasury_receipt_bridge'],
                );
            }

            // Task 20 — no inner "any payment exists" re-check here anymore.
            // Per-leg idempotency (a partial UNIQUE on
            // payments(company_id, idempotency_key) + the movement port's
            // per-key record()) makes each leg independently replay-safe, so
            // the advisory lock above is now purely a concurrency serializer:
            // the second waiter sees each committed leg as an idempotent hit.

            // Task 20 review Fix 1 (MAJOR) — legacy null-key fallback.
            // Payments written by the PRE-Task-20 bridge carry
            // `origin = Pos` + `fiscal_event_id = $event->id` but NO
            // `idempotency_key` (the per-leg key scheme did not exist yet, and
            // Task 20 shipped no backfill). The per-leg lookup in
            // projectPaymentLineFromCanonical() keys on
            // `payments.idempotency_key = 'fiscal_event:{id}:payment:{i}'`, so
            // it would MISS every legacy row → take the CREATE branch → write a
            // duplicate Payment + a duplicate POS-revenue GL post
            // (journal_entries(source_type, source_id) is NOT uniquely
            // constrained) + a duplicate balance movement. Fiscal projections
            // are redelivery-driven, so a pre-Task-20-projected event
            // redelivered after cutover is a silent wrong-money defect that
            // clean-DB tests never exercise.
            //
            // SAFE fix: recognize the legacy event at the EVENT level and treat
            // the WHOLE event as already-settled — do NOT attempt to backfill or
            // guess per-leg ordinals for the null-key rows. New (post-Task-20)
            // events stamp per-leg keys on every row, so this probe finds
            // nothing for them and they continue down the per-leg path below.
            $legacyNullKeyPaymentExists = Payment::query()
                ->where('company_id', $event->company_id)
                ->where('fiscal_event_id', $event->id)
                ->where('origin', PaymentOrigin::Pos)
                ->whereNull('idempotency_key')
                ->exists();

            if ($legacyNullKeyPaymentExists) {
                return;
            }

            // Pass 2A.PHP.2 — read from the canonical view. The 27-key
            // payload exposes `payments[]` with `method_code`, NOT
            // `payment_method_id`. Resolve the FK via the Shared/Contracts
            // PaymentMethodResolver seam (synthesis v5 §8.B + dispatch
            // §0 Gap A). `repository_id` is NOT on the canonical payload
            // either — the bridge resolves it via a tenant+company-scoped
            // payment-method mapping with deterministic fallback (see
            // resolveRepositoryForTender).
            $view = $this->canonicalReader->forSaleReceipt($event);

            // ============================================================
            // LEDGER gate G-3 — TRAINING receipts never move real money.
            // ============================================================
            // A training receipt is a rehearsal on a live terminal: nothing
            // is sold, no cash enters the drawer, no revenue is earned. The
            // device still authors a fully-signed SALE_RECEIPT (the hash
            // chain has no "practice" mode) with `training_flag = true`, so
            // the flag on the SEALED payload is the only honest
            // discriminator — never the mutable `pos_receipts.is_training`
            // mirror, and never a heuristic on amounts.
            //
            // EVERY Treasury effect this projector owns is suppressed from
            // here down, for SALES and REFUNDS/VOIDS alike (a refund rides
            // this same SALE_RECEIPT event through this same apply(), keyed
            // off `invoice_type_code` below — so one gate covers both):
            //   - the `payments` row (origin=Pos, status=Completed),
            //   - the synchronously-posted POS revenue / reversal GL entry,
            //   - the `repository_movements` drawer balance movement,
            //   - instrument receive/cancel lifecycle writes, and
            //   - the §4.6 rounding + tolerance entries below.
            //
            // Returning cleanly (rather than throwing) is deliberate: the
            // read-model row is PosCoreReceiptProjection's job (priority 50,
            // already training-aware) and `ApplyFiscalEventProjectionJob`
            // still marks this projection `applied`, so a rehearsal never
            // parks a permanently-retrying projection row.
            //
            // Placed AFTER the legacy null-key short-circuit so a
            // pre-Task-20 event keeps its existing untouched-return path.
            if ($view->payload->trainingFlag === true) {
                return;
            }

            // Spec §4.6 netting pre-pass. Gated on
            // `event_version >= CashRoundingCutover::EVENT_VERSION` — the same
            // single discriminator PosCoreReceiptProjection uses, so the read
            // model and the ledger can never disagree about a receipt.
            //
            // Deliberately placed AFTER the legacy null-key short-circuit
            // above: a pre-Task-20 event returns before any netting work is
            // even attempted, so it stays untouched.
            $nettedAmounts = $this->computeNettedAmounts($event, $view, $receipt);
            $currencyScale = $view->payload->currencyScale;

            // Task 21 — a refund/void rides the SALE_RECEIPT event carrying
            // `invoice_type_code='REFUND'` (or 'VOID') + a non-null
            // `original_receipt_reference` (there is NO separate REFUND event
            // type — REFUND_RECEIPT/SALE_VOID are RESERVED_UNREACHABLE). Every
            // money field in the payload is NON-NEGATIVE (§6), so the refund
            // signal is the invoice_type_code, NOT a negative amount. For a
            // refund immediate legs pay cash OUT and reverse the sale entry;
            // Task 17 maturity legs instead cancel exactly one still-Received
            // original instrument or fall back to cash with a durable alert.
            // A plain SALE keeps the Task-20 IN + sale-GL behavior. Decided
            // once from the immutable payload so the receipt stays consistent.
            $invoiceTypeCode = $view->payload->invoiceTypeCode;
            $isRefund = $invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID';
            $originalEventId = $view->originalReceiptReference?->fiscalEventId;

            $totalLines = count($view->payments);

            // W4-9 — decide the revenue/VAT decomposition ONCE, for the whole
            // receipt, from the amounts the legs will actually retain. It has to
            // happen here rather than per leg: the sealed VAT is a receipt-level
            // fact and apportioning it needs every leg's amount at once.
            //
            // A refusal (no sealed rows, VAT above the tender, a split that will
            // not reconcile) throws out of the enclosing DB::transaction, so the
            // projection row stays pending and Horizon retries — the projector
            // never acknowledges a receipt it cannot book correctly.
            /** @var list<string> $legAmounts */
            $legAmounts = [];
            for ($i = 0; $i < $totalLines; $i++) {
                $legAmounts[] = $nettedAmounts[$i] ?? $view->payments[$i]->amount;
            }
            $vatSplits = $this->vatAllocator->allocate($receipt, $legAmounts, $currencyScale);

            $index = 0;
            foreach ($view->payments as $payment) {
                $this->projectPaymentLineFromCanonical(
                    $event,
                    $receipt,
                    $payment,
                    $index,
                    $totalLines,
                    $isRefund,
                    $originalEventId,
                    $terminalLocationId,
                    $nettedAmounts[$index] ?? $payment->amount,
                    $currencyScale,
                    $vatSplits[$index],
                );
                $index++;
            }

            // Spec §4.6 new entries — v3-gated, inside the SAME transaction as
            // the tender legs. Both run AFTER the loop so a missing purpose
            // account can never block revenue recognition: the legs are already
            // written when the precheck decides to skip.
            //
            // The training discriminator that used to be duplicated here is
            // GONE on purpose: the G-3 gate at the top of this closure already
            // returned for every training receipt, at every event_version, so
            // repeating `trainingFlag !== true` here could only ever be true
            // and would falsely suggest the containment lives at this line.
            // A v1/v2 event still has no rounding semantics to book.
            if (CashRoundingCutover::applies($event->event_version)) {
                $this->postCashRoundingEntry($event, $receipt, $view, $isRefund, $currencyScale);
                $this->postToleranceWriteoffEntry($event, $receipt, $view, $currencyScale);
            }
        });
    }

    /**
     * Post the cash-rounding difference for a v3 receipt (spec §4.6 entry 1).
     *
     * No-ops on a canonical zero adjustment (an exact multiple of the
     * denomination is not a difference).
     *
     * **This is the ONLY additional GL a rounded receipt gets.** Task 9 already
     * netted the customer's change off the cash legs, so the sale entry balances
     * at the retained figure — re-booking that change here would double-count it.
     */
    private function postCashRoundingEntry(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        bool $isRefund,
        int $currencyScale,
    ): void {
        $adjustment = $view->cashRoundingAdjustmentOrZero();
        if (! is_numeric($adjustment) || bccomp($adjustment, '0', $currencyScale) === 0) {
            return;
        }

        $sourceType = $isRefund ? 'pos_cash_rounding_refund' : 'pos_cash_rounding';

        // Probe BEFORE creating. The Task-7 partial unique indexes on
        // (source_type, source_id) are the DB-level backstop, not the guard: a
        // second apply() that reached the insert would raise a 23505 and turn a
        // clean replay into a permanently-failing queue job.
        if ($this->journalEntryExists($sourceType, (string) $receipt->id)) {
            return;
        }

        $roundedUp = bccomp($adjustment, '0', $currencyScale) > 0;
        $requiredPurposes = [
            // Both halves of the entry are prechecked. ProductRevenue is
            // normally guaranteed by the tender legs having posted — but a
            // receipt whose only cash leg was fully netted away writes no sale
            // entry at all, so it cannot be assumed here.
            SystemAccountPurpose::ProductRevenue,
            $roundedUp
                ? SystemAccountPurpose::PaymentToleranceIncome
                : SystemAccountPurpose::PaymentToleranceExpense,
        ];

        foreach ($requiredPurposes as $purpose) {
            if (! $this->generalLedgerService->hasAccountForPurpose($event->company_id, $purpose)) {
                $this->recordTolerancePurposeMissingAlertOrFail($event, $receipt, $purpose->value, $sourceType);

                return;
            }
        }

        $entry = $this->generalLedgerService->createPosCashRoundingEntry(
            $receipt,
            $adjustment,
            $sourceType,
            $currencyScale,
        );
        // Explicit currency: this projector runs on a Horizon worker where no
        // CompanyContext is bound and the no-arg scale resolution fails loud.
        $this->generalLedgerService->postEntryNow($entry, $receipt->cashier, $receipt->currency);
    }

    /**
     * Post the tender-tolerance write-off for a v3 receipt (spec §4.6 entry 2).
     *
     * The shortfall is computed from the TENDERED leg amounts, never the netted
     * ones — netting only ever removes an OVER-tender, so netted amounts would
     * define the gap away. It is likewise computed here rather than read off
     * `pos_receipts.tolerance_writeoff`: that column is `'0.000'` (not NULL) on
     * a v3 no-shortfall receipt, and the bridge must not take a dependency on
     * another projector's row shape.
     *
     * A REFUND/VOID posts the same direction as a sale. Entry 1 has an explicit
     * spec-mandated reversal; entry 2 has none, and no device path produces a
     * short-tendered refund today — flagged rather than invented.
     */
    private function postToleranceWriteoffEntry(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        int $currencyScale,
    ): void {
        $tendered = bcadd('0', '0', $currencyScale);
        foreach ($view->payments as $line) {
            if (! is_numeric($line->amount)) {
                // Unreachable: computeNettedAmounts() already threw on this at
                // v3. Kept as an honest guard rather than an inline @var cast.
                throw new RuntimeException(sprintf(
                    'TreasuryReceiptBridge: payment amount %s is not numeric for fiscal_event %s',
                    $line->amount,
                    $event->id,
                ));
            }
            $tendered = bcadd($tendered, $line->amount, $currencyScale);
        }

        $total = $view->payload->total;
        if (! is_numeric($total)) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payload total %s is not numeric for fiscal_event %s',
                $total,
                $event->id,
            ));
        }

        $shortfall = bcsub($total, $tendered, $currencyScale);
        if (bccomp($shortfall, '0', $currencyScale) <= 0) {
            return;
        }

        if ($this->journalEntryExists('pos_tolerance_bridge', (string) $receipt->id)) {
            return;
        }

        $requiredPurposes = [
            SystemAccountPurpose::ProductRevenue,
            SystemAccountPurpose::PaymentToleranceExpense,
        ];

        foreach ($requiredPurposes as $purpose) {
            if (! $this->generalLedgerService->hasAccountForPurpose($event->company_id, $purpose)) {
                $this->recordTolerancePurposeMissingAlertOrFail(
                    $event,
                    $receipt,
                    $purpose->value,
                    'pos_tolerance_bridge',
                );

                return;
            }
        }

        $entry = $this->generalLedgerService->createPosToleranceWriteoffEntry($receipt, $shortfall);
        $this->generalLedgerService->postEntryNow($entry, $receipt->cashier, $receipt->currency);

        $this->alertIfShortfallExceedsConfigSafely($event, $receipt, $view, $shortfall, $total, $currencyScale);
    }

    /**
     * Source-type-scoped existence probe. The two new literals are disjoint from
     * every other GL writer's, so `(source_type, source_id)` identifies exactly
     * one entry per receipt per purpose.
     */
    private function journalEntryExists(string $sourceType, string $sourceId): bool
    {
        return DB::table('journal_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * Fail-closed wrapper: **the rethrow is the load-bearing part.** It rolls
     * back the bridge's outer `DB::transaction()`, so the projection row stays
     * pending and Horizon retries. A purpose-missing projection must never be
     * acknowledged when its only durable operator signal was not persisted.
     *
     * The nested `DB::transaction()` (a SAVEPOINT) is recovery-only here, NOT
     * the mechanism that makes this safe. Its job is narrow: on PostgreSQL a
     * failed statement aborts the enclosing transaction (`25P02` on everything
     * after it), so the savepoint leaves the connection in a state where the
     * outer rollback and the job's own failure accounting can still run. It is
     * NOT containment in the "carry on regardless" sense — nothing here
     * continues after the catch.
     *
     * Contrast {@see alertIfShortfallExceedsConfigSafely()}, where the savepoint
     * IS load-bearing: that path swallows, because the write-off it reports on
     * was already posted and the money must not be undone by a telemetry fault.
     * The two wrappers look alike and mean opposite things — do not unify them
     * without deciding which discipline each alert deserves.
     */
    private function recordTolerancePurposeMissingAlertOrFail(
        FiscalEvent $event,
        Receipt $receipt,
        string $purpose,
        string $sourceType,
    ): void {
        try {
            DB::transaction(function () use ($event, $receipt, $purpose, $sourceType): void {
                $this->recordTolerancePurposeMissingAlert($event, $receipt, $purpose, $sourceType);
            });
        } catch (\Throwable $e) {
            Log::error('TreasuryReceiptBridge: purpose-missing alert failed; rolling back projection for retry', [
                'fiscal_event_id' => $event->id,
                'missing_purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Durable signal that a chart of accounts cannot carry a rounding or
     * tolerance entry. Same idempotency-guarded `audit_events` shape as
     * {@see recordMaturityRefundAlert()}.
     *
     * The probe is keyed on the fiscal event, so a receipt that skips BOTH
     * entries for the same missing purpose records one alert naming the first
     * one it hit — the operator fix (seed/backfill the purpose) is identical
     * either way.
     */
    private function recordTolerancePurposeMissingAlert(
        FiscalEvent $event,
        Receipt $receipt,
        string $purpose,
        string $sourceType,
    ): void {
        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.gl.tolerance_purpose_missing')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.gl.tolerance_purpose_missing',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'receipt_id' => (string) $receipt->id,
                    'missing_purpose' => $purpose,
                    'skipped_source_type' => $sourceType,
                ],
            );
        }

        Log::warning('POS GL skipped a rounding/tolerance entry: the system-purpose account is missing.', [
            'fiscal_event_id' => $event->id,
            'missing_purpose' => $purpose,
            'skipped_source_type' => $sourceType,
        ]);
    }

    /**
     * Run the beyond-config check inside a SAVEPOINT so telemetry can never cost
     * the write-off that was already posted.
     *
     * The nested `DB::transaction()` is load-bearing and NOT interchangeable
     * with a bare try/catch: on PostgreSQL a failed statement aborts the whole
     * transaction (`25P02` on everything after it), so swallowing the exception
     * without rolling back to a savepoint would poison the outer transaction and
     * fail the commit anyway. Mirrors
     * `PosCoreReceiptProjection::reconcileRoundingPolicySafely()`.
     *
     * @param  numeric-string  $shortfall
     * @param  numeric-string  $total
     */
    private function alertIfShortfallExceedsConfigSafely(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        string $shortfall,
        string $total,
        int $currencyScale,
    ): void {
        try {
            DB::transaction(function () use ($event, $receipt, $view, $shortfall, $total, $currencyScale): void {
                $this->alertIfShortfallExceedsConfig($event, $receipt, $view, $shortfall, $total, $currencyScale);
            });
        } catch (\Throwable $e) {
            Log::warning('TreasuryReceiptBridge: shortfall-ceiling check failed (write-off unaffected)', [
                'fiscal_event_id' => $event->id,
                'shortfall' => $shortfall,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The write-off is POSTED regardless — the money moved. This only raises a
     * durable signal when the shortfall exceeded the EFFECTIVE configured
     * ceiling and no supervisor approval rode the payload.
     *
     * **The ceiling is `max(min(pct × total, max_amount), D)`** — spec §8.1's
     * approved tolerance denomination floor, active when rounding is on. The
     * floor is not decoration: the percentage cap is TRUNCATED at currency scale
     * (0.5% of 9.950 = 0.049), so without it the spec's OWN worked example — a
     * legitimately auto-accepted 0.050 shortfall on a 0.050 denomination — would
     * alert, and so would every full-D shortfall the device is designed to
     * accept. That is an alert storm on day one, which trains operators to
     * ignore the one signal that matters.
     *
     * The config is read through `PosPaymentPolicyResolver`, NOT the raw
     * `country_payment_settings` row: the resolver is the same fail-closed gate
     * that decided what the device was allowed to cache (round-trip validity,
     * non-positive values, the §4.1 `CashRoundingCaps` ceiling), so a
     * denomination it refuses to emit is by definition not a live floor. It is
     * worker-safe by construction — explicit company id, no `CompanyContext`,
     * no no-arg `getScale()` (rule 20).
     *
     * Tolerance disabled ⇒ ceiling zero ⇒ every shortfall alerts. That is the
     * intended fail-closed direction: a device that auto-accepted while the
     * server says tolerance is off is exactly what this alert is for. Drift
     * between the SIGNED denomination and the live one is a separate concern
     * already reported by `pos.rounding.policy_mismatch` (Task 8), which is why
     * the live policy is used here as the single source rather than a hybrid of
     * signed and live values.
     *
     * @param  numeric-string  $shortfall
     * @param  numeric-string  $total
     */
    private function alertIfShortfallExceedsConfig(
        FiscalEvent $event,
        Receipt $receipt,
        SaleReceiptCanonicalView $view,
        string $shortfall,
        string $total,
        int $currencyScale,
    ): void {
        // A supervisor already answered for this gap on the device, and the
        // approval rode the SIGNED payload. Alerting anyway would just be noise
        // on top of an authorized decision.
        if ($this->hasTenderToleranceApproval($view)) {
            return;
        }

        $policy = $this->posPaymentPolicyResolver->forCompany($event->company_id);
        $effectiveMax = $this->effectiveToleranceCeiling($policy, $total, $currencyScale);

        if (bccomp($shortfall, $effectiveMax, $currencyScale) <= 0) {
            return;
        }

        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.tolerance.shortfall_exceeds_config')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.tolerance.shortfall_exceeds_config',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'receipt_id' => (string) $receipt->id,
                    'shortfall' => $shortfall,
                    'effective_max' => $effectiveMax,
                    'total' => $total,
                ],
            );
        }

        Log::warning('POS tolerance write-off exceeded the configured ceiling; posted and flagged.', [
            'fiscal_event_id' => $event->id,
            'shortfall' => $shortfall,
            'effective_max' => $effectiveMax,
        ]);
    }

    /**
     * `max(min(pct × total, max_amount), D)` — spec §8.1, with the denomination
     * floor applied only when rounding is live AND the sale is non-zero.
     *
     * The percentage cap is computed at the CURRENCY scale, i.e. TRUNCATED
     * (bcmul truncates), never rounded up: the cap can only ever be
     * conservative, and the floor is what admits a full-`D` shortfall. This
     * mirrors the device's `toleranceEffectiveMax` exactly — the two must agree
     * or the server flags what the device was told to accept.
     *
     * Anything the resolver refuses to emit (tolerance off, unusable
     * percentage/max, rounding off, non-positive denomination) collapses the
     * corresponding term to zero. Tolerance off ⇒ ceiling zero ⇒ every
     * shortfall is beyond config, which is the fail-closed direction.
     *
     * @param  numeric-string  $total
     * @return numeric-string
     */
    private function effectiveToleranceCeiling(
        PosPaymentPolicyDTO $policy,
        string $total,
        int $currencyScale,
    ): string {
        $zero = bcadd('0', '0', $currencyScale);

        if (! $policy->tenderToleranceEnabled) {
            return $zero;
        }

        // The resolver already refuses to emit anything it cannot format, but
        // its DTO is typed `string`; guard rather than assume.
        $percentage = $policy->tenderTolerancePercentage;
        $maxAmount = $policy->tenderToleranceMaxAmount;
        if (! is_numeric($percentage) || ! is_numeric($maxAmount)) {
            return $zero;
        }

        $percentageCap = bcmul($total, $percentage, $currencyScale);
        $ceiling = bccomp($percentageCap, $maxAmount, $currencyScale) <= 0 ? $percentageCap : $maxAmount;

        $denomination = $policy->cashRoundingDenomination;
        $floorApplies = $policy->cashRoundingEnabled
            && is_numeric($denomination)
            && bccomp($denomination, '0', $currencyScale) > 0
            && bccomp($total, '0', $currencyScale) > 0;

        if ($floorApplies && bccomp($denomination, $ceiling, $currencyScale) > 0) {
            $ceiling = $denomination;
        }

        return bcadd($ceiling, '0', $currencyScale);
    }

    /**
     * True when the sealed payload carries supervisor approval for THIS gap.
     *
     * Scope-checked deliberately: a `discount_limit_override` approves a
     * discount, not a till shortage. Treating "any approval" as evidence would
     * let one supervisor tap launder an arbitrary tender gap past the alert.
     */
    private function hasTenderToleranceApproval(SaleReceiptCanonicalView $view): bool
    {
        foreach ($view->payload->approvalReferences as $reference) {
            if (($reference['approval_scope'] ?? null) === 'tender_tolerance_override') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the RETAINED amount per canonical tender leg (spec §4.6).
     *
     * **Two-semantics rule.** `payments.amount` (Treasury) is the RETAINED
     * amount — what the business actually kept. `pos_receipt_payments.amount`
     * (the POS read model, written by `PosCoreReceiptProjection::writePayments()`
     * — named, deliberately not imported) is the TENDERED amount — what the
     * customer handed over. The canonical
     * payload carries TENDERED; this pre-pass is what converts it. The two
     * columns are DELIBERATELY different numbers; never reconcile them
     * directly.
     *
     * Change (`Σ legs − total`, clamped at zero so a tolerance SHORTFALL never
     * inflates a leg) is subtracted from the LAST cash leg first, cascading
     * backwards in canonical index order. That makes the result a deterministic
     * pure function of the sealed payload + the `payment_methods.is_cash_tender`
     * flag (mutable operator state — never flip it while a terminal has
     * unsettled projections; see the deploy checklist) — which is what keeps a
     * replay landing on the same numbers. It has to: `TreasuryMovementService`
     * throws `IdempotencyConflictException` when an existing movement key is
     * re-recorded at a different amount, so a non-deterministic netting would
     * not be a wrong number, it would be a permanently-failing queue job.
     *
     * Cash-ness comes from `payment_methods.is_cash_tender` (Task 1) and
     * nothing else — never the method code, never the display name. A voucher
     * leg IS a payment leg but is NOT a cash tender: no drawer ever hands
     * change back out of a voucher.
     *
     * **Invariant: maturity legs are non-cash by construction, so they are
     * never netted.** That is precisely what keeps `handleMaturityRefundLeg()`'s
     * raw-amount instrument match correct, and what keeps a minted cheque
     * carrying the face value of the paper the customer actually handed over.
     *
     * `change > Σ cash legs` means a foreign or malformed event violated the
     * device invariant. Net what the cash legs allow, leave the remainder, and
     * raise a durable `pos.change.exceeds_cash_legs` alert.
     *
     * v1/v2 events return the tendered amounts unchanged, before any
     * validation or arithmetic runs — see the class-level replay contract.
     *
     * @return array<int, string> index-aligned with $view->payments
     */
    private function computeNettedAmounts(
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        Receipt $receipt,
    ): array {
        // Below the cutover: hand back exactly what the payload carries, with
        // ZERO new work. Any validation added above this line would change the
        // failure mode of a historical event.
        if (! CashRoundingCutover::applies($event->event_version)) {
            return array_map(
                static fn (PaymentDTO $line): string => $line->amount,
                $view->payments,
            );
        }

        $scale = $view->payload->currencyScale;

        /** @var list<numeric-string> $amounts */
        $amounts = [];
        /** @var numeric-string $sum */
        $sum = bcadd('0', '0', $scale);
        foreach ($view->payments as $line) {
            if (! is_numeric($line->amount)) {
                throw new RuntimeException(sprintf(
                    'TreasuryReceiptBridge: payment amount %s is not numeric for fiscal_event %s',
                    $line->amount,
                    $event->id,
                ));
            }
            $amounts[] = $line->amount;
            $sum = bcadd($sum, $line->amount, $scale);
        }

        $total = $view->payload->total;
        if (! is_numeric($total)) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payload total %s is not numeric for fiscal_event %s',
                $total,
                $event->id,
            ));
        }

        // `max(0, Σ legs − total)`. A NEGATIVE difference is an under-tender
        // (tolerance shortfall, booked by the tolerance bridge) — it must never
        // be added back onto a leg, which is what an unclamped bcsub would do.
        $change = bcsub($sum, $total, $scale);
        if (bccomp($change, '0', $scale) <= 0) {
            return $amounts;
        }

        $remaining = $change;

        // Resolve cash-ness ONCE per method code for the whole event — the
        // resolver + model load are the expensive part and a split payment
        // repeats codes.
        /** @var array<string, bool> $cashByCode */
        $cashByCode = [];
        foreach ($view->payments as $line) {
            $code = $line->methodCode;
            if (array_key_exists($code, $cashByCode)) {
                continue;
            }
            $cashByCode[$code] = $this->isCashTender($event, $code);
        }

        for ($i = count($amounts) - 1; $i >= 0; $i--) {
            if (bccomp($remaining, '0', $scale) <= 0) {
                break;
            }
            if (($cashByCode[$view->payments[$i]->methodCode] ?? false) !== true) {
                continue;
            }

            $legAmount = $amounts[$i];
            $deduction = bccomp($legAmount, $remaining, $scale) <= 0 ? $legAmount : $remaining;
            $amounts[$i] = bcsub($legAmount, $deduction, $scale);
            $remaining = bcsub($remaining, $deduction, $scale);
        }

        if (bccomp($remaining, '0', $scale) > 0) {
            $this->recordChangeExceedsCashLegsAlert($event, $receipt, $change, $remaining);
        }

        return $amounts;
    }

    /**
     * Canonical cash-ness for a tender leg: `payment_methods.is_cash_tender`,
     * resolved through the same tenant+company-scoped seam the per-leg
     * projection uses. Fail-CLOSED — an unresolvable or unloadable method is
     * treated as NON-cash, so a leg is never netted on a guess. (The per-leg
     * projection below then throws on the same method and rolls the whole
     * apply() back, alert included.)
     */
    private function isCashTender(FiscalEvent $event, string $methodCode): bool
    {
        $methodId = $this->paymentMethodResolver->resolveByCode(
            $event->tenant_id,
            $event->company_id,
            $methodCode,
        );

        if ($methodId === null) {
            return false;
        }

        try {
            $method = PaymentMethod::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->find($methodId);
        } catch (QueryException) {
            return false;
        }

        return $method !== null && $method->is_cash_tender === true;
    }

    /**
     * Durable alert for a foreign/malformed event whose change exceeds the sum
     * of its cash legs. Same idempotency-guarded `audit_events` pattern as
     * {@see recordMaturityRefundAlert()}.
     */
    private function recordChangeExceedsCashLegsAlert(
        FiscalEvent $event,
        Receipt $receipt,
        string $change,
        string $unnetted,
    ): void {
        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.change.exceeds_cash_legs')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $receipt->cashier_id,
                eventType: 'pos.change.exceeds_cash_legs',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'change' => $change,
                    'unnetted_remainder' => $unnetted,
                ],
            );
        }

        Log::warning('POS receipt change exceeds the sum of its cash tender legs; netted what cash allowed.', [
            'fiscal_event_id' => $event->id,
            'change' => $change,
            'unnetted_remainder' => $unnetted,
        ]);
    }

    /**
     * Project a single `payment_lines[]` entry into one Treasury `Payment`
     * row + one immediately-posted POS payment GL entry. The whole body
     * runs inside the wrapping `apply()` transaction so a GL-post failure
     * rolls back the Payment row + every prior line written in this
     * apply() call (no partial bridge state).
     *
     * **`repository_id` cross-tenant gate.** The fail-closed lookup matches
     * Task 21 round-2's Opus F3 posture: `payments.repository_id` is a
     * tenant-scoped FK, but the FK itself only checks PK existence; without
     * the application-side `(tenant_id, company_id)` predicate a foreign
     * `repository_id` smuggled into the payload would bind another tenant's
     * repository onto this tenant's payment row. Throw `RuntimeException`
     * inside the transaction → the wrapping `DB::transaction` rolls back
     * the in-progress bridge writes atomically.
     *
     * **`payment_method_id` cross-tenant gate (Task 22 round-2 — Opus F1 BLOCKER).**
     * Identical posture, identical defense. The `payments.payment_method_id`
     * FK is to `payment_methods.id` (`2025_11_30_120000_create_treasury_tables.php:143`)
     * and the FK only enforces PK existence. Without the application-side
     * tenant-scoped lookup a foreign tenant's `payment_method_id` smuggled
     * into the payload would silently bind onto this tenant's Payment row.
     * Mirror of the `repository_id` gate below.
     *
     * Pass 2A.PHP.2 canonical-payload variant of the bridge's per-payment
     * write. Resolves `payment_method_id` via the Shared/Contracts seam;
     * `repository_id` via a tenant+company-scoped payment-method mapping,
     * with the deterministic GL-linked fallback retained for unmapped or
     * unusable mappings. The canonical payload deliberately carries no
     * mutable operational repository selection.
     */
    private function projectPaymentLineFromCanonical(
        FiscalEvent $event,
        Receipt $receipt,
        PaymentDTO $line,
        int $index,
        int $totalLines,
        bool $isRefund,
        ?string $originalEventId,
        ?string $terminalLocationId,
        string $nettedAmount,
        int $currencyScale,
        PosRevenueVatSplit $vatSplit,
    ): void {
        // RETAINED amount (spec §4.6 two-semantics rule) — this is what the
        // Treasury Payment row, the GL entry and the repository movement all
        // consume. The canonical TENDERED value stays on `$line->amount` and
        // is used ONLY by the maturity instrument match below. On a v1/v2
        // event `computeNettedAmounts()` hands back `$line->amount` verbatim,
        // so this assignment is a no-op there.
        $amount = $nettedAmount;
        $methodCode = $line->methodCode;

        // The canonical PaymentDTO carries `amount` as a plain string
        // (bcformat at currency_scale). Fail loud on a non-numeric value —
        // it can never reach the money-movement port (which requires
        // numeric-string); the `is_numeric` guard also narrows the type.
        if (! is_numeric($amount)) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payment amount %s is not numeric for fiscal_event %s',
                $amount,
                $event->id,
            ));
        }

        // Resolve payment_method_id via the Shared/Contracts seam (the
        // same surface PosCoreReceiptProjection uses). Tenant+company-scoped
        // lookup; null return triggers fail-closed RuntimeException
        // (same security stance as the prior payment_method_id gate).
        $paymentMethodId = $this->paymentMethodResolver->resolveByCode(
            $event->tenant_id,
            $event->company_id,
            $methodCode,
        );

        if ($paymentMethodId === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payment_method_not_found:method_code=%s:tenant_id=%s:company_id=%s',
                $methodCode,
                $event->tenant_id,
                $event->company_id,
            ));
        }

        try {
            $paymentMethod = PaymentMethod::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->find($paymentMethodId);
        } catch (QueryException) {
            $paymentMethod = null;
        }
        if ($paymentMethod === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payment_method_id %s resolved but not loadable for tenant %s / company %s',
                $paymentMethodId,
                $event->tenant_id,
                $event->company_id,
            ));
        }

        // A fully-netted cash leg (the customer's change consumed it) writes
        // NOTHING: no Payment, no GL entry, no movement. `repository_movements`
        // carries CHECK (amount > 0) (`2026_07_08_100100:55`) so a zero
        // movement is not even representable, and the payments idempotency
        // index is PARTIAL (`2026_07_08_150000:44-46`) so the ordinal HOLE this
        // leaves in the per-leg keys is legal. The survivors deliberately keep
        // their ORIGINAL canonical ordinals — re-indexing them would make the
        // keys a function of the netting result rather than of the sealed
        // payload, and a replay could then collide with a prior partial write.
        //
        // Gated on the cutover: v1/v2 behaviour is untouched (a v1/v2 leg
        // reaches here with its tendered amount and is never zero unless the
        // payload itself said so).
        //
        // Placed AFTER the payment-method resolution above, not before it, so
        // a suppressed leg still passes through the cross-tenant fail-closed
        // gate — a foreign `method_code` must throw whether or not netting
        // happened to zero that leg out.
        if (CashRoundingCutover::applies($event->event_version)
            && bccomp($amount, '0', $currencyScale) === 0) {
            return;
        }

        $isMaturityLeg = $this->maturityLegHandler->handles($paymentMethod);
        if ($isRefund && $isMaturityLeg) {
            if ($originalEventId === null) {
                throw new RuntimeException('TreasuryReceiptBridge: maturity refund has no original fiscal event reference.');
            }
            if ($this->handleMaturityRefundLeg(
                $event,
                $line,
                $index,
                $paymentMethod,
                $receipt,
                $originalEventId,
            )) {
                return;
            }

            // Missing, ambiguous, or active non-Received paper takes the safe
            // standard cash reversal path after emitting its durable alert.
            $isMaturityLeg = false;
        }

        // Task 20 — PER-LEG idempotency key. Resolve the existing Payment
        // BEFORE current routing configuration: mappings are mutable operator
        // policy, while a projected payment's repository is immutable history.
        $legKey = sprintf('fiscal_event:%s:payment:%d', $event->id, $index);
        $existing = Payment::query()
            ->where('company_id', $event->company_id)
            ->where('idempotency_key', $legKey)
            ->first();

        if ($existing instanceof Payment) {
            $repository = PaymentRepository::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->find($existing->repository_id);
        } else {
            $repository = $this->resolveRepositoryForTender($event, $paymentMethod);
        }

        if ($repository === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: no GL-linked payment_repository found for tenant %s / company %s — '.
                'cannot create POS-payment GL post for fiscal_event %s',
                $event->tenant_id,
                $event->company_id,
                $event->id,
            ));
        }

        if ($repository->gl_account_id === null) {
            throw new RuntimeException(sprintf(
                "TreasuryReceiptBridge: repository '%s' (%s) has no gl_account_id — ".
                'every POS-payment repository must be linked to a GL account ',
                (string) $repository->name,
                (string) $repository->code,
            ));
        }

        // The canonical index is the
        // POSITION of this payment in the sealed, immutable
        // `payload.payments[]` array (both this bridge and
        // PosCoreReceiptProjection iterate the SAME canonical `$view->payments`
        // in the same order — §15 Q2). It is STABLE across replays because the
        // fiscal event payload is immutable, so no new column is needed. The
        // key is stamped on `payments.idempotency_key` (partial UNIQUE on
        // (company_id, idempotency_key)) so a replay of an already-written leg
        // is a clean hit, while a partial prior write (only SOME legs) is
        // COMPLETED — the missing legs fall through to the create branch.
        $cashAccountOverrideId = null;
        $instrument = null;
        $shouldRecordMovement = true;

        if ($existing instanceof Payment) {
            // Leg already written on a prior apply(). Do NOT create a second
            // Payment or post a second GL entry — reuse the existing GL link.
            // The movement port record() below is idempotent on the same leg
            // key, so it is either a clean hit (movement already there) or it
            // completes a partial write (Payment+GL landed but the movement
            // never did) by linking to the EXISTING journal entry.
            $payment = $existing;
            $journalEntryId = $existing->journal_entry_id;

            if ($isMaturityLeg) {
                if ($journalEntryId === null) {
                    throw new RuntimeException(sprintf(
                        'TreasuryReceiptBridge: maturity payment %s has no journal entry.',
                        $existing->id,
                    ));
                }

                $debitAccountId = DB::table('journal_lines')
                    ->where('journal_entry_id', $journalEntryId)
                    ->where('line_order', 0)
                    ->value('account_id');

                if ($debitAccountId === $repository->gl_account_id) {
                    // Pre-cutover replay: the original JE recognized cash.
                    // Complete its movement if needed, but never mint paper.
                    $isMaturityLeg = false;
                } elseif ($debitAccountId === $this->maturityLegHandler->portfolioAccountId($paymentMethod, $event->company_id)) {
                    // Post-cutover replay: ensure its instrument exists and
                    // categorically keep this leg away from the movement port.
                    $result = $this->maturityLegHandler->handleMaturityLeg(
                        $event,
                        $line,
                        $index,
                        $paymentMethod,
                        new MaturityLegContext(
                            currency: $receipt->currency,
                            repositoryId: $repository->id,
                            partnerId: $receipt->partner_id,
                            receivedDate: $receipt->posted_at->toDateString(),
                            createdBy: $receipt->cashier_id,
                            locationId: $terminalLocationId,
                        ),
                    );
                    if ($existing->instrument_id !== null && $existing->instrument_id !== $result->instrument->id) {
                        throw new RuntimeException('TreasuryReceiptBridge: maturity payment links a conflicting instrument.');
                    }
                    if ($existing->instrument_id === null) {
                        $existing->instrument_id = $result->instrument->id;
                        $existing->save();
                    }
                    if ($result->instrument->payment_id !== null && $result->instrument->payment_id !== $existing->id) {
                        throw new RuntimeException('TreasuryReceiptBridge: maturity instrument links a conflicting payment.');
                    }
                    if ($result->instrument->payment_id === null) {
                        $result->instrument->payment_id = $existing->id;
                        $result->instrument->save();
                    }
                    if (DB::table('repository_movements')
                        ->where('idempotency_key', $legKey)
                        ->exists()) {
                        throw new RuntimeException('TreasuryReceiptBridge: portfolio maturity leg already has a forbidden cash movement.');
                    }
                    $shouldRecordMovement = false;
                } else {
                    throw new RuntimeException(sprintf(
                        'TreasuryReceiptBridge: maturity payment %s has an unrecognized debit account.',
                        $existing->id,
                    ));
                }
            }
        } else {
            if ($isMaturityLeg) {
                $result = $this->maturityLegHandler->handleMaturityLeg(
                    $event,
                    $line,
                    $index,
                    $paymentMethod,
                    new MaturityLegContext(
                        currency: $receipt->currency,
                        repositoryId: $repository->id,
                        partnerId: $receipt->partner_id,
                        receivedDate: $receipt->posted_at->toDateString(),
                        createdBy: $receipt->cashier_id,
                        locationId: $terminalLocationId,
                    ),
                );
                $instrument = $result->instrument;
                $cashAccountOverrideId = $result->portfolioAccountId;
                $shouldRecordMovement = false;
            }

            // Create the Treasury `Payment` row stamped with origin=pos +
            // fiscal_event_id (spec §13 writer row 1). Both `payment_method_id`
            // and `repository_id` were resolved through tenant-scoped lookups
            // above — using the verified `$paymentMethod->id` / `$repository->id`
            // is belt-and-braces.
            $payment = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'partner_id' => $receipt->partner_id,
                'payment_method_id' => $paymentMethod->id,
                'instrument_id' => $instrument?->id,
                'repository_id' => $repository->id,
                // Device-authored receipt attribution is terminal-origin only;
                // unlike the server-authored deposit bridge, do not fall back to
                // repository custody when the terminal lookup is unavailable.
                'location_id' => $terminalLocationId,
                'amount' => $amount,
                'currency' => $receipt->currency,
                'payment_date' => $receipt->posted_at,
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::POS,
                // §13 row 1 — both columns stamped on the same write.
                'origin' => PaymentOrigin::Pos,
                'fiscal_event_id' => $event->id,
                'idempotency_key' => $legKey,
                'reference' => "POS Receipt {$receipt->receipt_number} - Payment ".($index + 1),
                'notes' => 'POS payment ('.($index + 1).' of '.$totalLines.') [fiscal_event_bridge]',
            ]);

            // Create the GL entry + post it SYNCHRONOUSLY in-transaction
            // (PostingMode::SynchronousInTransaction via postEntryNow) so the
            // GL post + the repository movement below commit or roll back as
            // one unit (spine BLOCKER-1: postEntryNow takes the company
            // advisory lock BEFORE the movement port takes the repo row lock).
            // POS payments are direct to revenue (no draft / no AR account).
            //
            // Task 21 — for a refund/void the sale entry is REVERSED (Dr Revenue
            // / Cr Cash) instead of posted (Dr Cash / Cr Revenue). Both helpers
            // return a Draft entry that postEntryNow commits below.
            $journalEntry = $isRefund
                ? $this->generalLedgerService->createPOSRefundReversalEntry(
                    payment: $payment,
                    receipt: $receipt,
                    repository: $repository,
                    vatSplit: $vatSplit,
                )
                : $this->generalLedgerService->createPOSPaymentEntry(
                    payment: $payment,
                    receipt: $receipt,
                    repository: $repository,
                    vatSplit: $vatSplit,
                    cashAccountOverrideId: $cashAccountOverrideId,
                );

            // Pass the receipt currency explicitly: this projector runs on a
            // Horizon worker where no CompanyContext is bound, and the GL
            // service's no-arg scale resolution fails loud there (F-RES-1).
            $this->generalLedgerService->postEntryNow($journalEntry, $receipt->cashier, $receipt->currency);

            $payment->journal_entry_id = $journalEntry->id;
            $payment->save();

            if ($instrument !== null) {
                $instrument->payment_id = $payment->id;
                $instrument->save();
            }

            $journalEntryId = $journalEntry->id;
        }

        // Task 20 — move the repository balance through the single write port,
        // ONE movement per tender leg keyed on the canonical index. Device
        // origin: a SALE_RECEIPT is authored on the offline POS device, so the
        // leg carries `allowWhileFrozen = true` — an offline device must never
        // poison the fiscal-projection queue if the repo was frozen server-side
        // after the device authored the sale. This is decided from the EVENT
        // TYPE (device vs server-only), never inferred from the movement
        // sourceType. record() is idempotent on the leg key.
        //
        // Task 21 — a refund/void tender leg pays cash OUT of the drawer, so
        // the movement direction is Out (the GL reversal above already credited
        // cash). A plain SALE keeps direction In. sourceType stays FiscalEvent +
        // sourceId=event->id + idempotencyLeg=payment:{index} so the movement
        // idempotency key is the SAME canonical per-leg key a sale leg for this
        // event would use (stable across replays; the invoice_type_code is
        // immutable on the sealed payload). The direction Out + GL reversal is
        // the ONLY difference from the normal-sale path.
        if (! $shouldRecordMovement) {
            return;
        }

        $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $event->tenant_id,
            companyId: $event->company_id,
            direction: $isRefund ? MovementDirection::Out : MovementDirection::In,
            amount: $amount,
            // Task 20 review Fix 2 (MINOR) — pass the TENDER/RECEIPT currency
            // (the currency `$amount` is denominated in), NOT the repository
            // currency. The port's CurrencyMismatchException guard compares
            // `$repo->currency !== $intent->currency`; passing the repo currency
            // here makes that comparison trivially always-equal and silently
            // disarms the F12 safety check. Today receipt currency == repo
            // currency so behavior is unchanged, but the guard is restored.
            currency: $receipt->currency,
            sourceType: MovementSourceType::FiscalEvent,
            sourceId: $event->id,
            idempotencyLeg: sprintf('payment:%d', $index),
            journalEntryId: $journalEntryId,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $payment->created_by ?? $receipt->cashier_id,
            notes: null,
            allowWhileFrozen: ! $event->event_type->isServerOnly(),
            allowBehindCheckpoint: ! $event->event_type->isServerOnly(),
            // W-5b Option B intent-flag sweep: this bridge runs inside
            // ApplyFiscalEventProjectionJob (ShouldQueue) — a queue worker,
            // not an interactive request. Mirrors allowWhileFrozen exactly
            // (same event-type-derived expression, same site): a device-
            // authored refund/void tender leg (direction Out on $isRefund)
            // must record + alert rather than throw and poison the
            // projection queue into failed_jobs.
            allowNegative: ! $event->event_type->isServerOnly(),
        ));
    }

    /**
     * Resolve a tender's operational repository without adding mutable routing
     * data to the sealed fiscal payload. A mapped repository wins only when it
     * belongs to the event tenant+company, is active, and remains GL-linked.
     * Otherwise preserve the historical deterministic fallback: the first
     * tenant+company GL-linked repository ordered by stable UUID.
     *
     * DPA lane G3 requirement 4: the rule itself now lives in
     * {@see TenderRepositoryResolver} so the shift-close cash-variance listener
     * resolves the SAME repository this projection does — by sharing the code,
     * not by duplicating it. This method stays as the bridge's named seam (and
     * the anti-drift test's second entry point); its behaviour is unchanged.
     */
    private function resolveRepositoryForTender(
        FiscalEvent $event,
        ?PaymentMethod $method,
    ): ?PaymentRepository {
        return $this->tenderRepositoryResolver->resolve(
            (string) $event->tenant_id,
            (string) $event->company_id,
            $method,
        );
    }

    /**
     * Return true when the maturity refund is fully handled without cash.
     * False means the caller must use the standard refund JE + movement path.
     */
    private function handleMaturityRefundLeg(
        FiscalEvent $event,
        PaymentDTO $line,
        int $index,
        PaymentMethod $method,
        Receipt $receipt,
        string $originalEventId,
    ): bool {
        $matches = PaymentInstrument::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('idempotency_key', 'like', sprintf('fiscal_event:%s:instrument:%%', $originalEventId))
            ->where('kind', $method->instrument_kind?->value)
            // The TENDERED `$line->amount`, deliberately — NOT the netted/retained
            // amount. Spec §4.6 invariant: a maturity tender is non-cash by
            // construction (`is_cash_tender` is false for cheque/effet methods),
            // so `computeNettedAmounts()` never touches these legs and the paper
            // was minted at its face value. If netting ever reached a maturity
            // leg, this match would silently stop finding the instrument and
            // every maturity refund would fall through to the cash path.
            //
            // Raw equality on amount relies on the same-currency-scale invariant between
            // the canonical fiscal payload amounts and the stored decimal column (both are
            // scaled to the repository/company currency, single-currency today). A scale
            // mismatch simply yields NO match, which fails SAFE: the caller falls through to
            // an alert + the standard cash-reversal refund path — it never guess-cancels a
            // near-but-unequal instrument.
            ->where('amount', $line->amount)
            ->orderBy('id')
            ->get();
        $received = $matches->where('status', InstrumentStatus::Received)->values();

        if ($received->count() === 1) {
            /** @var PaymentInstrument $instrument */
            $instrument = $received->first();
            $this->instrumentLifecycle->cancel(
                $instrument->id,
                $receipt->cashier_id,
                sprintf('POS refund/void fiscal event %s', $event->id),
                CancellationShape::PosRevenue,
            );

            return true;
        }

        if ($matches->count() === 1 && $matches->first()?->status === InstrumentStatus::Cancelled) {
            return true;
        }

        $this->recordMaturityRefundAlert(
            event: $event,
            index: $index,
            originalEventId: $originalEventId,
            matchCount: $matches->count(),
            receivedCount: $received->count(),
            userId: $receipt->cashier_id,
        );

        return false;
    }

    private function recordMaturityRefundAlert(
        FiscalEvent $event,
        int $index,
        string $originalEventId,
        int $matchCount,
        int $receivedCount,
        ?string $userId,
    ): void {
        $aggregateId = sprintf('%s:payment:%d', $event->id, $index);
        $exists = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos_refund_on_active_instrument')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $aggregateId)
            ->exists();

        if (! $exists) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $userId,
                eventType: 'pos_refund_on_active_instrument',
                aggregateType: 'fiscal_event',
                aggregateId: $aggregateId,
                payload: [
                    'refund_event_id' => $event->id,
                    'leg_index' => $index,
                    'original_event_id' => $originalEventId,
                    'match_count' => $matchCount,
                    'received_count' => $receivedCount,
                ],
            );
        }

        Log::warning('POS maturity refund could not safely cancel its original instrument.', [
            'refund_event_id' => $event->id,
            'leg_index' => $index,
            'original_event_id' => $originalEventId,
            'match_count' => $matchCount,
            'received_count' => $receivedCount,
        ]);
    }
}
