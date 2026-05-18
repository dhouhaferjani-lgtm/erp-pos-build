<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
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
 * **Race-safety contract.** `apply()` is single-flight in production
 * under the Task 23 (`ApplyFiscalEventProjectionJob`) lifecycle, which
 * acquires `lockForUpdate()` on the `fiscal_event_projections` row keyed
 * on `(fiscal_event_id, 'treasury_receipt_bridge')` BEFORE invoking the
 * projector. Manual operator replay (e.g.,
 * `fiscal:enqueue-resolved-event-projections`) MUST run after the active
 * job completes — DO NOT bypass the projection row. A concurrent
 * apply() bypassing the lock would race the existence probe and write
 * duplicate Payment rows.
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
 * Treasury `Payment` + GL entry inline when invoked from the legacy
 * `/pos/receipts/sync` HTTP path. Per spec v7 §14.2 / Task 21
 * adjudication that legacy write is "knowingly retained no-new-writers"
 * with retirement routed to Task 28 (`/pos/receipts/sync` retirement)
 * and Task 30 (the two-chokepoint CI grep gate). DO NOT physically
 * remove the legacy `Payment::create` call — the documented disposition
 * keeps it functional through the rollout window. The legacy call gets
 * stamped `origin = PaymentOrigin::Pos` + `fiscal_event_id = null`
 * (no fiscal event was authored device-side for this server-recompute
 * code path).
 */
final class TreasuryReceiptBridge implements FiscalEventProjector
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
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
        // The POS-core projector runs first (its `fiscal_event_projections`
        // row is enqueued ahead of ours by `OutboxIngestor` — Task 19's
        // tagged-set iteration order), but we do not assume strict
        // ordering: if the receipt row is not yet visible we treat the
        // event as not-yet-projectable and bail. Task 23 will retry the
        // job per its lifecycle.
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
            Log::warning('TreasuryReceiptBridge: pos_receipt not yet projected for fiscal event', [
                'fiscal_event_id' => $event->id,
            ]);

            return;
        }

        DB::transaction(function () use ($event, $payload, $receipt): void {
            // Re-check inside the transaction. Belt-and-braces — the
            // production race fence is Task 23's row lock, but if a
            // manual replay path collides with an in-flight job both
            // would clear the outer probe; this inner check prevents
            // duplicate writes if both paths reach the transaction
            // body. Using a fresh query (not the outer probe's cached
            // result) ensures we see commits by concurrent writers.
            if ($this->paymentsForEventExist($event)) {
                return;
            }

            $paymentLines = FiscalPayloadArrayGuards::requireArray($payload, 'payment_lines');

            $totalLines = count($paymentLines);
            $index = 0;
            foreach ($paymentLines as $line) {
                if (! is_array($line)) {
                    throw new RuntimeException(
                        'TreasuryReceiptBridge: payment_lines[] entry is not an array',
                    );
                }
                /** @var array<string, mixed> $line */
                $this->projectPaymentLine($event, $receipt, $line, $index, $totalLines);
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
     * @param  array<string, mixed>  $line
     */
    private function projectPaymentLine(
        FiscalEvent $event,
        Receipt $receipt,
        array $line,
        int $index,
        int $totalLines,
    ): void {
        $paymentMethodId = FiscalPayloadArrayGuards::requireString($line, 'payment_method_id');
        $amount = FiscalPayloadArrayGuards::requireString($line, 'amount');
        $repositoryId = FiscalPayloadArrayGuards::optionalString($line, 'repository_id');

        // payment_lines[] without an explicit repository_id cannot be
        // bridged into a GL post (the POS payment GL entry requires a
        // repository to source the bank/cash account). The legacy
        // ReceiptPaymentService rejected this shape too — preserve the
        // same fail-loud contract. Outside the spec's happy-path: every
        // device-authored payment_line carries a repository_id (mirrored
        // reference data from the POS local SQLite).
        if ($repositoryId === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: payment_lines[%d] missing repository_id — '.
                'cannot create GL post without a source repository for fiscal_event %s',
                $index,
                $event->id,
            ));
        }

        // Inbound tenant-scoped lookup of the repository. Matches Task 21
        // round-2 Opus F3 fail-closed posture: cross-tenant `repository_id`
        // throws inside the transaction → roll back.
        try {
            $repository = PaymentRepository::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->find($repositoryId);
        } catch (QueryException) {
            $repository = null;
        }

        if ($repository === null) {
            throw new RuntimeException(sprintf(
                'TreasuryReceiptBridge: repository_id %s not visible to tenant %s / company %s',
                $repositoryId,
                $event->tenant_id,
                $event->company_id,
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
        // fiscal_event_id (spec §13 writer row 1). The wrapping
        // transaction inherits the receipt + repository tenant-scope —
        // a regression that wrote a foreign-tenant payment_method_id
        // would still be caught by the §13 writer-inventory tests +
        // the FK on `fiscal_event_id` → `fiscal_events.id`.
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'partner_id' => $receipt->partner_id,
            'payment_method_id' => $paymentMethodId,
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
        // navigation. Single UPDATE — not subject to immutability
        // triggers (none on payments table).
        $payment->journal_entry_id = $journalEntry->id;
        $payment->save();
    }

    /**
     * Idempotency probe: any Treasury Payment row already linked to this
     * fiscal event with origin=pos. The (fiscal_event_id, origin=pos)
     * tuple is what the bridge writes; a row with that shape proves a
     * prior apply() completed for this event. Legacy rows pre-dating
     * Task 12 have `origin = NULL` and are correctly skipped by the
     * `origin` predicate — those came in via the legacy
     * `ReceiptPaymentService` path before Phase 1 §13 stamping.
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
}
