# Adversarial merge gate r2 — PR #211 (F-W2-13, supplier payments in the customer refund lane)

| Field | Value |
|---|---|
| PR | #211 `fix(treasury): refuse supplier payments in the customer refund lane (F-W2-13, P0)` |
| Round | r2 — re-gate after fix round 1 |
| r1 report | `docs/superpowers/reviews/2026-09-05-dhouha-pr-211-gate-r1.md` (spec ❌ + CHANGES-REQUESTED) |
| Handback answered | `docs/superpowers/reviews/2026-09-05-dhouha-pr-211-fix-round-1-handback.md` (on branch) |
| Gate worktree / branch | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211` · `gate/pr-211` |
| Tree reviewed | `9f5bf54aa` = local dev `fa000edc3` + `refs/pr/211` (`1ea649990`) + fix `e6f576be1` + handback `9f5bf54aa` |
| Diff | 12 files, +646/−3 (13 with the handback doc) |
| PG leg | private DB `autoerp_test_g211b` (127.0.0.1:5433) — created, used, dropped |
| Reviewer | treasury-reviewer (Opus), 2026-09-05 |

## VERDICT: spec ✅ + quality **APPROVED** (MERGE)

**Merge to local dev: YES** — the r1 BLOCKER is empirically cleared on both engines, the two MAJORs
that the fix round took on are landed and independently falsifiable, no new red was produced in any
suite or gate I ran, and every remaining item is either an explicitly-scoped follow-up (FU-1..FU-3) or
a pre-existing condition that this diff does not worsen. Merge is contingent on FU-1 being filed and
on the operator answer in §5 being accepted by the owner (it is a *stopgap*, not a lane).

---

## r1 finding → r2 status

| # | r1 finding | Severity | r2 status | Evidence |
|---|---|---|---|---|
| 1 | Guard refused every `Unsupported` shape → broke `PaymentOriginWriterInventoryTest:435`/`:471` | BLOCKER | **CLEARED** | `PaymentRefundService.php:511-515` keys on `Payment::isSupplierPayment()` only. Suite green sqlite 15/15 and PG 15/15 (§Test runs 2, 6) |
| 2 | Refusal never reached the operator (bare-string 422 + no reason on disabled buttons) | MAJOR | **CLEARED** | Typed `RefundLaneRefusedException`; catch arms `PaymentRefundController.php:90-107` / `:147-164` return `{error:{code,message,details}}`, the exact shape `apps/web/src/lib/api.ts:83-98` reads. FE note `PaymentDetailPage.tsx:428-437` + `title` `:415`,`:424`. Falsifiable both layers (§Test runs 3, 8) |
| 3 | Message pointed at `VendorRefundService`, a lane that would refuse the row too | MAJOR | **CLEARED for this lane** | New EN/FR/AR text names no class and no lane; asserted by `PaymentRefundRefusalTest.php:258-263`. **Residue:** the *reversal* twin still says it — see new finding N-2 |
| 4 | Refusing POS/POSRefund removed the only web POS refund path (scope creep) | MAJOR | **CLEARED** | `canRefund()` only adds the supplier block (`:1138-1140`); POS path byte-identical to dev. Pinned by inverted `test_pos_shapes_are_still_offered` — proven falsifiable (§Test runs 4) |
| 5 | `canRefund()` duplicated the SoT instead of using it | MINOR | **CLEARED** | `PaymentRefundService.php:1138` uses the same `isSupplierPayment()` predicate as `:512`. Correctly *not* `reversalSupport() === Unsupported` (that would reintroduce #1/#4) |
| 6 | `canRefund()` comment stated something untrue | MINOR | **PARTIAL — accepted** | Comment half fixed (`:1132-1136` now states the `Refund`/`Reversal` gap plainly). Behaviour half deliberately untouched, pre-existing |
| 7 | Idempotent-replay asymmetry for pre-fix rows | MINOR | **OPEN — accepted** | Guard still at `:159` (after `findExistingFullRefund`, before `findExistingRefundByRequestId`) and `:349` (before the replay). Handback lists it as untouched; orchestrator's call |
| 8 | No census/remediation for rows the bug already wrote | MINOR | **OPEN → FU-2** | Correctly deferred with the detection SQL carried forward verbatim |
| 9 | Data provider passed an argument the signature did not take | MINOR | **CLEARED** | Provider gone; `posShapes()` `:192-198` yields 1-element arrays matching `:201` |
| 10 | No second-company case (rule 22) | INFO | **OPEN — acceptable** | `payments` is not a catalogue entity; `docs/conventions/09` does not mandate it. Still one company (`PaymentRefundRefusalTest.php:91-105`) |
| 11 | Undeclared noun + class name in operator string | INFO | **CLEARED (class half)** | No operator string names a class. Glossary row correctly deferred to FU-1 — declaring a lane that does not exist would be a fictional surface |
| 12 | PR-body claims the gate could not reproduce | INFO | **CLEARED** | The §13 suite is now run and green on both engines; the `PaymentTest` red is proved pre-existing (§3) |

---

## New findings (r2)

### N-1. [IMPORTANT] `isSupplierPayment()` is the complete SoT for NEW writes, but NOT for legacy rows — and those rows still route into the AR shape

The handback's claim — "`PaymentType::SupplierPayment` is the ONLY shape a supplier-invoice payment is
ever written with" — is **verified true for every live writer**, exhaustively:

- Every `Payment` row writer in `apps/api/app`: `PaymentController.php:944` and `:1629`,
  `MultiPaymentService.php:102`, `:210`, `:415`, `TreasuryReceiptBridge.php:1466`,
  `TreasuryAccountPaymentBridge.php:192`, `TreasuryDepositBridge.php:176`,
  `VendorRefundService.php:121`, `PaymentRefundService.php:196`, `:377`, `:1344`, `:2331`.
  (`Billing/…/AdminBillingController.php:256` writes `App\Modules\Billing\Domain\Payment`, a different model.)
- `PaymentType::SupplierPayment` is assigned in exactly one place — `PaymentController.php:936`.
- Every other document-settling writer refuses a supplier invoice outright:
  `MultiPaymentController.php:174` and `:390` (`rejectSupplierInvoice()`, `:256`),
  `PaymentController.php:1513`/`:1846` (`rejectSupplierInvoiceInMultiline()`, `:1403`),
  `PaymentAllocationService.php:212`/`:870` (`rejectSupplierInvoiceAllocation()`, `:826`).
- No raw `DB::table('payments')->insert` anywhere (only the two backfill/census commands, which update).
- The deferred (cheque/traite) supplier branch still types the row at `:936` before the GL branch at
  `:1163`, so it is `SupplierPayment` too.

**What is NOT covered:** historical rows. `database/migrations/tenant/2026_08_25_150100_retype_supplier_and_pos_refund_payments.php`
re-types legacy supplier payments from `document_payment` **only when the GL proves it** — a *posted*
entry with a `debit > 0` line on `system_purpose = 'supplier_payable'` (`:33-70` arm a1, `:73-110` arm a2).
Its own docblock states the fail-closed residue: *"an unposted candidate keeps its old type and shows up
in the residual census"* (`:62-64`) and *"A company whose chart never mapped `supplier_payable` yields no
matching line and therefore no re-type"* (`:70-72`). Those rows are supplier-invoice payments still typed
`document_payment` — `isSupplierPayment()` returns false for them, `canRefund()` offers the button, and the
lane posts Dr 411 / Cr cash exactly as F-W2-13 describes.

**Why it matters:** this is a P0 wrong-money guard. Type-keying is the right design, but its coverage is
only as good as the retype backfill on each tenant.
**Fix (follow-up, not a merge blocker):** fold the residual census into FU-2 — run the migration's own
census query per tenant before promotion and confirm zero unretyped supplier-side `document_payment` rows.
Cannot verify from here whether any real tenant carries them (§Could not verify 1).

### N-2. [MINOR] The dead-end message r1 finding #3 condemned is still live one button away, on the same screen, for the same row

`PaymentRefundService.php:1537` (`unsupportedReversalMessage()`): *"a supplier payment cannot be reversed
here — … use the supplier refund lane (VendorRefundService)."* Pre-existing (identical text on dev at
`PaymentRefundService.php:1473`), so **not caused by this PR**. But:

- `PaymentDetailPage.tsx:437-442` — the danger **Reverse** button carries **no `disabled` prop at all**;
  it is enabled for any `status === 'completed'` payment including a supplier payment.
- `PaymentRefundController::reversePayment()` still returns the flat `['error' => $e->getMessage()]`
  (`:214-216`), the shape `getErrorMessage()` cannot read → the toast says *"Request failed with status
  code 422"*.

So the fix ships a note reading *"…is not available yet — contact an administrator"* sitting directly
above an enabled button that, when clicked, produces an unreadable error whose underlying text sends the
operator to `VendorRefundService`. The PR makes this contradiction *visible*; it did not create it.
**Fix:** fold into FU-1 (gate the Reverse button on `reversalSupport()` and give `reversePayment()` the
same typed catch arm). Not a merge blocker under rule 4.

### N-3. [MINOR] `translationKey()`'s `throw` arms turn a future mis-use into a 500, not a 422

`RefundLaneRefusedException.php:53-67` — `translationKey()` throws `\LogicException` for the seven
non-supplier cases. The controller calls it **inside** `catch (RefundLaneRefusedException $e)`
(`PaymentRefundController.php:99`, `:156`), so a `LogicException` raised there escapes the `try` entirely
and bypasses the generic `\Exception` arm → uncaught 500. Unreachable today (`:513` is the only throw site
and it hardcodes `PaymentType::SupplierPayment`), and the exhaustive-`match`-no-`default` design is the
house pattern (`unsupportedReversalMessage()`, `:1545-1557`). Flagging so the next person who adds a
refused shape knows the failure mode is a 500 and not a message-less 422.

### N-4. [MINOR] The POS-inversion test pins the read side only

`test_pos_shapes_are_still_offered` (`PaymentRefundRefusalTest.php:200-209`) asserts `canRefund()` only.
If someone re-added POS to `assertRefundableType()` and left `canRefund()` alone, this suite would stay
green — the write path is caught only by `PaymentOriginWriterInventoryTest:435`/`:471`, in a different
group (Fiscal). Both are proven falsifiable (§Test runs 4 and r1 §Test runs), so coverage exists; it is
the *locality* that is weak. One `assertRefundableType()`-reaching POS case in this file would make the
inversion self-contained. Not a blocker.

### N-5. [INFO] The handback's "all 13 ESLint warnings are on pre-existing lines" is not exact

`apps/web/src/features/treasury/PaymentDetailPage.test.tsx:145:55` —
`@typescript-eslint/require-await`, *"Async arrow function has no 'await' expression"* — sits on a line
**added by this fix round** (`mockApiGet.mockImplementation(async (url: string) => {` inside the new test
opened at `:142`). It is a warning, 0 errors, and it copies the file's own pre-existing pattern at `:104`
and `:190`. No action needed; recorded because the handback asserted otherwise.

### N-6. [INFO] FU-3 is real but is already a documented pre-existing red, not a new discovery

The handback files `PaymentTest.php:578` as "**FU-3 (new, found during this round)**". It is already on
the night handover's documented-reds list:
`docs/handoff/HANDOVER-request-hygiene-night-2026-09-04.md:42` — *"the whole backend suite must be green
except the documented pre-existing reds (`PaymentTest::test_supplier_invoice_payment_clears…`, …)"* —
and reproduced on baseline `b133caf21` in `docs/handoff/HANDBACK-request-hygiene-T10-2026-09-04.md:238-239`
and `HANDBACK-request-hygiene-T3-2026-09-03.md:204-205`. Ownership already exists; FU-3 should point at
those, not open a parallel ticket.

---

## Claim-by-claim verification

### 1. BLOCKER cleared — supplier-only guard

- `PaymentRefundService.php:511-515`:
  ```php
  private function assertRefundableType(Payment $payment): void
  {
      if ($payment->isSupplierPayment()) {
          throw new RefundLaneRefusedException((string) $payment->id, PaymentType::SupplierPayment);
      }
  }
  ```
  `Payment::isSupplierPayment()` = `payment_type === PaymentType::SupplierPayment` (`Payment.php:370-373`).
- `canRefund()` `:1138-1140` uses the same predicate; the diff **only adds** that block — nothing else in
  the method changed vs dev, so POS behaviour is byte-identical to dev by construction.
- Call sites unchanged and still on the DB-locked `$original` before any write: `:159` (`refundPayment`,
  inside the lock at `:144-149`), `:349` (`partialRefund`, inside the lock at `:341-346`).
- Only three callers of the guarded methods exist anywhere in `app/` —
  `PaymentRefundController.php:79`, `:135`, `:229` — so both write entry points have the typed catch arm
  and the read endpoint cannot throw.
- `PaymentOriginWriterInventoryTest`: **sqlite 15/15, PG 15/15** (verbatim below), including
  `refund inherits pos origin when original is pos` and its partial twin.
- Falsifiability: guard neutralised → **5 of 8** red, the 3 green ones being exactly the customer
  regression and the two POS cases. Falsifiability of the POS inversion proved separately (§Test runs 4).
- **Is `isSupplierPayment()` the only shape?** For every live writer, yes — exhaustive enumeration in
  finding N-1. For legacy rows, no — see N-1.

### 2. MAJOR 2/3 — the refusal reaches the operator

- **Envelope matches the house pattern.** New arms at `PaymentRefundController.php:90-107` (full) and
  `:147-164` (partial) return
  `['error' => ['code' => 'REFUND_LANE_REFUSED', 'message' => __($e->translationKey()), 'details' => ['payment_id' => …, 'payment_type' => 'supplier_payment']]]`
  with 422 — same shape as `refundPrepayment()`'s `REFUND_FAILED` at `:255-261`. Both arms precede the
  generic `\Exception` arm, which is what makes them effective.
- **The FE actually reads it.** `apps/web/src/lib/api.ts:83-98` `getErrorMessage()` chain is
  `data.error.message ?? data.message ?? axios string`; `PaymentDetailPage.tsx:233-234` is
  `toast.error(getErrorMessage(error))`. `data.error.message` is now populated → translated text in the toast.
- **i18n present and equivalent, both layers, three locales** (values read back programmatically):
  `apps/api/lang/{en,fr,ar}/treasury.php` key `refund_refused.supplier_payment`;
  `apps/web/src/locales/{en,fr,ar}/treasury.json:135` key `payments.refund.refusedSupplierPayment`.
- **The `ar` namespace resolves.** `apps/web/src/lib/i18n.ts:331` is
  `treasury: { ...enTreasury, ...arTreasury }` — a **shallow** spread, so `ar.treasury.payments` comes
  wholly from `arTreasury.payments`. The `ar` bundle carries the full `payments.refund` object including
  the new key, so the path resolves in Arabic (it would have silently fallen back to English had only the
  `en` bundle been edited — it was not). `treasury` is in the `ns` list at `:485`.
- **No class, no lane name** in any of the six operator strings; asserted at
  `PaymentRefundRefusalTest.php:258-263`.
- **FE gating and tokens.** `PaymentDetailPage.tsx:372-375`:
  `!canRefund && payment.payment_type === 'supplier_payment' ? t('payments.refund.refusedSupplierPayment') : null`
  — null for POS and for customer payments by construction. `title` at `:415` and `:424`;
  `role="note"` paragraph at `:428-437` with `className={cn('max-w-xs text-xs', textColors.tertiary)}`
  — design token only, no hardcoded colour (rule 18); text via `t()` only (rule 11).
  `PaymentType` is aliased to the generated global at `:49`
  (`type PaymentType = App.Modules.Treasury.Domain.Enums.PaymentType`) — no hand-rolled DTO shadow
  (rule 7 / one-surface-per-concept).
- **Vitest by path:** `PaymentDetailPage.test.tsx` 5/5, `__tests__/PaymentDetailPage.tenantScope.test.tsx` 5/5.
- **FE falsifiability proved**, not assumed (§Test runs 8).

### 3. The claimed pre-existing red

Reproduced **in the main checkout `/Users/houssamr/Projects/syneriva/apps/erp/apps/api` on unmodified
`dev` = `fa000edc39c5e2c060748db534ff0a22ed1e31a8`** (verified: `git rev-parse HEAD`; working tree carries
no PHP modifications, only untracked docs). Identical failure, identical line. See §Test runs 7.
It **is** the test the night handover already lists as a documented pre-existing red
(`docs/handoff/HANDOVER-request-hygiene-night-2026-09-04.md:42`) — see finding N-6.

### 4. Regressions and gates

Every neighbour suite the handback names re-run; **counts match the handback exactly**. Plus, beyond the
handback, I ran two CI ratchets a new file could have tripped: the design-system baseline and the feature
lane manifest. Both clean. Details in §Test runs.

### 5. Scope and follow-ups

- **Scope is clean.** 12 files: 4 PHP (new exception, service, controller, new test), 3 `lang/`, 3 web
  locale bundles, 1 page, 1 web test. Nothing outside r1 findings 1-5/9. The PR's old
  `unsupportedRefundMessage()` helper is gone and unreferenced. No money arithmetic anywhere in the diff —
  no `(float)`, no `parseFloat`, no `bc*`, no `getScale()` — so rule 19 is untouched. Module boundaries:
  the exception lives in and is used only from `App\Modules\Treasury\*` (rule 6). Constructor injection
  unchanged (rule 13); the exception is a value object, `new`'d, not injected.
- **FU-1 is correctly scoped and the gap is real — verified in code, not from the handback:**
  - `reversePayment()` refuses it — `PaymentType::reversalSupport()` maps `SupplierPayment` to
    `ReversalSupport::Unsupported` (`PaymentType.php:230`).
  - `VendorRefundService` has exactly one public method, `refundPrepayment()` (`:46`), which throws for
    anything that is not a `DocumentType::PurchaseOrder` (`:54-56`); its route is document-anchored
    (`Treasury/Presentation/routes.php:214`).
  - There is **no** `Route::delete` for payments anywhere in `app/` or `routes/`; the payment routes are
    `routes.php:181-246` and none of them undoes a supplier payment.
  So after this PR a completed supplier-invoice payment has **no undo action in any product surface**.
- **What does the operator do today?** Honest answer, verified: *nothing self-service.*
  - **Before this PR** there *was* an action — the Refund button — and it made the error **worse**: it
    posted `Dr customer_receivable 411 / Cr cash` and moved cash **OUT again**, while leaving `401`
    uncleared and `partners.payable_balance` *increased* (18.800 → 23.800, the measured damage at
    `docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:136`). Removing that is unambiguously right.
  - **After this PR** the only corrective surface is manual accounting: `POST /journal-entries` +
    `POST /journal-entries/{id}/post` (`Accounting/Presentation/routes.php:52`, `:56`) let an accountant
    book the correcting entry. That fixes the **GL only** — it does not restore
    `partners.payable_balance`, `payment_repositories.balance` or `repository_movements`, and it leaves
    the wrong `payments` row standing. Everything else is a DBA/admin data fix.
- **Is merging without FU-1 acceptable for the pharmacy launch?** **Yes, with a condition.** Trading a
  self-service action that silently corrupts three ledgers for a clear refusal plus a manual accounting
  path is the correct emergency posture, and it matches the Q-10 hotfix intent recorded in
  `docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:29`. The condition: FU-1 must be **filed
  with an owner ruling on the justifying document before launch traffic**, because a pharmacy paying
  suppliers daily will hit a mistyped supplier payment, and "contact an administrator" is a stopgap that
  needs an owner who has agreed to be that administrator. I did not find a file recording the Q-10 ruling
  itself (§Could not verify 2) — unchanged from r1.

---

## Test runs (verbatim)

Backend runs in `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-211/apps/api` on `9f5bf54aa`
unless stated. PG legs used the private DB `autoerp_test_g211b` (127.0.0.1:5433), dropped at the end.
Web runs in `.worktrees/pr-211/apps/web`.

### 1. `PaymentRefundRefusalTest` — sqlite (PASS)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundRefusalTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.15
Configuration: .../.worktrees/pr-211/apps/api/phpunit.xml
........                                                            8 / 8 (100%)
Time: 00:15.364, Memory: 165.00 MB
OK (8 tests, 33 assertions)
```

### 2. BLOCKER — `PaymentOriginWriterInventoryTest`, sqlite (PASS, was 2 errors in r1)
```
$ ./vendor/bin/phpunit tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php
...............                                                   15 / 15 (100%)
Time: 00:31.580, Memory: 167.00 MB
OK (15 tests, 34 assertions)
```

### 3. Falsifiability probe A — guard neutralised in BOTH places (`if (false && $payment->isSupplierPayment())`), sqlite: 5/8 RED
```
1) ...::test_full_refund_of_a_supplier_payment_refuses_and_writes_nothing
the refund lane must refuse this shape
.../PaymentRefundRefusalTest.php:303  /  :154

2) ...::test_partial_refund_of_a_supplier_payment_refuses_and_writes_nothing
the refund lane must refuse this shape
.../PaymentRefundRefusalTest.php:303  /  :167

3) ...::test_can_refund_is_false_for_a_supplier_payment
supplier_payment: the UI must not offer a refund action for a shape this lane refuses
Failed asserting that true is false.
.../PaymentRefundRefusalTest.php:177

4) ...::test_a_full_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Expected response status code [422] but received 201.

5) ...::test_a_partial_refund_refusal_surfaces_as_a_structured_422_over_the_http_api
Expected response status code [422] but received 201.

FAILURES!
Tests: 8, Assertions: 11, Failures: 5.
```
The 3 green are `test_a_genuine_customer_payment_still_refunds` and the two POS cases — exactly the tests
that must NOT depend on the guard. File restored from a scratchpad copy; `git status --porcelain` empty.

### 4. Falsifiability probe B — the POS inversion is real, not decorative
Injected an extra `if ($payment->payment_type === PaymentType::POS) { return false; }` into `canRefund()`
(i.e. re-created the r1 scope creep) and ran the inverted provider:
```
$ ./vendor/bin/phpunit --filter 'pos_shapes' tests/Feature/Treasury/PaymentRefundRefusalTest.php
F.                                                                  2 / 2 (100%)
1) ...::test_pos_shapes_are_still_offered with data set "pos" (PaymentType Enum (POS, 'pos'))
pos: F-W2-13 is a supplier finding — POS behaviour must be unchanged
Failed asserting that false is true.
.../PaymentRefundRefusalTest.php:205
FAILURES!
Tests: 2, Assertions: 2, Failures: 1.
```
File restored; `git status --porcelain` empty.

### 5. `PaymentRefundRefusalTest` — PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g211b DB_CENTRAL_DATABASE=autoerp_test_g211b \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundRefusalTest.php
   PASS  Tests\Feature\Treasury\PaymentRefundRefusalTest
  ✓ full refund of a supplier payment refuses and writes nothing        48.42s
  ✓ partial refund of a supplier payment refuses and writes nothing     15.45s
  ✓ can refund is false for a supplier payment                           8.79s
  ✓ pos shapes are still offered with data set "pos"                    11.28s
  ✓ pos shapes are still offered with data set "pos refund"              4.65s
  ✓ a genuine customer payment still refunds                             6.52s
  ✓ a full refund refusal surfaces as a structured 422 over the http ap… 4.60s
  ✓ a partial refund refusal surfaces as a structured 422 over the http… 9.92s
  Tests:    8 passed (33 assertions)
  Duration: 109.90s
```

### 6. BLOCKER — `PaymentOriginWriterInventoryTest`, PostgreSQL (PASS, was 2 failed in r1)
```
   PASS  Tests\Feature\Fiscal\PaymentOriginWriterInventoryTest
  ✓ payment controller store stamps web admin                           24.69s
  ✓ payment controller store multiple stamps web admin                   3.40s
  ✓ multi payment create split stamps web admin                          7.73s
  ✓ multi payment record deposit stamps web admin                        8.03s
  ✓ multi payment record payment on account stamps web admin             9.86s
  ✓ refund inherits pos origin when original is pos                      5.62s
  ✓ refund inherits web admin origin when original is web admin          3.41s
  ✓ partial refund inherits pos origin when original is pos              4.06s
  ✓ partial refund inherits web admin origin when original is web admin  3.57s
  ✓ refund payment falls back to unknown legacy when original origin is… 4.45s
  ✓ partial refund falls back to unknown legacy when original origin is… 3.96s
  ✓ proration refund falls back to unknown legacy when original origin…  2.53s
  ✓ receipt proration refund inherits pos origin                         2.41s
  ✓ proration refund inherits web admin origin when original is web adm… 2.52s
  ✓ vendor refund prepayment stamps web admin                            8.88s
  Tests:    15 passed (34 assertions)
  Duration: 95.22s
```

### 7. Pre-existing red reproduced on UNMODIFIED dev, in the MAIN checkout
```
$ git rev-parse --abbrev-ref HEAD && git rev-parse HEAD
dev
fa000edc39c5e2c060748db534ff0a22ed1e31a8

$ cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
$ ./vendor/bin/phpunit --filter test_supplier_invoice_payment_clears_401_and_reduces_payable_balance \
    tests/Feature/Treasury/PaymentTest.php
F                                                                   1 / 1 (100%)
1) Tests\Feature\Treasury\PaymentTest::test_supplier_invoice_payment_clears_401_and_reduces_payable_balance
Failed asserting that two values of enumeration App\Modules\Treasury\Domain\Enums\PaymentType are equal,
SupplierPayment does not match expected DocumentPayment.
/Users/houssamr/Projects/syneriva/apps/erp/apps/api/tests/Feature/Treasury/PaymentTest.php:578
FAILURES!
Tests: 1, Assertions: 2, Failures: 1.
```
Read-only: no file in the main checkout was modified.

### 8. Web — vitest by path (PASS) + falsifiability
```
$ ./node_modules/.bin/vitest run src/features/treasury/PaymentDetailPage.test.tsx
 ✓ src/features/treasury/PaymentDetailPage.test.tsx (5 tests) 280ms
 Test Files  1 passed (1)      Tests  5 passed (5)

$ ./node_modules/.bin/vitest run src/features/treasury/__tests__/PaymentDetailPage.tenantScope.test.tsx
 ✓ src/features/treasury/__tests__/PaymentDetailPage.tenantScope.test.tsx (5 tests) 1169ms
 Test Files  1 passed (1)      Tests  5 passed (5)
```
Probe — `refundRefusalReason` condition replaced with `false`:
```
   × PaymentDetailPage presentation > explains why refunding is refused for a supplier payment ...
     → Unable to find role="note"
   ✓ offers the refund actions with no refusal note for a refundable customer payment
      Tests  1 failed | 4 passed (5)
```
File restored; `git status --porcelain` empty. (React `act(...)` advisories print on the pre-existing
cases too and are not failures.)

### 9. Neighbour suites — sqlite (counts match the handback exactly)
```
$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundProrationTest.php \
    tests/Feature/Treasury/RefundSpineTest.php tests/Feature/Treasury/PaymentReversalRefusalTest.php \
    tests/Feature/Treasury/PaymentRefundSecurityTest.php tests/Unit/Treasury/PaymentTypeReversalTest.php
.....................................................             53 / 53 (100%)
OK (53 tests, 195 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/VendorPrepaymentRefundTest.php \
    tests/Feature/Treasury/SupplierPaymentGuardTest.php tests/Feature/POS/PosAnalyticsRefundNettingTest.php
.........................                                         25 / 25 (100%)
OK (25 tests, 135 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRefundTest.php
....................                                              20 / 20 (100%)
OK (20 tests, 79 assertions)
```

### 10. `PaymentRefundTest` — PostgreSQL (PASS)
```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g211b DB_CENTRAL_DATABASE=autoerp_test_g211b \
  php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRefundTest.php
  ✓ reverse payment is idempotent                                        2.52s
  ✓ a reversal row cannot be refunded                                    3.33s
  ✓ a refund row cannot be refunded                                      4.84s
  ✓ partial refund of a negative payment fails by rule not by accident   2.28s
  ✓ an ordinary positive payment is still refundable                     1.89s
  ✓ multiple partial refunds accumulate                                  1.50s
  Tests:    20 passed (79 assertions)
  Duration: 62.43s
```

### 11. PHPStan level 8 — the 4 touched PHP files (PASS)
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G --no-progress \
    app/Modules/Treasury/Domain/Services/PaymentRefundService.php \
    app/Modules/Treasury/Domain/Exceptions/RefundLaneRefusedException.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php \
    tests/Feature/Treasury/PaymentRefundRefusalTest.php
Note: Using configuration file .../apps/api/phpstan.neon.
 [OK] No errors
```

### 12. Pint — touched PHP + lang files (PASS)
```
$ ./vendor/bin/pint --test <the 4 PHP files> lang/en/treasury.php lang/fr/treasury.php lang/ar/treasury.php
{"result":"pass"}
```

### 13. Scoped ESLint — 0 errors
```
$ ./node_modules/.bin/eslint src/features/treasury/PaymentDetailPage.tsx \
    src/features/treasury/PaymentDetailPage.test.tsx
✖ 13 problems (0 errors, 13 warnings)
```
Warnings: `precision/no-parsefloat-on-money` at `PaymentDetailPage.tsx:317`, `:326`, `:327`, `:493`,
`:501`, `:597`, `:783`; `@typescript-eslint/no-unnecessary-condition` at `:187`, `:197`, `:330`;
`@typescript-eslint/require-await` at `PaymentDetailPage.test.tsx:104`, `:145`, `:190`. None falls inside
the added range `:365-442` of the page. `:145` is a NEW line — see finding N-5.

### 14. TypeScript — `tsc --noEmit` (PASS; swap free was 874 MB, above the 300 MB floor)
```
$ ./node_modules/.bin/tsc --noEmit
EXIT=0   (no output)
```

### 15. CI ratchets a new file could trip (both PASS — beyond the handback's scope)
```
$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries

$ node tools/audit-tanstack-keys.mjs
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries

$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1509 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1920 test classes across all suites.
EXIT=0

$ ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php
OK (76 tests, 431 assertions)
```

### 16. Worktree left untouched
```
$ git status --porcelain    (after every probe and at the end)
(empty)
```
Private DB `autoerp_test_g211b` created at the start and `DROP DATABASE`d at the end.

---

## What I could not verify

1. **Whether any real (non-test) tenant carries supplier-side payments the guard misses** — either rows
   already wrongly refunded (r1 #8 / FU-2) or rows still typed `document_payment` because the
   2026-08-25 retype migration's fail-closed arms skipped them (finding N-1). I queried no tenant DB.
2. **Whether the owner has ruled Q-10.** Unchanged from r1: `HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:29`
   says "confirm the ruling with the owner"; I found no file recording the ruling.
3. **The CI i18n-completeness gate.** `node tools/audit-i18n-completeness.mjs` fails closed locally
   (`I18N_BASELINE_PROTECTED_BLOB is unset` — an owner-set repo variable, CI-only). I verified the key
   exists and is non-empty in all three backend `lang/` files and all three web bundles at the same path,
   but I cannot assert the CI gate's verdict.
4. **Visual layout of the new note.** It renders as a `max-w-xs` paragraph *between* the two disabled
   refund buttons and the Reverse button, inside the `PageHeader` actions row. No browser leg was run
   (no local stack); wrapping/alignment in that flex row is unverified.
5. **The whole backend/frontend suites.** Forbidden / not run. Only the files named above.
6. **Whether a back-office operator actually uses the POS-payment refund action today.** Unchanged from r1.

---

## What to fix before merge

Nothing blocking. File **FU-1** (supplier-invoice payment reversal lane, with the owner ruling on its
justifying document) and fold findings **N-1** (per-tenant residual census for supplier payments still
typed `document_payment`) and **N-2** (gate the Reverse button + give `reversePayment()` the same typed
catch arm) into it, so the operator is not left with a note that says "not available yet" beside an
enabled button that fails unreadably.

**Merge to local dev: YES** — r1 BLOCKER cleared on sqlite and PostgreSQL with an independently
reproduced falsifiability probe, both MAJORs landed and reaching the operator, zero new reds across 8
suites and 4 CI ratchets, PHPStan/Pint/ESLint/tsc clean, and every open item is a tracked follow-up or a
pre-existing condition this diff does not worsen.
