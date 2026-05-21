# Task 22 — Opus Adversarial Review

**Subject:** `18df84988` on `feat/pos-fiscal-event-engine-phase1` (worktree `apps/erp.fiscal-phase1`, PR #124).
**Scope:** `TreasuryReceiptBridge` (Treasury-operational `FiscalEventProjector` for `SALE_RECEIPT`) + the complete spec v7 §13 `payments.origin` writer inventory across `ReceiptPaymentService`, `PaymentController::{store,storeMultiple}`, `MultiPaymentService::{createSplitPayment,recordDeposit,recordPaymentOnAccount}`, `PaymentRefundService::{refundPayment,partialRefund,refundReceiptPayments}`, `VendorRefundService::refundPrepayment` + `TreasuryServiceProvider::register()` tag wiring + new tests `TreasuryReceiptBridgeTest` (12), `PaymentOriginWriterInventoryTest` (11) + regression extension to `FiscalEventProjectionRegistryTest`.

## Verdict: REQUEST-CHANGES

The §7.4 split contract is structurally honored — the bridge writes only Treasury `Payment` rows + GL entries; it does NOT write `ReceiptPayment` rows (those live in `PosCoreReceiptProjection`); zero imports of POS write surfaces beyond the `Receipt` read model + the inbound mirrored `PaymentRepository`. The full spec v7 §13 writer inventory is exhaustively stamped — all 10 rows accounted for, no missing `Payment::create` writers in Treasury or POS modules (verified by grep). The `requiresModule()` PascalCase token is correct, the tag wiring shape matches Task 18's tagged-set contract, and standing patterns (`FiscalPayloadArrayGuards` everywhere, no `(type) $array['key']` casts on payload, `QueryException` wraps around UUID lookups, constructor injection, `private readonly` deps, no `app()` helper) are all observed. The implementer-flagged C2 (POS-core-first ordering) deferred-bail-out is a real concern: the docblock claim that POS-core runs first is **factually wrong** (Treasury is registered BEFORE POS in `bootstrap/providers.php`), so the deferred-bail-out is load-bearing in production on first attempt, not a safety net — see F4 below.

However, one genuine BLOCKER and one P1 land:

1. **F1 (BLOCKER) — Cross-tenant `payment_method_id` is NOT scope-gated by the bridge.** The bridge enforces tenant-scoped lookup on `repository_id` (lines 250-266, the Task 21 round-2 F3 standing pattern) but writes `payment_method_id` raw from the payload (line 288). The `payments.payment_method_id` FK only checks PK existence; tenant scope is application-only. A foreign tenant's `payment_method_id` smuggled into `payment_lines[]` would bind onto this tenant's Payment row, exactly the same security gap Task 21 round-2 closed for `PosCoreReceiptProjection`. The docblock at lines 280-282 makes a load-bearing-but-false claim that "the §13 writer-inventory tests + the FK on `fiscal_event_id` → `fiscal_events.id`" would catch this — they do not (those tests check `origin` stamping and a different FK entirely). Pattern-matches Task 21 round-2 F3 (stale-docblock-on-cross-tenant-FK) + the Task 21 fail-closed posture.

2. **F2 (P1) — `PaymentRefundService::refundReceiptPayments` web_admin-inherit branch is untested for the proration path.** §13 row 9 specifies "inherit the original payment's `origin`" — the test `test_receipt_proration_refund_inherits_pos_origin` covers POS-inherit, but the symmetric web_admin-inherit case for proration is missing. NOTE: in current production code, proration only runs over `payment_type=POS` rows (the proration query at `PaymentRefundService.php:349` filters `where('payment_type', PaymentType::POS->value)`), so a web_admin-origin row will not be reached in normal operation. But (a) the §13 spec disposition is "inherit" without qualifier and (b) a future broadening of proration to non-POS payments would silently default the unmodified copy-of-origin code to whatever — this is the test-locks-future-broadening case. Convert to P2 if the spec disposition is read as "POS-only by construction"; treat as P1 if the spec line is read as a universal inheritance contract.

There is also one P1 docblock-drift (the C2 ordering claim), one P2 (the docblock-vs-behavior drift on `payment_method_id` security), one P2 docblock disclosure for the missing-payments backfill, and three P3 cleanups (stale legacy line numbers, the "Task 28" / "Task 30" disposition naming, a comment claim about index hot-path).

C1 (manual replay race) and C3 (defensive null check removal on `Receipt::cashier`) are acceptable deferrals — see adjudication.

## Findings summary

| # | Sev | File:Line | One-liner |
|---|---|---|---|
| F1 | BLOCKER | app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:283-300, 280-282 | Bridge writes `payment_method_id` raw from payload — no tenant-scoped `PaymentMethod` lookup; cross-tenant FK bind is silent. Same security gap Task 21 round-2 F3 closed for POS-core, NOT applied here. |
| F2 | P1 | tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:371-390 | `refundReceiptPayments` web_admin-inherit case for §13 row 9 is untested — only POS-inherit covered. |
| F3 | P1 | app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:142-149 | Bridge docblock claims "POS-core projector runs first" via tagged-set iteration order. FALSE: `bootstrap/providers.php` registers Treasury at L65, POS at L76, so Treasury's tag fires FIRST. Deferred-bail-out is load-bearing in production on first attempt, not a safety net. |
| F4 | P2 | app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:277-282 | Docblock claim "a regression that wrote a foreign-tenant payment_method_id would still be caught by the §13 writer-inventory tests + the FK on `fiscal_event_id` → `fiscal_events.id`" is technically incorrect: the §13 tests don't check cross-tenant FKs and the `fiscal_event_id` FK is a different column entirely. Stale-comment hazard (Task 20 standing pattern). |
| F5 | P2 | apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php; spec §13 | Spec §13 says "Any pre-existing rows (preflight gate permitting) → `unknown_legacy`." The Task 12 migration sets nullable but NO backfill. Bridge docblock at line 334-336 documents "legacy rows pre-dating Task 12 have `origin = NULL`" but the spec literally says they should be `unknown_legacy`. Either backfill the legacy rows or document the deviation in spec §13 with a §16 migration-plan note. |
| F6 | P3 | app/Modules/POS/Application/Services/ReceiptPaymentService.php:45 | Task 21 review F8 already flagged the `:297` stale line number (now `:321`). Task 22 didn't sweep — the docblock still says `:306` per Task 21 round-2; actual `ReceiptPayment::create` is now at `:321` in the worktree. |
| F7 | P3 | app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:87 | The `§14.2` retirement-routing docblock says "Task 28" / "Task 30" but the §14.1 plan numbering is "Task 28 = sync retirement" + "Task 30 = §14.3 CI grep gate". The actual section number reference (Task 29 also exists for §14.2 web-POS work). Confirm the task numbers match the plan; if not align them. Minor. |
| F8 | P3 | app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:322-326 | The journal-entry-back-link `payment->save()` is a single UPDATE inside the outer transaction — fine — but the comment "not subject to immutability triggers (none on payments table)" reads as a future-tense forward reference. A future migration adding an immutability trigger to `payments` (the §3.3 pattern is precedented for `fiscal_events` / `pos_receipts`) would break this assumption silently. Either delete the parenthetical or convert it to an assertion ("if you add an immutability trigger to `payments.journal_entry_id`, see…"). |

---

### F1 — BLOCKER — Cross-tenant `payment_method_id` is NOT scope-gated by the bridge

**File.** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:283-300`.

**Observation.** The bridge enforces a tenant-scoped lookup on `repository_id`:

```php
$repository = PaymentRepository::query()
    ->where('tenant_id', $event->tenant_id)
    ->where('company_id', $event->company_id)
    ->find($repositoryId);
if ($repository === null) {
    throw new RuntimeException(/* "not visible to tenant" */);
}
```

Then writes the Payment row with `'repository_id' => $repository->id` (the verified value). This is the Task 21 round-2 F3 fail-closed posture — correct.

But the same Payment::create writes `'payment_method_id' => $paymentMethodId` (line 288) — the **raw** payload value, with NO tenant-scoped lookup. `payments.payment_method_id` is a FK to `payment_methods.id` (verified in `2025_11_30_120000_create_treasury_tables.php:143`), and the FK only enforces PK existence — not tenant scope. So a payload carrying a foreign tenant's `payment_method_id` would silently bind onto this tenant's Payment row, with `tenant_id = $event->tenant_id` but `payment_method_id` pointing at another tenant's payment method. This is the **exact** security gap Task 21 round-2 closed for `PosCoreReceiptProjection`'s `writePayments()` — but the bridge took only half the fix (repository_id gated, payment_method_id not).

The docblock at lines 280-282 makes a false-positive claim:

> "a regression that wrote a foreign-tenant payment_method_id would still be caught by the §13 writer-inventory tests + the FK on `fiscal_event_id` → `fiscal_events.id`."

Neither check actually catches cross-tenant `payment_method_id`:
- The §13 writer-inventory tests assert `origin` is stamped — they don't construct cross-tenant scenarios for `payment_method_id`.
- The `fiscal_event_id` FK is to `fiscal_events.id` — a completely different FK, on a different column. It's enforced at the row level, but doesn't constrain `payment_method_id`.

**Why it's a BLOCKER, not a P1.** This is the SAME pattern Codex caught and Opus escalated to BLOCKER on Task 21 round-2 (the F3 fail-closed cross-tenant FK posture). The bridge is the **second projector landing this exact pattern** — copy-paste of the half-fix would propagate the security gap. Task 22 is the place to apply the lesson; deferring to a future task means a foreign-tenant `payment_method_id` is silently bindable on Treasury Payment rows in the rollout window. The bridge already does the work for `repository_id`; the same query shape applied to `PaymentMethod` is ~6 lines.

**Remediation.** Mirror the `repository_id` gate for `payment_method_id`:

```php
$paymentMethod = PaymentMethod::query()
    ->where('tenant_id', $event->tenant_id)
    ->where('company_id', $event->company_id)
    ->find($paymentMethodId);
if ($paymentMethod === null) {
    throw new RuntimeException(sprintf(
        'TreasuryReceiptBridge: payment_method_id %s not visible to tenant %s / company %s',
        $paymentMethodId, $event->tenant_id, $event->company_id,
    ));
}
```

Then write `'payment_method_id' => $paymentMethod->id`. Add a test mirroring `test_cross_tenant_repository_id_is_rejected_fail_closed`: create a `PaymentMethod` scoped to a different tenant, point `payment_lines[0].payment_method_id` at it, assert `RuntimeException` + atomic rollback (zero `payments` rows, zero `journal_entries` rows). Update the docblock at lines 280-282 to describe the actual mechanism (application-side tenant-scoped lookup).

**Reference.** SoT v3 §13.6 / D16 (the bounded-modules seam — Treasury is permitted to reference inbound mirrored reference data, but the reference must be scoped to the event's tenant). Task 21 round-2 F3 standing pattern (handoff §4.3 Task 21 fail-closed cross-tenant FK posture change).

---

### F2 — P1 — `refundReceiptPayments` web_admin-inherit branch is untested

**File.** `apps/api/tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php:371-390`.

**Observation.** Spec §13 row 9 ("`PaymentRefundService` receipt-proration refund rows → inherit the original payment's `origin`") is verified by one test: `test_receipt_proration_refund_inherits_pos_origin`. The symmetric web_admin-inherit case is missing — there's no `test_receipt_proration_refund_inherits_web_admin_origin`.

In current production code the proration query (`PaymentRefundService.php:349`) filters `where('payment_type', PaymentType::POS->value)` — so only POS-typed payments are proration targets in steady state. A web_admin-typed payment is `PaymentType::DocumentPayment` and isn't reached. Argument for it being P2: untestable code path under current production constraints.

But §13 row 9's contract is "inherit" unqualified. The line `'origin' => $original->origin` at `PaymentRefundService.php:468` is the **only** mechanism. The test asserting `PaymentOrigin::Pos` is necessary but not sufficient — it doesn't pin the universal inheritance contract. A future broadening of proration to non-POS payments would silently default to whatever the original carries, including web_admin (correct by spec) or NULL (the legacy ambiguity case). The test as written would pass that future code regardless of correctness.

**Why it's a P1, not a P2.** Convergent with the standing-pattern carried forward from Task 20 ("discriminated-union test matrix coverage") — when a writer's behavior is conditioned on input variants, write the test matrix exhaustively. §13 row 9 is one writer with two inherit variants in scope (POS, web_admin); test both. The cost is one helper-built fixture + one test.

**Remediation.** Add `test_receipt_proration_refund_inherits_web_admin_origin`:

```php
public function test_receipt_proration_refund_inherits_web_admin_origin(): void
{
    // Mirror seedReceiptWithLinkedPayment but with web_admin-stamped + DocumentPayment type
    // on the linked payment. The proration query as-is would not pick it up
    // (filters POS), so either:
    //   (a) Broaden the helper to allow payment_type override, demonstrate that
    //       proration skips web_admin (asserting filter behavior, not the inherit),
    //   (b) Build a fixture where the original IS POS-typed but carries
    //       PaymentOrigin::WebAdmin (an unusual but valid combination per the
    //       enum + spec — there is no constraint linking origin to payment_type),
    //       and assert the refund inherits PaymentOrigin::WebAdmin.
    // Option (b) is the one that pins §13 row 9 directly.
}
```

Alternatively: explicitly disposition the asymmetry in spec §13 ("receipt-proration refunds only ever inherit `pos` because the proration query is `payment_type=POS`-only") and lock that filter behavior as a test instead.

**Reference.** Spec v7 §13 (writer inventory, row 9); handoff §4.3 Task 20 standing pattern (discriminated-union test matrix).

---

### F3 — P1 — Bridge docblock falsely claims POS-core runs first; deferred-bail-out is load-bearing, not a safety net

**File.** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:142-149`.

**Observation.** The docblock at lines 142-149 reads:

> "The Treasury bridge writes rows scoped to the receipt row that PosCoreReceiptProjection (Task 21) wrote for the same event. **The POS-core projector runs first (its `fiscal_event_projections` row is enqueued ahead of ours by `OutboxIngestor` — Task 19's tagged-set iteration order)**, but we do not assume strict ordering: if the receipt row is not yet visible we treat the event as not-yet-projectable and bail."

This claim is **factually wrong** about the iteration order:
- `bootstrap/providers.php:65` — `TreasuryServiceProvider`
- `bootstrap/providers.php:76` — `POSServiceProvider`

`$app->tag()` is called inside each provider's `register()`. Laravel's container stores tagged bindings in registration order — Treasury's tag fires FIRST, so the tagged set materializes as `[TreasuryReceiptBridge, PosCoreReceiptProjection]`. The registry's `activeProjectorsFor()` iterates in this order, and `OutboxIngestor::storeFiscalEventAndProjectionRows` inserts pending rows in this order. So Treasury's projection row is enqueued FIRST, NOT POS-core's. Jobs are dispatched after-commit; the worker pool picks them up; whichever the worker picks first runs first.

The deferred bail-out at lines 165-171 is therefore **load-bearing on first attempt in production**, not a safety net. When Task 23 ships and starts dispatching real jobs, the first bridge attempt will commonly find no receipt → bail → next bridge retry (after POS-core has run) succeeds. This works because of the retry contract, but the docblock's reasoning about ordering is wrong, and the test `test_no_pos_receipt_for_event_is_a_deferred_bail_out_not_a_crash` documents the bail-out as defensive when it's actually the steady-state first-attempt path.

**Why it's a P1, not a P2.** Two reasons:
1. **Misleading docblock invites a future "optimization" regression.** A future reader reasoning from the docblock might "fix" the bridge to assume the receipt is always present (deleting the bail-out branch as dead code). The implementation would then crash in production.
2. **The actual deferred-bail behavior depends on retries — Task 23 is a forward dependency.** Until Task 23 ships, `apply()` is invoked synchronously in tests but is wired to no-op in production (per `OutboxIngestor::storeFiscalEventAndProjectionRows`'s `TODO(Task 23)` at lines 783-789). The docblock claim that POS-core "runs first" can't be tested today; that gap is fine, but the docblock should match the actual provider order.

**Remediation.** Choose one:

**Option A — Fix the docblock.** Rewrite lines 142-149 to describe actual behavior:

> "The Treasury bridge writes rows scoped to the receipt row that PosCoreReceiptProjection (Task 21) wrote for the same event. Tagged-set iteration order is **Treasury-first** per `bootstrap/providers.php` (TreasuryServiceProvider at L65, POSServiceProvider at L76); the bridge's first projection attempt commonly precedes the POS-core projection's commit. The deferred-bail-out below is therefore **load-bearing on first attempt** — the bridge logs + returns cleanly; Task 23's projection-row retry walks through subsequent attempts until the receipt row is visible. DO NOT delete the bail-out: it is the bridge's steady-state ordering posture, not a defense-in-depth check."

**Option B — Re-order providers.** Move `POSServiceProvider` above `TreasuryServiceProvider` in `bootstrap/providers.php` so the docblock matches reality, then add a constructor-asserted invariant in the registry or a CI test that fails if the order is ever reversed. (More invasive; touches a global config; only justified if a future Task 26 end-to-end test asserts iteration order.)

Option A is the lower-friction choice; it costs nothing at the projector boundary and accurately documents the actual race window the deferred-bail-out absorbs.

**Reference.** `bootstrap/providers.php:65,76` (provider registration order); Laravel container tagged-set ordering (registration-order — verified at `vendor/laravel/framework/src/Illuminate/Container/Container.php` `tag()` + `tagged()` methods).

---

### F4 — P2 — Docblock-vs-behavior drift on `payment_method_id` security claim

**File.** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:277-282`.

**Observation.** Companion to F1. The docblock at lines 280-282 reads:

> "The wrapping transaction inherits the receipt + repository tenant-scope — a regression that wrote a foreign-tenant payment_method_id would still be caught by the §13 writer-inventory tests + the FK on `fiscal_event_id` → `fiscal_events.id`."

Both claims are factually wrong:
- The §13 writer-inventory tests assert `origin` stamping, not cross-tenant payment_method_id rejection. None of the 11 tests in `PaymentOriginWriterInventoryTest` construct a cross-tenant `payment_method_id` payload.
- The `fiscal_event_id` FK is to `fiscal_events.id` — a different column entirely. It doesn't constrain `payment_method_id`.

This is the Task 20 stale-comment hazard standing pattern: docblock claims a defense that doesn't exist. Even if F1 is closed (cross-tenant gate added), this docblock would still be wrong because it cites the wrong mechanism. Rewrite it to reflect either the new gate (post-F1 fix) or the actual current behavior ("the payment_method_id FK accepts any valid PK; cross-tenant scope is the bridge's responsibility, not the FK's").

**Remediation.** After implementing F1 fix, rewrite the docblock to describe the actual `payment_method_id` mechanism (tenant-scoped application-side lookup, mirror of the `repository_id` gate above).

**Reference.** Task 20 stale-comment hazard standing pattern (handoff §4.3); Task 21 round-2 F5 (the same stale-comment-on-cross-tenant-FK pattern).

---

### F5 — P2 — Spec §13 says legacy rows → `unknown_legacy`; migration leaves them NULL; bridge docblock documents NULL

**Files.** `apps/api/database/migrations/2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php`; bridge docblock at `:332-336`; spec §13.

**Observation.** Spec v7 §13 says:

> "Any pre-existing rows (preflight gate permitting) → `unknown_legacy`."

The Task 12 migration at `2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php` adds the column as nullable, but contains NO backfill statement. So legacy production rows have `origin = NULL`, NOT `unknown_legacy`.

The bridge's idempotency probe docblock at lines 332-336 acknowledges this:

> "Legacy rows pre-dating Task 12 have `origin = NULL` and are correctly skipped by the `origin` predicate — those came in via the legacy `ReceiptPaymentService` path before Phase 1 §13 stamping."

This works for the idempotency probe (the bridge only writes `origin = pos`, so NULL legacy rows aren't candidate duplicates), but it diverges from the spec text. The spec's "preflight gate permitting" caveat may mean "the preflight check confirms zero legacy rows exist so no backfill is needed" — but the gate's actual scope isn't documented in this commit. The migration's docblock at lines 17-22 says "Nullable so pre-migration rows (legacy) remain valid; the Task 22 `TreasuryReceiptBridge` projector tags new rows explicitly" — which contradicts the spec's `unknown_legacy` text.

**Why it's a P2, not a P1.** No data corruption — the bridge's behavior is internally consistent. But the spec-vs-implementation divergence is a real documentation drift: a reader holding the spec and running a `SELECT origin, COUNT(*) FROM payments GROUP BY origin` on production would expect `unknown_legacy` rows, find NULL rows, and lose confidence in the §13 invariant.

**Remediation.** Choose one:
1. **Add a backfill.** Extend the migration with an `UPDATE payments SET origin = 'unknown_legacy' WHERE origin IS NULL` statement (or a separate backfill migration, dispositioned per the preflight gate).
2. **Document the deviation in spec §13.** Add a sentence: "Phase 1 preflight gate (§2) confirms zero pre-existing rows; the migration therefore omits the `unknown_legacy` backfill — legacy rows remain NULL and the bridge's idempotency probe handles this case explicitly."

Either is a one-line change. Pick the one that matches the actual preflight-gate scope.

**Reference.** Spec v7 §13 (writer inventory text); migration `2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:17-22`; bridge docblock `:332-336`.

---

### F6 — P3 — Stale line citation in `ReceiptPaymentService` docblock

**File.** `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:45`.

**Observation.** Task 21 review F8 already flagged that the `:297` line citation drifted to `:306` after the inserted docblock. Task 21 round-2 updated it to `:306`. The Task 22 commit added another docblock block at lines 267-274 (the §13 row 1 disposition), pushing the actual `ReceiptPayment::create` to line 321 in the current worktree. The Task 21-introduced docblock at line 45 still says `:306` — stale at the moment of the Task 22 commit.

**Remediation.** Either drop the line number or update to the current `:321`.

**Reference.** Task 21 review F8.

---

### F7 — P3 — Task-number references in §14 retention docblock need confirmation

**File.** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:82-93`.

**Observation.** The bridge's §14 retention disposition docblock says:

> "Per spec v7 §14.2 / Task 21 adjudication that legacy write is "knowingly retained no-new-writers" with retirement routed to Task 28 (`/pos/receipts/sync` retirement) and Task 30 (the two-chokepoint CI grep gate)."

Plan numbering: Task 28 = `syncService.ts` fiscal-event push + `/pos/receipts/sync` retirement (§14.1) — verified. Task 29 = §14.2 web-POS disposition. Task 30 = §14.3 two-chokepoint CI grep gate. The docblock skips Task 29; given §14.2 covers `void`/`processReturn` retention (NOT the legacy `ReceiptPaymentService` Treasury writes specifically — that's §14.1 + the bridge), the omission may be intentional. But the §14.2 reference at line 85 (`Per spec v7 §14.2`) plus the Task-28-named retirement disposition is at minimum confusing.

**Remediation.** Either cite §14.1 (the `/pos/receipts/sync` retirement section, which IS where the legacy `ReceiptPaymentService::processReceiptPayments` retirement lives) or document why §14.2 is the relevant section. Minor.

**Reference.** Plan task numbering (Task 28 / Task 29 / Task 30); spec §14.1 vs §14.2.

---

### F8 — P3 — Implicit assumption about absence of `payments` immutability triggers

**File.** `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:322-326`.

**Observation.** The journal-entry back-link single-UPDATE has a comment:

> "Link the journal entry back onto the Payment for downstream navigation. Single UPDATE — not subject to immutability triggers (none on payments table)."

The parenthetical is true today but is a forward-fragile invariant. The `fiscal_events` and `pos_receipts` tables both have immutability triggers (§3.3 + Task 11). A future migration adding a similar trigger to `payments` (e.g., to enforce "origin / fiscal_event_id once stamped, never mutated" — a natural §13 hardening) would silently break this single-UPDATE.

**Remediation.** Either delete the parenthetical or rewrite it as a tripwire: "If you add an immutability trigger to `payments.journal_entry_id` (or to `origin` / `fiscal_event_id` per a §13 hardening), this single UPDATE must move into the bridge's idempotency window or use `forceSaveQuietly()` — see Task 11's pos_receipts immutability pattern."

---

## C1–C3 adjudications

### C1 — Manual replay race acknowledged in docblock; deferred to Task 23

**Verdict: ACCEPTABLE.** The bridge documents the race scope explicitly (lines 63-71): the production fence is Task 23's `lockForUpdate()` on `fiscal_event_projections`; manual replay paths (`fiscal:enqueue-resolved-event-projections`, not yet shipped) must run after the active job completes. The inner re-check inside `DB::transaction` (lines 181-183) is belt-and-braces. PG advisory locks are NOT mandated by the spec for this case (`payments.fiscal_event_id` is intentionally NOT UNIQUE per Task 12 — split-payment row count is N). The check-then-insert pattern + Task 23 row-lock is the documented contract; an advisory lock would be premature optimization absent a Task 23 design that doesn't already cover the race.

**One caveat.** When Task 23 lands, verify it actually does the `lockForUpdate` claim — the bridge's docblock is making a forward-promise. If Task 23 turns out to use a non-PG-native locking primitive (e.g., a job-id-keyed Horizon distributed lock instead of a row lock), the docblock's "lockForUpdate on the projection row" reference will need updating.

### C2 — Bridge reads `pos_receipts` → couples to POS-core projector running first

**Verdict: ESCALATED to F3 (P1).** The deferred-bail behavior is correct and tested. But the docblock claim about POS-core running first is factually wrong (Treasury is registered FIRST in `bootstrap/providers.php`). The deferred-bail-out is therefore the steady-state first-attempt code path, not a safety net. See F3 for the remediation. The defense itself is fine; the documentation isn't.

### C3 — Removed defensive null check on `Receipt::cashier`

**Verdict: ACCEPTABLE.** Verified via the migration `2026_01_08_190637_create_pos_receipts_table.php:53-55` — `cashier_id` is `foreignUuid(...)->constrained('users')->restrictOnDelete()` with no `->nullable()` modifier. Combined with the FK's `restrictOnDelete`, `Receipt::cashier` cannot return null in steady state. The PHPStan-driven removal is safe. A future migration that made `cashier_id` nullable would break this assumption silently — but that would be a deliberate API change that the migration author would be on the hook for catching. The bridge's `$receipt->cashier` access (line 320) is acceptable as-is.

---

## Grep verification log

| Claim | Verified | Notes |
|---|---|---|
| §13 writer inventory exhaustive (10 rows, every `Payment::create` in Treasury + POS modules stamped) | Yes | Grep `Payment::create\b\|Payment::factory()` across `apps/api/app/Modules/Treasury/` + `apps/api/app/Modules/POS/` returns exactly 11 `Payment::create` calls — the bridge itself + 10 corresponding to §13 rows 1-10. No misses. |
| `PaymentOrigin` enum has 5 cases | Yes | `apps/api/app/Modules/Treasury/Domain/Enums/PaymentOrigin.php` — `Pos`, `WebAdmin`, `Mobile`, `Api`, `UnknownLegacy` matches spec §13. |
| `pos:verify-chains` does NOT read `payments` table | Yes | Grep against `VerifyPosChainCommand` finds 3 hits — all reading `fiscal_event_id IS NULL` on `pos_receipts` (Task 21 round-2 carve-out). |
| `ReceiptHashService::verifyPaymentMethodsHash` reads `$receipt->payments()` which → `ReceiptPayment`, not Treasury `Payment` | Yes | `Receipt::payments()` at `:335-337` returns `hasMany(ReceiptPayment::class, 'receipt_id')` — POS-core relation, not Treasury. |
| `payments.payment_method_id` FK exists | Yes | `2025_11_30_120000_create_treasury_tables.php:143` — `foreignUuid('payment_method_id')->constrained('payment_methods')->onDelete('restrict')`. PK existence only; tenant scope must be app-enforced. |
| `payments.fiscal_event_id` FK exists (PG only) | Yes | `2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:49-53` — `ALTER TABLE payments ADD CONSTRAINT payments_fiscal_event_id_fk FOREIGN KEY (fiscal_event_id) REFERENCES fiscal_events(id)`. PG only. |
| `payments.fiscal_event_id` is NOT UNIQUE | Yes | Same migration — only a partial index, no UNIQUE constraint. Bridge idempotency probe is the documented pattern. |
| `bootstrap/providers.php` order: TreasuryServiceProvider before POSServiceProvider | Yes | Treasury at L65, POS at L76. Container tagged-set order is Treasury-first. See F3. |
| `requiresModule()` interface declares `?string` return | Yes | `apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php:72`. Bridge returns `'Treasury'`. |
| `GeneralLedgerService::createPOSPaymentEntry` + `postEntry` signatures match call sites | Yes | `createPOSPaymentEntry(Payment, Receipt, PaymentRepository)` + `postEntry(JournalEntry, User)`. Bridge calls match. |
| `Receipt::cashier_id` is NOT NULL in migration | Yes | `2026_01_08_190637_create_pos_receipts_table.php:53-55` — `foreignUuid(...)->constrained('users')->restrictOnDelete()` with no `nullable()`. C3 safe. |
| CI PG-merge-gate filter shape correct (pipe-separated, no leading/trailing pipe) | Yes | `.github/workflows/ci.yml:392` — `TreasuryReceiptBridgeTest\|PaymentOriginWriterInventoryTest` appended cleanly. |
| New cross-task wiring test asserts BOTH names | Yes | `FiscalEventProjectionRegistryTest::test_tagged_set_contains_both_pos_core_and_treasury_bridge_in_production` asserts both `pos_core_receipt` AND `treasury_receipt_bridge`. |
| Phpunit green | Yes | 23 tests / 63 assertions pass locally (TreasuryReceiptBridgeTest + PaymentOriginWriterInventoryTest, sqlite). |
| Phpstan level 8 green | Yes | 0 errors on the 7 modified files. |
| `Receipt::cashier` returns User (`belongsTo`) | Yes | `Receipt.php:274-277` — `belongsTo(User::class, 'cashier_id')`. |
| Bridge does NOT create `ReceiptPayment` rows | Yes | Grep `ReceiptPayment::` in `TreasuryReceiptBridge.php` returns zero. Bridge owns ONLY Treasury Payment + GL. |
| Bridge reads `pos_receipts` only via `Receipt::query()` (read lookup, not create) | Yes | Single read at lines 151-155 — `where(tenant_id, company_id, fiscal_event_id)->first()`. No `Receipt::create` / `Receipt::update`. |
| `App\Modules\Billing\Domain\Payment` is out of §13 scope | Yes | `AdminBillingController:254` uses `Billing\Domain\Payment` — different namespace, different table, explicitly excluded by spec §13. |

---

## §13 writer inventory cross-check

| Row | Writer (per spec §13) | Code location | `origin` stamped | Test | Status |
|---|---|---|---|---|---|
| 1 | `ReceiptPaymentService` (POS receipt payment lines, legacy `/pos/receipts/sync` path) | `apps/api/app/Modules/POS/Application/Services/ReceiptPaymentService.php:275` | `PaymentOrigin::Pos` | Covered by `TreasuryReceiptBridgeTest::test_payment_status_and_type_match_legacy_receipt_payment_service` + legacy retention disposition acknowledged | ✅ |
| 1 (bridge) | `TreasuryReceiptBridge::projectPaymentLine()` (NEW — device-authored fiscal-event path) | `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:283` | `PaymentOrigin::Pos` + `fiscal_event_id = $event->id` | `TreasuryReceiptBridgeTest::test_bridge_creates_treasury_payment_and_gl_exactly_once` + 11 others | ✅ |
| 2 | `PaymentController::store()` | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:213` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_payment_controller_store_stamps_web_admin` | ✅ |
| 3 | `PaymentController::storeMultiple()` | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:588` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_payment_controller_store_multiple_stamps_web_admin` | ✅ |
| 4 | `MultiPaymentService::createSplitPayment()` | `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:76` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_multi_payment_create_split_stamps_web_admin` | ✅ |
| 5 | `MultiPaymentService::recordDeposit()` | `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:151` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_multi_payment_record_deposit_stamps_web_admin` | ✅ |
| 6 | `MultiPaymentService::recordPaymentOnAccount()` | `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:292` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_multi_payment_record_payment_on_account_stamps_web_admin` | ✅ |
| 7 | `PaymentRefundService::refundPayment()` | `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:88` | inherit `$payment->origin` | `test_refund_inherits_pos_origin_when_original_is_pos` + `test_refund_inherits_web_admin_origin_when_original_is_web_admin` | ✅ |
| 8 | `PaymentRefundService::partialRefund()` | `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:178` | inherit `$payment->origin` | `test_partial_refund_inherits_pos_origin_when_original_is_pos` + `test_partial_refund_inherits_web_admin_origin_when_original_is_web_admin` | ✅ |
| 9 | `PaymentRefundService` receipt-proration refund rows (`refundReceiptPayments`) | `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:468` | inherit `$original->origin` | `test_receipt_proration_refund_inherits_pos_origin` — **POS-inherit only**; web_admin-inherit symmetric test missing (F2). Proration query is POS-only in current code (`:349`) so untestable in steady state, but spec text is unconditional. | ⚠ |
| 10 | `VendorRefundService::refundPrepayment()` | `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:116` | `PaymentOrigin::WebAdmin` | `PaymentOriginWriterInventoryTest::test_vendor_refund_prepayment_stamps_web_admin` | ✅ |

**Cross-module grep for unexpected `Payment::create` writers:** zero hits beyond the 11 enumerated above. `Payment::factory()` calls are test-only. `Payment::query()->create / firstOrCreate / updateOrCreate / insert` returns zero. `new Payment(…)` returns zero. The §13 inventory is exhaustive at this commit.

---

## Standing-pattern conformance

| # | Pattern | Status |
|---|---|---|
| 1 | `(type) $array['key']` casts on payload values | CLEAN — zero instances. All payload reads via `FiscalPayloadArrayGuards::requireString` / `requireArray` / `optionalString`. The only `(string)` casts are on Eloquent attributes (`$repository->name`, `$repository->code`), not on payload arrays. |
| 2 | Resolver / downstream-service exceptions fail-closed | MIXED — `QueryException` on `Receipt::query()->first()` and `PaymentRepository::find($repositoryId)` are wrapped + return null/fail-closed. But cross-tenant `payment_method_id` lookup is missing entirely (F1). |
| 3 | `Eloquent::find($uuid)` PG malformed-UUID wrap | CLEAN — `PaymentRepository::find($repositoryId)` wrapped in `try { } catch (QueryException)`. Receipt lookup similarly wrapped. Idempotency probe's `where('fiscal_event_id', $event->id)` wrapped. |
| 4 | `chain_sequence` not `hash_sequence` wording | N/A — bridge doesn't write chain_sequence. |
| 5 | Constructor injection with `private readonly`; no `app()` helper | CLEAN — only `GeneralLedgerService` injected; zero `app()` calls in bridge. |
| 6 | `$fillable` boundary discipline | N/A for the bridge — Treasury `Payment::$fillable` already includes `origin` and `fiscal_event_id` per Task 12 (verified in Payment model). |
| 7 | Constructor-asserted invariants | N/A — bridge has no construction-time invariants beyond DI. |
| 8 | DB primitives the spec names by-the-statement | N/A — Task 22 does not use load-bearing ON CONFLICT primitives; spec explicitly chose NOT to make `payments.fiscal_event_id` UNIQUE, so the check-then-insert + Task 23 row lock pattern is the documented approach. |
| 9 | Legacy verify/audit infrastructure may inspect relocated columns (Task 21 standing pattern) | CLEAN — verified by grep that `pos:verify-chains` + `ReceiptHashService` do NOT read Treasury `payments` table. Bridge's writes don't pass through any column the legacy verifier reads. |
| 10 | D8 coexistence via spec §14 disposition (Task 21 standing pattern) | CLEAN — Task 22 keeps the legacy `ReceiptPaymentService::processReceiptPayments()` Treasury write (line 275) with a §13-stamped `origin = pos`; the deprecation docblock at lines 45-56 (Task 21 round-2) documents the disposition; §14.1 routes physical retirement to Task 28. Acceptable per the standing pattern. |
| 11 | Tag projector at `register()` not `boot()` | CLEAN — `TreasuryServiceProvider::register()` tags at line 37 (post-binding, before boot — singleton consumption is safe). |
| 12 | Wider-than-canonical parse / accept grammar (Task 16 standing pattern) | N/A — bridge consumes already-parsed payload from `FiscalEvent::payload` (post-`StrictCanonicalParser`); not at a canonical boundary. |
| 13 | Multi-constraint tables need constraint-name-targeted ON CONFLICT (Task 19 standing pattern) | N/A — no `ON CONFLICT` in bridge. |

---

## Final notes on subagent execution

The subagent followed the plan's letter on the §7.4 split (no `ReceiptPayment::create` in the bridge — verified by grep; only Treasury Payment + GL writes), the §13 writer inventory exhaustively (10 rows, every `Payment::create` site stamped — verified by cross-module grep), the canonical PascalCase `'Treasury'` token (Task 17 standing pattern), the `register()`-time tag wiring (Task 21 standing pattern), idempotency via check-then-insert with inner re-check (documented Task 12 + Task 23 contract), and most of the standing code-smell patterns. The implementer-flagged C1 (manual replay race) + C3 (defensive null check) are correctly adjudicated as acceptable; C2 is the F3 docblock fix.

The remaining gap is the **partial application of the Task 21 round-2 F3 standing pattern**: tenant-scoped FK lookup applied to `repository_id` but NOT to `payment_method_id`. Both columns have the same FK-only-checks-PK-existence hazard; both need the same application-side tenant scope. The bridge took only half the fix. This is the exact "same security gap, half the closure" pattern Task 21 round-2 itself fixed when Codex caught the docblock-vs-behavior drift on the POS-core projector. Round-2 changeset shape: F1 (cross-tenant payment_method_id gate) is the must-close BLOCKER. F2 (proration web_admin-inherit) + F3 (provider-order docblock) are must-close P1s. F4 / F5 / F6 / F7 / F8 are sweep-while-you're-in-here.

Convergent with Codex? — predicted high likelihood on F1 (the same posture Codex caught on Task 21 round-2's F3-equivalent), medium on F3 (Codex is good at provider-order claims), F2 may be Codex-unique territory if Codex reads spec §13 row 9 strictly.
