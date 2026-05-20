<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
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
 * `ReceiptController::storePayments()` (`POST /pos/receipts/{id}/payments`,
 * the web-POS + Tauri-online new-sale path — `/pos/receipts/sync` goes
 * through `ReceiptSyncService`, not this service). Per spec v7
 * §14.1 (`/pos/receipts/sync` retirement, Task 28) + §14.2 (new-sale
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
        // compares against the `Vertical::defaultModules()` identifier set;
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
        // Fast-path idempotency probe. The race-safety fence in production
        // is the Task 23 projection-row `lockForUpdate()`; this check is
        // a cheap operator-replay short-circuit so we don't open a
        // transaction when an existing pos-origin Payment is already
        // visible for the event. Under concurrent dispatch bypassing the
        // Task 23 lock, the probe is not a substitute for the lock and
        // is documented as such in the class docblock.
        if ($this->paymentsForEventExist($event)) {
            return;
        }

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

            // Re-check inside the transaction. With the advisory lock
            // above, the second waiter sees the first's committed rows
            // here and returns cleanly. Without the lock (SQLite tests,
            // future driver migrations), this is the only defense
            // against double-write — keep it.
            if ($this->paymentsForEventExist($event)) {
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

            $totalLines = count($view->payments);
            $index = 0;
            foreach ($view->payments as $payment) {
                $this->projectPaymentLineFromCanonical($event, $receipt, $payment, $index, $totalLines);
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
    ): void {
        $amount = $line->amount;
        $methodCode = $line->methodCode;

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

        // Create the Treasury `Payment` row stamped with origin=pos +
        // fiscal_event_id (spec §13 writer row 1). Both `payment_method_id`
        // and `repository_id` were resolved through tenant-scoped lookups
        // above — using the verified `$paymentMethod->id` / `$repository->id`
        // is belt-and-braces (the payload value is already validated; this
        // makes a code regression that drops the gate fail-loud in code
        // review rather than silently in production).
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'partner_id' => $receipt->partner_id,
            'payment_method_id' => $paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => $amount,
            'currency' => $receipt->currency,
            'payment_date' => $receipt->posted_at,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            // §13 row 1 — both columns stamped on the same write.
            'origin' => PaymentOrigin::Pos,
            'fiscal_event_id' => $event->id,
            'reference' => "POS Receipt {$receipt->receipt_number} - Payment ".($index + 1),
            'notes' => 'POS payment ('.($index + 1).' of '.$totalLines.') [fiscal_event_bridge]',
        ]);

        // Create the GL entry + post it immediately. POS payments are
        // direct to revenue (no draft / no AR account). The GL service
        // wraps its own DB::transaction → nested savepoint under our
        // outer transaction. A throw here rolls the entire bridge call
        // back atomically.
        $journalEntry = $this->generalLedgerService->createPOSPaymentEntry(
            payment: $payment,
            receipt: $receipt,
            repository: $repository,
        );

        // The GL service expects a cashier to attribute the post to;
        // mirror the legacy ReceiptPaymentService behaviour — use the
        // receipt's cashier directly. The Receipt model declares cashier
        // as a non-null `User` (FK is `restrictOnDelete` on the migration),
        // so a missing user would have surfaced before reaching this
        // point (the POS-core projector that wrote the receipt row would
        // have thrown on the FK).
        $this->generalLedgerService->postEntry($journalEntry, $receipt->cashier);

        // Link the journal entry back onto the Payment for downstream
        // navigation. Single UPDATE — no immutability triggers exist on
        // `payments` as of the migration set through 2026_05_17_*. If a
        // future migration adds an immutability trigger to
        // `payments.journal_entry_id` (or to `origin` / `fiscal_event_id`
        // per a §13 hardening), this single UPDATE must move into the
        // bridge's idempotency window or use `forceSaveQuietly()` — see
        // Task 11's `pos_receipts` immutability pattern.
        $payment->journal_entry_id = $journalEntry->id;
        $payment->save();
    }

    /**
     * Idempotency probe: any Treasury Payment row already linked to this
     * fiscal event with origin=pos. The (fiscal_event_id, origin=pos)
     * tuple is what the bridge writes; a row with that shape proves a
     * prior apply() completed for this event.
     *
     * Task 22 round-2 (Opus F5 P2): legacy rows pre-dating Task 12 are
     * backfilled to `origin = unknown_legacy` by
     * `2026_05_17_120000_backfill_legacy_payment_origin.php`; they are
     * correctly skipped by the `origin = pos` predicate here. (Pre-
     * round-2 those rows kept `origin = NULL` and were also skipped by
     * this predicate — the round-2 backfill closes the spec §13
     * divergence without changing the probe's behavior.)
     */
    private function paymentsForEventExist(FiscalEvent $event): bool
    {
        try {
            return Payment::query()
                ->where('fiscal_event_id', $event->id)
                ->where('origin', PaymentOrigin::Pos)
                ->exists();
        } catch (QueryException $e) {
            // Defensive: a malformed UUID smuggled into $event->id would
            // throw on PG (uuid column at the driver layer). Treat as
            // "no rows match" — the wrapping caller's downstream writes
            // would surface their own FK / type errors clearly.
            Log::warning('TreasuryReceiptBridge: idempotency probe failed', [
                'fiscal_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pass 2A.PHP.2 — resolve the default `payment_repositories` row for
     * the event's tenant+company. The canonical SALE_RECEIPT payload does
     * NOT carry a per-payment `repository_id` (synthesis v5 §3 — repository
     * selection is a Treasury-operational concern, not part of the audit
     * seal). The bridge picks the FIRST tenant+company-scoped repository
     * with a non-null `gl_account_id`. Deterministic when exactly one
     * repository exists per (tenant, company) — the common single-cash-
     * drawer case. Phase 1.5 may introduce per-method default-repository
     * mapping.
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
