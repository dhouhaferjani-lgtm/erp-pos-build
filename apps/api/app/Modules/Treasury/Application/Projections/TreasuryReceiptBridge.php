<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
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
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly PaymentMethodResolver $paymentMethodResolver,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly HandlesMaturityTenderLeg $maturityLegHandler,
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

        DB::transaction(function () use ($event, $receipt): void {
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
            // default-repository lookup (see resolveDefaultRepository).
            $view = $this->canonicalReader->forSaleReceipt($event);

            // Task 21 — a refund/void rides the SALE_RECEIPT event carrying
            // `invoice_type_code='REFUND'` (or 'VOID') + a non-null
            // `original_receipt_reference` (there is NO separate REFUND event
            // type — REFUND_RECEIPT/SALE_VOID are RESERVED_UNREACHABLE). Every
            // money field in the payload is NON-NEGATIVE (§6), so the refund
            // signal is the invoice_type_code, NOT a negative amount. For a
            // refund each tender leg pays cash OUT of the drawer and posts a GL
            // reversal of the sale entry; a plain SALE keeps the Task-20 IN +
            // sale-GL behavior. Decided ONCE from the immutable payload so the
            // whole receipt's legs are consistent.
            $invoiceTypeCode = $view->payload->invoiceTypeCode;
            $isRefund = $invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID';

            $totalLines = count($view->payments);
            $index = 0;
            foreach ($view->payments as $payment) {
                $this->projectPaymentLineFromCanonical($event, $receipt, $payment, $index, $totalLines, $isRefund);
                $index++;
            }
        });
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
     * `repository_id` via a tenant+company-scoped default-repository
     * lookup (the 27-key contract doesn't carry per-payment repository
     * selection — see resolveDefaultRepository).
     */
    private function projectPaymentLineFromCanonical(
        FiscalEvent $event,
        Receipt $receipt,
        PaymentDTO $line,
        int $index,
        int $totalLines,
        bool $isRefund,
    ): void {
        $amount = $line->amount;
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
        // same surface PosCoreReceiptProjection uses). Tenant-scoped
        // lookup; null return triggers fail-closed RuntimeException
        // (same security stance as the prior payment_method_id gate).
        $paymentMethodId = $this->paymentMethodResolver->resolveByCode(
            $event->tenant_id,
            $methodCode,
        );

        if ($paymentMethodId === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payment_method_not_found:method_code=%s:tenant_id=%s',
                $methodCode,
                $event->tenant_id,
            ));
        }

        try {
            $paymentMethod = PaymentMethod::query()->find($paymentMethodId);
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

        $isMaturityLeg = $this->maturityLegHandler->handles($paymentMethod);

        // Repository resolution — the 27-key canonical payload does not
        // carry per-payment `repository_id` (synthesis v5 §3 deliberately
        // excludes it: the device-side fiscal seal contract is about
        // audit data, not Treasury operational routing). The bridge picks
        // the first tenant+company-scoped repository with a non-null
        // `gl_account_id`. Phase 1.5 may introduce a payment_method →
        // default_repository mapping; until then, the first matching
        // repository is the deterministic per-tenant default.
        $repository = $this->resolveDefaultRepository($event);
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

        // Task 20 — PER-LEG idempotency key. The canonical index is the
        // POSITION of this payment in the sealed, immutable
        // `payload.payments[]` array (both this bridge and
        // PosCoreReceiptProjection iterate the SAME canonical `$view->payments`
        // in the same order — §15 Q2). It is STABLE across replays because the
        // fiscal event payload is immutable, so no new column is needed. The
        // key is stamped on `payments.idempotency_key` (partial UNIQUE on
        // (company_id, idempotency_key)) so a replay of an already-written leg
        // is a clean hit, while a partial prior write (only SOME legs) is
        // COMPLETED — the missing legs fall through to the create branch.
        $legKey = sprintf('fiscal_event:%s:payment:%d', $event->id, $index);

        $existing = Payment::query()
            ->where('company_id', $event->company_id)
            ->where('idempotency_key', $legKey)
            ->first();

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
                        $receipt,
                        $repository,
                    );
                    if ($existing->instrument_id !== null && $existing->instrument_id !== $result->instrument->id) {
                        throw new RuntimeException('TreasuryReceiptBridge: maturity payment links a conflicting instrument.');
                    }
                    if ($existing->instrument_id === null) {
                        $existing->instrument_id = $result->instrument->id;
                        $existing->save();
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
                    $receipt,
                    $repository,
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
                )
                : $this->generalLedgerService->createPOSPaymentEntry(
                    payment: $payment,
                    receipt: $receipt,
                    repository: $repository,
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
        ));
    }

    /**
     * Pass 2A.PHP.2 — resolve the default `payment_repositories` row for
     * the event's tenant+company. The canonical SALE_RECEIPT payload does
     * NOT carry a per-payment `repository_id` (synthesis v5 §3 — repository
     * selection is a Treasury-operational concern, not part of the audit
     * seal). The bridge picks the FIRST tenant+company-scoped repository
     * with a non-null `gl_account_id` ordered by `id` (Codex P2-6 closure
     * — `orderBy('id')` is the deterministic tiebreaker for the multi-
     * repository case under concurrent transactions).
     *
     * **Phase 1.5 deferral (Codex P2-6).** When tenants run more than one
     * repository per (tenant, company), the `id`-ordered tiebreaker is
     * deterministic but not necessarily semantically correct — the bridge
     * may bind every receipt's payments to the same repository regardless
     * of `method_code`. The Phase 1.5 roadmap introduces a per-method
     * default-repository mapping (e.g. `payment_methods.default_repository_id`)
     * so that CASH lines route to the cash drawer and CARD lines route to
     * the merchant account. Until then, single-repository deployments are
     * unaffected and multi-repository deployments must configure the
     * intended default via SQL fixture seeding.
     */
    private function resolveDefaultRepository(FiscalEvent $event): ?PaymentRepository
    {
        try {
            return PaymentRepository::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->whereNotNull('gl_account_id')
                ->orderBy('id')
                ->first();
        } catch (QueryException) {
            return null;
        }
    }
}
