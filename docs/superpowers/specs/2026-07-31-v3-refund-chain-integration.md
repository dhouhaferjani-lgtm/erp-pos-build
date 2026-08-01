# v3 Refund/Void Chain Integration — Design Spec (REVISION 4.2 — FINAL, TENANT-#1 LAUNCH SLICE)

**Lane:** C (first-tenant launch program). **Phase:** SPEC ONLY — zero code changes.
**Round-4 verdicts on Revision 4 (`b99b7beb3`):** fiscal-pos-reviewer **APPROVE** (code phase may
be planned, subject to errata), treasury-reviewer **APPROVE-WITH-FIXES** (one hard blocker —
T1 — plus bounded boundary contracts), Codex 6/7 items **LANDED**, item 7 **PARTIAL** (3
mechanical citation fixes). Reviews:
`docs/superpowers/reviews/2026-07-31-lane-c-spec-r4-verify-consolidated.md`,
`docs/superpowers/reviews/2026-07-31-codex-refund-chain-spec-r4-verify.md`.
**Treasury micro-verify on the 4.1 errata (T1–T3):** T1 PASS, T2 PASS, **T3 MISS** — the
`endOfDayPreview.ts` fix in 4.1 added `receipt_kind` and branched the tolerance/rounding/change-due
sums, but left `cashTenderedSum`/`perMethod`'s per-payment loop unbranched; deleting the separate
`cashRefundImpact` subtraction (as 4.1 correctly did for the now-redundant path) without also
branching that loop would have reintroduced a +2× expected-cash error via a different code path
in the same file. **Revision 4.2 (this revision) closes that miss** — see the errata-4.2 markers
in §7.3a, §5.3, and §7.2a below. No further review round is planned.

**This is the final spec edit, not a fifth design round.** Revision 4's design was verified
sound; revision 4.1 applied the round-4 verification's exact single-fold correction list
(T1–T7, F1–F7, X1, X3); revision 4.2 closes the one item (T3) that fold left incomplete. The
substantive corrections across 4.1–4.2 are: **T1** (hard blocker) — `SalesReturn` was falsely
claimed seeded in all three chart seeders; only Generic actually has it, so the `valid_unbooked`
write-off class was a 500 for tenant #1 until now (§5.3), plus a resolved revenue-vs-expense
account-type divergence on the FR/TN fix itself (errata 4.2, same section). **T2** — the
`offline_receipts` refund row's status never flipped off `'pending'` (its fiscal event's
`source_event_class` doesn't match the existing sync-completion-flip condition) and several
NOT-NULL columns, most critically `hash_sequence` (the Z query's own windowing column), were
unspecified — the exact class of silent-Z-drop bug this lane exists to close (§7.2a). **T3**
(closed in 4.2) — `endOfDayPreview.ts` runs its own,
separate query/loop that §7.3's `zReportService.ts` fix never touched (§7.3a). Every remaining
item (T4–T7, F1–F7, X1, X3) is a bounded citation, naming, or wording correction, applied exactly
as specified in the consolidated verification record — each is tagged inline at its edit site so
the fold can be checked mechanically, item by item, against that record.

---

## Fold-completeness map (verify against this table first)

| # | Fold item (converged list) | Sources | Closed in |
|---|---|---|---|
| 1 | V4 sign contract + discount arithmetic; ⚖️ transaction-discount refusal ruling | fiscal C-1/C-2, treasury C-1, Codex §4.1 | §3 |
| 2 | `offline_receipts` insert ruling + discriminator + filter contract | treasury C-2, orchestrator ruling | §7 |
| 3 | §12 cap query sign normalization + mixed legacy-population test | fiscal C-3 | §12 |
| 4 | Capability flag delivery; ⚖️ two-phase enable/acknowledge + >1-terminal preflight refusal | fiscal C-4, Codex §4.7 | §9 |
| 5 | Write-off redesign; ⚖️ server-observable evidence + class-dependent entries + movement port + idempotency | treasury C-3, Codex §4.4 | §5 |
| 6 | Seal-discriminator backfill legality; ⚖️ one-time trigger-permitted transition | Codex §4.5 | §6 |
| 7 | Intent index/lifecycle (exclude `synced`, cumulative-quantity idempotency, print recovery, payout-dispute money effect) | fiscal I-4, Codex §4.3, treasury | §4.4–§4.5 |
| 8 | Non-retryable enactment (job classification + regression test) | fiscal I-3, Codex §4.2 | §4.2 |
| 9 | Policy-evidence claim narrowed (server-advisory for launch) | Codex item 7 | §3.6 |
| 10 | Manifest/citation sweep (14 sub-items, listed individually below) | Codex §5, §6 items | §15, §17 |

**Two mechanical notes from the orchestrator, folded:** (a) Lane B's promoted eslint gate — the
rewritten refund stores/services route cart mutations through the gated helpers (fiscal M-8),
§17. (b) Ticket citation corrected to the real path
`docs/superpowers/tickets/2026-07-31-treasury-bridge-training-money-legs.md`; the cash-drawer
ticket — now confirmed to exist at `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`
(created by the orchestrator between rounds 3 and 4) — is cited by its real path, not a
placeholder (§3.7).

---

## 1. Today's failure mode (unchanged from Revision 3 — not touched by round 3)

Kept verbatim: the state-dependent trace (`current_sequence = 0` → PG CHECK `23514`;
`current_sequence = 1` colliding with the first projected sale → PG unique `23505`; a sequence
gap → conditional orphan commit; full rollback; controller 422; VOID never touches the legacy
chain), the acceptance test shape (§1.1), and the verifier-partition determination. None of the
three round-3 reviews found anything to fix here.

### 1.1 Acceptance test (unchanged)

`apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php`, Postgres test mode, both
`current_sequence` topologies, sale at `event_version = 3`, refund at `event_version = 4`,
next-sale-chains-off-refund assertion, `fiscal:verify-event-chain --tenant=<id> --terminal=<id>
--actor-id=<system-actor-uuid>` + `pos:verify-chains --terminal=<id>` both green, negative 409
companion, v2-non-regression companion (with the terminal's `fiscal_schema_version = 2` set
**explicitly** in the fixture post-D1 — fold item 10 sub-item: D1 changed default terminal
creation, so a v2 test terminal must no longer rely on any default and must set the version
explicitly).

---

## 2. v4 authoring executability (unchanged core — Codex r3 called this "closed, subject to item
2's schema defects," which §3 now closes)

The payload-aware `eventVersionFor(type, payload?)` API, the `FiscalEventEngine.append()` call-site
threading, `SALE_RECEIPT_PAYLOAD_KEYS_V4`, and `validateSaleReceiptPayload(payload, eventVersion)`
are unchanged from Revision 3. **Manifest correction (fold item 10):**
`apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` **already exists** (192
lines, confirmed) — every current `eventVersionFor()` call in it passes only a type string (e.g.
`expect(registry.eventVersionFor('SALE_RECEIPT')).toBe(3)`), proving today's payload-less
behavior is the green baseline this file's tests pin. §2's manifest entry is **extend**, not
**new**: the extension must state explicitly that every existing assertion keeps passing
unmodified (the payload-less overload — a `SALE_RECEIPT` call with no second argument — must
still resolve to `3`, matching `SALE`/`TRAINING`'s default, so none of today's 192 lines needs to
change), and new cases are appended for the `payload`-aware REFUND→4/VOID→throw behavior.

**Exact version-resolution rule, stated precisely (errata F7):**

| `eventVersionFor('SALE_RECEIPT', payload?)` call shape | Resolution |
|---|---|
| Second argument **absent** entirely | `3` — back-compatible with every existing call site (the 192-line registry test's payload-less assertions, §2 above, and every other `SALE_RECEIPT`-authoring path this feature does not touch) |
| Second argument **present**, `payload.invoice_type_code` is `'SALE'` or `'TRAINING'` | `3` |
| Second argument **present**, `payload.invoice_type_code` is `'REFUND'` | `4` |
| Second argument **present**, `payload.invoice_type_code` is `'VOID'` | throws `VoidAuthoringProhibitedError` |
| Second argument **present**, `invoice_type_code` missing, non-string, or an unrecognized value | throws `FiscalEventTypeNotImplementedError` — fail-closed, never silently defaults to `3` |

**M-6 error-type overload, noted:** the last row's failure and the "reserved-but-unimplemented
event type" failure elsewhere in this registry both surface as
`FiscalEventTypeNotImplementedError` — the same exception class covering two conceptually
different conditions (an unrecognized `SALE_RECEIPT` discriminator vs. an entirely unimplemented
`FISCAL_EVENT_TYPES` member). This is an accepted, pre-existing overload of that error type, not
introduced by this feature — noted here so a caller catching it for one reason does not
mis-attribute the other, but not changed as part of this manifest.

**VOID server-side rejection lives in the validator, not a new registry branch (fold item 10):**
the server `FiscalEventPayloadRegistry.php` change is limited to adding `4` to its
`SUPPORTED_VERSIONS`/equivalent list for `SALE_RECEIPT` — VOID rejection at `event_version = 4`
is enforced by `FiscalPayloadConstraintValidator.php`'s per-version constraint check (the same
place the `invoice_type_code` enum and the v4 key set are validated), not by a second
registry-level branch duplicating the device's own boundary check. This keeps the "authoritative
version list" and "per-version content rules" concerns in their existing, separate files rather
than conflating them.

---

## 3. The exact v4 schema (fold item 1 — fiscal C-1/C-2, treasury C-1, Codex §4.1, all three
formulations satisfied)

### 3.1 Sign contract — positive magnitudes everywhere; direction lives in `invoice_type_code`
only (fiscal C-1)

**One rule, restated as the governing principle for every field in this section:** every money
and quantity field in the signed v4 canonical payload — `line_items[].quantity`,
`line_items[].line_total`, `line_items[].line_vat`, `subtotal`, `vat_total`, `total`,
`transaction_discount_amount` — is a **non-negative magnitude**, identical in shape to a sale's.
There is no sign-bearing money field anywhere in the payload. The **only** thing that marks a
receipt as a refund is the top-level `invoice_type_code = 'REFUND'` discriminator plus the
non-null `original_receipt_reference`. This is not a new rule invented here — it is the existing,
already-enforced server contract (`FiscalPayloadConstraintValidator.php:822-825`'s non-negative
`moneyRegex`) that a naive "just delegate the negative cart to the V3 builder" implementation
would silently violate.

### 3.2 Normalization lives in `RefundReceiptV4Payload.ts`, NOT `hydrateFromReceipt.ts` (Codex §4.1)

`hydrateFromReceipt.ts` is **unchanged in sign behavior** from Revision 3 — it continues to
produce `CartItem`s with **negative** `quantity`/`line_total`/`tax_amount` (`:55-83`), because the
on-screen return cart's UI depends on negative display values (a return line reads "-2 ×
Product = -20.00" in the cart, matching every other negative-return-line UI convention already
in the codebase). Revision 3's discount-field fix to `hydrateFromReceipt.ts` (carrying
`discount_amount`/`discount_reason` through onto the returned `CartItem`, unchanged from
Revision 3) stays exactly as specified — that fix is about **which fields** survive into the
cart item, not about **sign**.

**The required normalization step Codex found missing:** `RefundReceiptV4Payload.ts` (§17's new
builder file) is responsible for converting the negative-signed `CartItem[]` into the
positive-magnitude shape §3.1 requires, **before** delegating to `buildSaleReceiptV3Payload`.
Exact steps, in order:

1. Assert every input item has `kind === 'return'` (defense-in-depth — the classification
   boundary at `cartClassification.ts` should already guarantee this; a violation here is a
   programmer error and throws, never silently coerces, matching the existing
   `LineArithmeticInvariantError` fail-loud convention).
2. For each line: `positiveQuantity = bcabs(item.quantity)`, `positiveLineTotal =
   bcabs(item.line_total)`, `positiveTaxAmount = bcabs(item.tax_amount)`. `unit_price` is
   already positive on a return `CartItem` (only the derived totals carry the negative sign) —
   passed through unchanged. `discount_amount` (now correctly present per Revision 3's
   `hydrateFromReceipt.ts` fix, §3's kept discount-carry-through) is **already** a non-negative
   magnitude on both sale and return lines (a discount is a reduction regardless of direction) —
   passed through unchanged, no `bcabs()` needed.
3. Build the positive-magnitude line-item array and receipt-level aggregates
   (`subtotal`/`vat_total`/`total`, all positive), then delegate to `buildSaleReceiptV3Payload`
   with these positive values — satisfying the same `line_total === unit_price × quantity −
   discount_amount` invariant (`SaleReceiptPayload.ts:262-294`) a sale already must satisfy,
   because the normalized values are now shaped exactly like a sale's.
4. Compose the V4 fields (`original_line_references[]`, `refund_destination`,
   `settlement_allocation: null`) on top, per §3.3.

**New test proving this exact path** (§17): `RefundReceiptV4Payload.test.ts` constructs a
negative-signed return cart (including at least one discounted line) and asserts the resulting
canonical payload passes the **existing, unmodified** `assertSaleReceiptAggregates`/
`assertSaleReceiptAggregatesV3` invariants with entirely positive values — proving the
normalization step, not merely asserting it in prose.

### 3.3 Return-line object — exact frozen key set, types, enum (Codex §4.1 "spell out the object
shape")

`original_line_references[i]`, one entry per `line_items[i]` (strict parallel array, kept from
Revision 3's §3.1 positional-alignment/equality invariants — `len` equal, `product_id` equal,
`quantity` equal at each index `i`), has **exactly** these keys, no more, no fewer (validated by
the same `assertExactKeySet`-adjacent mechanism used everywhere else in this contract):

```ts
interface OriginalLineReferenceInput {
  readonly original_line_index: number;   // integer >= 0, index into the ORIGINAL sale's line_items[]
  readonly product_id: string;            // UUID, must equal line_items[i].product_id
  readonly quantity: string;              // canonical positive quantity string, must equal line_items[i].quantity
  readonly disposition: 'restock' | 'scrap' | 'not_received'; // ReturnLineDisposition enum values, verbatim
}
```

`disposition`'s three literal values are the **exact, verbatim** values of the existing PHP
`ReturnLineDisposition` enum (`restock`, `scrap`, `not_received`) — no new enum, no renaming; the
v4 contract reuses the vocabulary the legacy path already established rather than inventing a
parallel one.

### 3.4 `refund_destination` / `settlement_allocation` (unchanged from Revision 3)

`refund_destination: 'cash'` (single literal for launch, §0's scope); `settlement_allocation:
null`, required present-and-null on every launch v4 payload. Unchanged — round 3 raised no
finding against this sub-item.

### 3.5 Transaction-discount ruling — REVISED per the orchestrator's ⚖️ binding ruling (fiscal
C-2, Codex §4.1's arithmetic objection)

**Revision 3's ruling is withdrawn as mathematically unsound, confirmed by direct verification:**
Revision 3 allowed a full-receipt refund of a transaction-discounted original to emit
`transaction_discount_amount = 0`, reasoning that per-line discounts already accounted for it.
Codex traced the actual persistence model and found this false: `offlineReceiptRepository.ts:37-47`
stores `transaction_discount_amount` **separately** from line-level discounts;
`receiptService.ts:523-558` persists per-line discount and transaction discount as two
**independent** fields; and `SaleReceiptPayload.ts:121-147,193-201` enforces the aggregate
identity `subtotal + VAT = total + transaction_discount_amount`. Zeroing a genuinely non-zero
original transaction discount on the refund payload either silently over-refunds the customer
(the refund total no longer reflects the discount that was actually applied) or breaks the
aggregate identity outright — there is no way to make "full refund, discount zeroed" both
correct and invariant-consistent without a proration step this launch does not build.

**⚖️ Orchestrator ruling, binding, replacing Revision 3's §3.3 in full: launch REFUSES any refund
— partial or full — of a receipt whose original `transaction_discount_amount` is non-zero.** The
device checks this at local-resolution time (§4.1's original-lookup step, unchanged location):
if the resolved original's own signed `transaction_discount_amount` is non-zero, the refund flow
shows a typed refusal ("this receipt has a whole-receipt discount; refunds are not available for
it in this launch") **before** any approval authoring, for both partial and full line selections.
There is no full-receipt carve-out. **Whole-discount-receipt refunds move to §16.4 (roadmap)** —
a genuine future feature needing either a proration algorithm or a decision to preserve the exact
original discount unmodified on a full refund with server-side verification that the refund is
provably 1:1 with the original (open design question, not attempted here). This is strictly
simpler than Revision 3's ruling (one refusal condition, no partial/full distinction) and closes
the arithmetic hole entirely rather than narrowing it.

`v4Payload.transaction_discount_amount` is therefore **always canonical zero** on every launch v4
refund (unconditionally — since a non-zero-original-discount refund never reaches payload
construction at all, this is not a separate runtime branch, it is a structural consequence of the
refusal above).

**Test placement and server-side defense-in-depth (errata T7, minor):** the refusal's primary
test lives at the **lookup level**, not only inside `RefundReceiptV4Payload.test.ts`'s
builder-level proof — a new test asserts the refusal fires at
`resolveOriginalFiscalEventLocally()`'s own call site (the refund flow's original-resolution
step, §4.1), before any payload construction is attempted, since that is where a real cashier's
attempt is actually stopped. **Server-side flag, mirroring §3.6's accept-and-flag mechanism:**
in case a future or compromised client build bypasses the device-side refusal, the projector adds
a `refund_policy_alerts` entry (§3.6, unchanged mechanism) whenever it observes a resolved
original with a non-zero `transaction_discount_amount` on an incoming v4 REFUND event — this is
detection/flagging only, not a projection-time reject (Model 1, §4.1: an already-signed event is
never silently dropped), giving the finance team visibility into a launch-invariant violation
that should be structurally impossible from a correctly-behaving device.

### 3.6 Policy evidence — narrowed to server-advisory for launch (fold item 9, Codex item 7)

Revision 3 claimed the device is "the sole real-time gatekeeper" for return-window/daily-cap/
manager-threshold/disposition policy, backed by "signed evidence." Codex correctly found no
signed policy snapshot exists in the v4 contract (§3.3's frozen shape above has no window/cap
timestamp or policy-version field) and no device cache/enforcement files were in the manifest —
the claim of device-side *proof* was unsupported prose, not a specified mechanism.

**Ruling, narrowing rather than building the missing mechanism:** for this launch, return
window / daily cap / manager-threshold / disposition-vs-regulated-stock policy are
**server-advisory only** — there is no device-side real-time enforcement claim. The projector's
`refund_policy_alerts` accept-and-flag mechanism (kept from Revision 3, modeled on the existing
`PosCoreReceiptProjection.php:1051-1071` late-sale-flag precedent) is **the entire mechanism** —
it computes whatever policy questions it can from data it already has **after** the event is
signed, and flags discrepancies for manual review; it never claims this was device-proven in
advance. **Timestamp-only, not permission-based (errata F1):** the alert source is limited to
what the projector can compute purely from the original receipt's own timestamp (return-window
questions) — **not** the cashier's permission set. `ApplyFiscalEventProjectionJob` runs on a
Horizon worker with **no bound Spatie permissions team** (no `CompanyContext`, per rule 20's
established worker-context gap, already relied on elsewhere in this spec) — a permission-set
check inside the projector would silently evaluate against an unbound/wrong team and produce
meaningless results, not a real policy signal. Any manager-threshold/daily-cap advisory that
would require a permission check is deferred to §16.5's signed-snapshot mechanism (where the
device signs the fact it checked, rather than the server attempting to re-check permissions it
cannot correctly resolve) — dropped from launch's `refund_policy_alerts` scope entirely, not
silently left half-working. A **signed**
device-side policy snapshot (the device asserting, in the payload itself, "I checked the window
and it was N days") is deferred to §16.5 — a real future capability, not attempted here. This is
a narrowing of the claim, not new code: it removes the false "device proves it" prose and states
plainly what already existed (the accept-and-flag advisory mechanism), satisfying Codex's
"pick one" instruction by picking server-advisory.

### 3.7 `payments[]`, training refusal, and the corrected ticket citation (unchanged mechanism,
citation fixed)

`payments[]` is exactly one cash leg (unchanged from Revision 3). **Training-original refusal —
enforcement site named exactly (errata T4):** the check runs inside `RefundReceiptV4Payload.ts`'s
normalization step (§3.2), immediately after `resolveOriginalFiscalEventLocally()` resolves the
original — before step 1 of §3.2's ordered procedure (i.e., before the `kind === 'return'`
defense-in-depth assertion even runs, since a training-original refusal is a harder gate than a
programmer-error check). The refusal reads `training_flag` from the **resolved original fiscal
event's own signed `payload.training_flag`** — never from the refunding session's *current*
training-mode context (`PosOverrideContext.isTraining`, an unrelated concept: whether *this*
register is presently in training mode, not whether the *original sale being refunded* was a
training transaction). **Attribution fix:** `resolveOriginalFiscalEventLocally()`'s returned
`training_flag` must be read from the original event's `payload.training_flag` field specifically
(the same field §3.5's `transaction_discount_amount` check already reads from), not from any
column on the local `fiscal_events` mirror row that might be conflated with the *current*
session's training state — the function's return type names this field explicitly as
`original.training_flag` to make the source unambiguous at the call site. **New device test**
(§17): `RefundReceiptV4Payload.trainingRefusal.test.ts` asserts a refund attempt against a
locally-resolved original whose `payload.training_flag === true` is refused before any approval
authoring, and that a refund against a non-training original in a training-mode *session* is
**not** incorrectly refused (proving the two concepts are not conflated).

**Citation fix (mechanical note (b) from the orchestrator):** the
training-gate-leak ticket is `docs/superpowers/tickets/2026-07-31-treasury-bridge-training-money-legs.md`
(confirmed existing at this exact path) — Revision 3's placeholder path is replaced with this
real one. The unrelated `CashDrawerService` v3 expected-cash ticket, also referenced in §7.5, is
now confirmed to exist at `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`
(created between rounds 3 and 4) — cited by its real path, not "TBD."

---

## 4. Settlement authority, durable intent, approval protocol

### 4.1 Model 1 (unchanged)

### 4.2 Approval protocol (unchanged core) + non-retryable enactment (fold item 8, fiscal I-3,
Codex §4.2)

The deterministic pre-generated UUID source-ID design (`posOverrideAuthoring.ts` gains an
optional `sourceEventIds` parameter, backward-compatible), the exact recovery query, and the
projector-side seven-field evidence verification are **unchanged from Revision 3** — Codex r3
confirmed these close the round-2 recovery-key concern.

**What was missing, closed now:** Revision 3 declared `ApprovalEvidenceUnresolvedException`
"non-retryable" in prose without changing the job that would need to honor it.
`ApplyFiscalEventProjectionJob`'s only `catch (Throwable $e)` block (`:392-401`) calls
`advanceFailureAccounting()` (resets `projection_status = Pending`, increments `attempts`) and
re-throws — Horizon retries up to `$tries = 5` regardless of exception type; there is no existing
fast-path to immediate dead-letter (`recordHardFailure()`, used for missing-projector/
missing-FiscalEvent, does the **same** pending-reset-and-rethrow as `advanceFailureAccounting()`
— it is a differently-named log message, not a different retry outcome, confirmed by direct
reading).

**New mechanism (§17's manifest):**
- New marker interface `apps/api/app/Modules/Fiscal/Domain/Exceptions/NonRetryableProjectionException.php`
  — implemented by both `RefundQuantityExceededException` (§12) and
  `ApprovalEvidenceUnresolvedException` (§4.2).
- `ApplyFiscalEventProjectionJob.php` — a new `catch (NonRetryableProjectionException $e)` block,
  placed **before** the existing generic `catch (Throwable $e)` (PHP dispatches to the first
  matching type), performing the **same** terminal-state write `failed()` already does
  (`projection_status = DeadLettered`, `dead_lettered_at = now()`, `last_error` set, `Log::critical`
  with the same structured fields) **immediately**, then calling `$this->fail($e)` (Laravel's
  built-in immediate-fail primitive on a queued job using `InteractsWithQueue`) instead of
  re-throwing — bypassing Horizon's remaining retry attempts entirely rather than letting them
  run to exhaustion first.
- **Regression test** (§17): `ApplyFiscalEventProjectionJobNonRetryableTest.php` asserts that
  after one `NonRetryableProjectionException`, `attempts === 1` (not 5), `projection_status ===
  DeadLettered` on the **first** job execution, and no second Horizon attempt is dispatched —
  proving the classification actually changes behavior, not merely that the exception is
  correctly typed.

### 4.3 Intent transaction order (unchanged — append-first, confirmed correct by Codex r3)

### 4.4 `refund_intents` schema — active-index fix, cumulative-quantity idempotency (fold item 7,
fiscal I-4, Codex §4.3)

**The bug Codex found:** Revision 3's partial unique index excluded `applied`,
`dead_lettered_local`, and `abandoned` from the "active" set — but `applied` was never a real
local state (§4.6 stops the local machine at `synced`; server-side application is not locally
observable), so the exclusion list was vacuous for the state that actually matters. `synced` was
never excluded, meaning a **successfully synced** partial refund stayed permanently "active,"
blocking every subsequent legitimate partial refund of a different, valid quantity against the
same original+line selection.

**Fix:** the partial unique index on `(original_local_receipt_id, line_snapshot_fingerprint)`
excludes `synced` (in addition to `dead_lettered_local`/`abandoned`) — i.e., it is scoped to
**only** `state IN ('drafted', 'approval_authored', 'refund_event_appended')`, the genuinely
in-flight window before local terminal state is reached. Once an intent reaches `synced`, a new
intent for the same original+line-selection fingerprint is free to be drafted.

**Cumulative-quantity idempotency for repeat identical partials, ruled explicitly (Codex §4.3's
"refunding one unit twice" scenario):** the *local* uniqueness index intentionally does not
attempt to prevent two genuinely valid, sequential partial refunds of the same line (e.g., "1 of
2 units today, 1 of 2 units next week") — that would wrongly block legitimate repeat business.
**§12's server-side per-original quantity-cap lock is the sole authority for this** — every
refund attempt, however many times the same original+line pair is refunded, is checked against
the cumulative already-refunded quantity at projection time (§12, unchanged mechanism), which
correctly allows N legitimate sequential partials up to the original quantity and rejects the
`(N+1)`th. The device-local index's only job is preventing a **duplicate in-flight** attempt
(double-click, restart-retry) — never a policy decision about how many times a line may
legitimately be refunded over time. This is stated explicitly so the two mechanisms (local
uniqueness, server cap) are understood as non-overlapping, not redundant.

> **⚖️ ERRATUM — post-FINAL, 2026-08-01, orchestrator-ruled (wave-2 fix wave, finding 18 /
> Codex Q-1).** In addition to the duplicate-in-flight guard above, the device carries a
> **cumulative-quantity BACKSTOP** (`getCumulativeRefundedQuantityByOriginalLine()`), which sums
> the already-refunded quantity per `original_line_index` across every `refund_intents` row for
> the same original that has reached a state PROVING a fiscal event was appended
> (`refund_event_appended` or `synced`), and refuses a new selection that would push a line past
> the original's own quantity. This backstop is **retained as a hardening bound only** — §12's
> server-side per-original quantity-cap lock remains the **sole authority**, and the backstop is
> explicitly single-device, best-effort, and blind to refunds settled through the legacy
> `/return` path. Its policy role is to close a full-refund-value cash-loss class for a
> single-terminal launch tenant *before any network round trip*, not to adjudicate refund
> eligibility. **Its tolerance is nil:** a malformed, unparseable, or non-canonical prior
> appended snapshot **fails closed** (refuses the refund) rather than skipping the row —
> skipping *undercounts* what has already been refunded, which makes the cap more permissive in
> exactly the case where its data is untrustworthy, i.e. the direction that loses money. Refund
> quantities in the snapshot are canonical positive decimal **strings** (§3.2's `bcabs`
> normalization, applied once at the refund boundary, at the canonical payload's own FROZEN
> quantity scale so the string the cap sums is byte-identical to the string the chain signs); a
> number-typed quantity is treated as non-canonical and refused, never coerced.
>
> **Second device-side bound (finding 19, same erratum).** Because the LEGACY `/return` path stays
> live on non-acknowledged terminals by ruled design (§9.2), refunds settled through it leave no
> `refund_intents` row and are invisible to the per-line backstop above. A second, RECEIPT-LEVEL,
> VALUE-based bound therefore also runs at `begin()`: the magnitude of every `local_refund_records`
> row for the ORIGINAL's receipt number, plus this attempt's own `Σ|line_total|`, must not exceed
> the original's **exact** total — taken from the SIGNED, byte-bound payload as
> `total − cash_rounding_adjustment` (the signed `total` is the ROUNDED figure, and a refund pays
> out the exact gross line amounts, so a rounded-DOWN original must not have its first full refund
> refused). It has its own typed refusal and i18n key, is coarser than the per-line cap by design
> (`local_refund_records` carries no per-line quantities and keys the original by receipt number),
> sees only refunds settled on THIS device, and — like the backstop above — fails closed on
> unreadable data. §12's server-side cap remains the sole cross-terminal authority for both.

### 4.5 Payout/print reconciliation — payout-confirmed-but-unprinted recovery, dispute money
effect (fold item 7)

**Print recovery, closed (was a bare nullable timestamp with no flow in Revision 3):** on app
start, any `refund_intents` row with `payout_confirmed_at IS NOT NULL AND printed_at IS NULL`
triggers an explicit reprint prompt in the same reconciliation screen §4.5 (kept from Revision 3)
already shows for payout confirmation — "This refund was confirmed but the receipt was never
printed — print it now?" The reprint reads the AVOIR data from the **`offline_receipts` row**
this same refund inserted (§7's new mechanism) via the existing `getOfflineReceiptForPrint()`
path (`apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts:66-68`, confirmed to already read
from local SQLite by `idempotency_key`, not a server call) — the refund's `offline_receipts` row
uses its own stable `idempotency_key` (the `refund_intents.id`, reused, §7.2) for exactly this
lookup. Confirming "Yes, print" sets `printed_at = now()`; "Skip" leaves it null and re-prompts
on next restart (never silently dropped).

**`payout_disputed_at` money effect, ruled (fold item 7's explicit requirement):** a disputed
payout (§4.5's "No / Not sure" branch, kept from Revision 3) does **not** change the fiscal
event's already-signed effect and does **not** exclude the refund from the device Z's expected-cash
subtraction (§7.3's sign convention) — the signed event is immutable chain truth regardless of
local reconciliation uncertainty, so the Z math reflects fiscal reality, not dispute state. What
the dispute changes is purely a **server-side evidence signal**: setting
`payout_disputed_at` triggers a new, small synced record — an `OPERATOR_APPROVAL_GRANTED`-adjacent
audit event (reusing the existing operator-evidence authoring pattern, §4.2) carrying the
refund's `refund_fiscal_event_id` and the disputing operator's identity — giving the finance team
a signed, server-side record that "the local device could not confirm this cash left the drawer"
for their own investigation, entirely separate from and non-blocking of the Z's own arithmetic.
**New `approval_scope` literal, `'payout_dispute_evidence'`, and its exact assertion-site scope
(errata T6):** four sites enumerate/assert the `approval_scope` value set today and all four gain
the new literal, in the manifest (§17): the TS `PosOverrideApprovalScope` union type
(`posOverrideAuthoring.ts:8-10`); the TS `assertApprovalScope()` runtime guard
(`posOverrideAuthoring.ts:60-68`); the PHP `OPERATOR_APPROVAL_GRANTED` payload's
`assertEnum($payload, 'approval_scope', [...])` (`FiscalPayloadConstraintValidator.php:580`); and
the PHP `OVERRIDE_VOID_OR_RETURN`-sibling payload's equivalent assertion
(`FiscalPayloadConstraintValidator.php:613`) — the new dispute-evidence event reuses
`OPERATOR_APPROVAL_GRANTED`'s existing shape rather than authoring a sixth `OVERRIDE_*` event
type, so only the approval-side (not override-side) enums need the new literal.

---

## 5. Model-1 compensation — server-observable evidence, class-dependent entries, idempotency
(fold item 5, ⚖️ orchestrator ruling, treasury C-3, Codex §4.4)

### 5.1 Why Revision 3's design was unbuildable

Codex found three fatal gaps: (1) the server write-off action read `refund_intents.payout_confirmed_at`
— a **device-local SQLite column**, never synced anywhere, that the server cannot observe at all;
(2) an ingress-quarantined event may have no `fiscal_event_projections` row, so routing every
compensation through `/dead-lettered-projections/{id}/write-off` left that class with no
addressable target; (3) there was no unique idempotency key tying one compensation to one
rejected event — a repeated POST could duplicate the GL entry and drawer adjustment.

### 5.2 ⚖️ Orchestrator ruling, implemented exactly

**Server-observable evidence only.** The compensation action **never** reads device-local
`payout_confirmed_at`. Payout evidence for the write-off decision is exclusively: (a) the
**signed refund payload's own `shift_id`** (already present, chain-immutable, server-readable the
instant the event syncs — no device round trip needed at write-off time), and (b) an explicit
**operator attestation** captured at the moment of the write-off action itself ("I have
reconciled the physical drawer for this shift and confirm cash left it for this refund") — a
required field on the write-off request, not an inference from any device state.

**One idempotent compensation record per rejected event.** New table
`fiscal_refund_compensations` (new migration, §17): `id` (UUID, PK), `fiscal_event_id` (the
`fiscal_events.id` of the **rejected refund event itself** — universally addressable regardless
of whether the rejection came from projection dead-letter or ingress quarantine, closing gap (2)
above), `compensation_class` (`'invalid_refund' | 'valid_unbooked'`, §5.3), `journal_entry_id`,
`repository_movement_id`, `operator_id`, `operator_attestation` (text), `created_at`. Idempotency
via a partial unique index, mirroring the repository's own established precedent for "one record
per source event" —
`journal_entries_treasury_transfer_source_unique` (`2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php:22-26`,
`CREATE UNIQUE INDEX ... ON journal_entries (source_type, source_id) WHERE source_type =
'treasury_transfer' AND status = 'posted'`): `CREATE UNIQUE INDEX
fiscal_refund_compensations_event_unique ON fiscal_refund_compensations (fiscal_event_id)`. A
repeated write-off POST for the same `fiscal_event_id` returns the existing record (`200`, not a
new one) rather than duplicating the entry — the same idempotent-hit shape
`TreasuryMovementServiceInterface::record()` already uses for its own idempotency key.

**Ingress quarantine gets an addressable target.** The compensation endpoint is re-keyed to
**`fiscal_event_id`**, not a `fiscal_event_projections` row ID: `POST
/fiscal/refund-compensations` with `{ fiscal_event_id, compensation_class, operator_attestation }`
— reachable for both a dead-lettered projection (resolve `fiscal_event_id` from the projection
row for display, but key the compensation by the event) and a quarantined ingress row (which
always has a stable `fiscal_events.id` even when it has no projection row at all).

**Repository balance moves through `TreasuryMovementServiceInterface`, in the same transaction as
the journal post** — never a raw `CashDrawerOperation` write outside that port. Concretely:
`GeneralLedgerService::postEntryNow()` posts the compensating journal entry (§5.3's shape) inside
a `DB::transaction()`; **inside the same transaction**, `TreasuryMovementServiceInterface::record()`
is called with a `MovementIntent` (**errata T5 — corrected**: `idempotencyKey()` is a **derived**
value, `"{sourceType}:{sourceId}:{idempotencyLeg}"` (`MovementIntent.php`'s own method), not a
caller-settable string as Revision 4 wrongly described — the intent is constructed with
`sourceType: MovementSourceType::FiscalEvent` (existing enum case, `'fiscal_event'`), `sourceId:
$fiscalEventId`, `idempotencyLeg: 'refund_writeoff'`, yielding the derived key
`fiscal_event:{fiscal_event_id}:refund_writeoff`. **Non-collision, stated explicitly:** this is
structurally distinct from `TreasuryReceiptBridge`'s own per-payment-leg movement keys,
`fiscal_event:{id}:payment:{i}` (same `sourceType`/`sourceId` shape, different `idempotencyLeg` —
`'refund_writeoff'` can never equal `'payment:{i}'` for any integer `i`), so a write-off movement
for a given fiscal event can never collide with that same event's own payment-leg movement, even
though both key off the identical `fiscal_event_id`) to move the cash repository's balance —
this is the "exact cash-drawer adjustment operation" Codex asked to be named: it is **not**
`CashDrawerService::recordPayout()`/`recordRefund()` (both are shift-scoped `CashDrawerOperation`
rows tied to the legacy per-shift cash-count model, and `recordPayout()` additionally requires
`assertCashDrawerApproval()` — a cashier-facing manager-PIN flow inappropriate for an
already-privileged, permission-gated administrative action) — it is the Treasury money-movement
spine's own port, the same one every other GL-adjacent repository mutation in the codebase is
required to converge on (`TreasuryMovementServiceInterface.php`'s own docblock: "the single write
port every treasury money-movement converges onto"). The journal post, the movement record, and
the `fiscal_refund_compensations` row insert are all **inside one `DB::transaction()`** — atomic,
stated explicitly (closing Codex's "does not require the journal be posted" and "does not freeze
atomicity" findings).

### 5.3 Class-dependent entry shape

- **`invalid_refund`** (the refund was genuinely wrong — quantity cap exceeded, approval evidence
  unresolved, or any other class where the event should never have been honored as a real refund):
  `Dr RefundWriteOff (new `SystemAccountPurpose` case, expense) / Cr Cash` — a genuine loss
  booking. `RefundWriteOff` is an **existing-file-modified** enum addition (not a new file, fold
  item 10) to `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`, alongside
  its exhaustive `label()`/`expectedAccountType()` match arms (both must be extended — an enum
  case without exhaustive-match coverage is a compile-time PHPStan error under this codebase's
  strictness rules, named explicitly in the manifest, §17).
- **`valid_unbooked`** (the refund was a genuine, correct refund, but a transient/config failure —
  purpose-account misconfiguration, ingress quarantine later resolved as non-tamper — prevented
  it from booking before the operator chose to write it off rather than wait for a
  `fiscal:retry-projections` fix): `Dr Revenue / Cr Cash` **using the existing `SalesReturn`
  purpose** (`SystemAccountPurpose::SalesReturn`, `'sales_return'`, GL account 709) — the **same
  reversal shape** a successfully-booked refund would have received via the normal path, because
  this class *is* a normal refund; only its booking was delayed by infrastructure, not its
  validity.

**Errata (revision 4.1, T1 — hard blocker, corrects a false premise carried since Revision 3):**
Revision 4 claimed `SalesReturn` was "already seeded in all three chart seeders for credit
notes." This is false, verified directly against the seeders: only
`GenericChartOfAccountsSeeder.php:196` seeds a 709 row with `system_purpose =>
SystemAccountPurpose::SalesReturn->value`. `TunisiaChartOfAccountsSeeder.php:277` and
`FranceChartOfAccountsSeeder.php:282` both create a 709 account row, but **neither sets
`system_purpose`** — for tenant #1 (a France or Tunisia tenant), `hasAccountForPurpose(SalesReturn)`
resolves to false, and the `valid_unbooked` class has **no usable account** at launch. This is
corrected, not merely noted: **both `SalesReturn` and `RefundWriteOff` are seeded** in this same
change.

**Seeding and provisioning (§17, fold item 5's remaining sub-findings, corrected for T1):**
- `RefundWriteOff` is added to all three chart seeders (`GenericChartOfAccountsSeeder.php`,
  `FranceChartOfAccountsSeeder.php`, `TunisiaChartOfAccountsSeeder.php`), each following the
  existing `PaymentToleranceIncome`/`PaymentToleranceExpense` seeding line shape exactly (e.g.
  `GenericChartOfAccountsSeeder.php:199-200`'s `['code' => ..., 'system_purpose' =>
  SystemAccountPurpose::PaymentToleranceIncome->value, 'is_system' => true]` pattern).
- `SalesReturn`'s existing 709 row in `TunisiaChartOfAccountsSeeder.php:277` and
  `FranceChartOfAccountsSeeder.php:282` gains the missing `'system_purpose' =>
  SystemAccountPurpose::SalesReturn->value` key — the account row already exists in both
  seeders; only the purpose mapping was absent. `GenericChartOfAccountsSeeder.php:196` is
  unchanged (already correct).
- **Errata 4.2 (T1 minor — account-type divergence, resolved by alignment, not exception):**
  both FR/TN 709 rows are currently typed `'type' => 'revenue'` (`FranceChartOfAccountsSeeder.php:282`,
  `TunisiaChartOfAccountsSeeder.php:277`), but `SystemAccountPurpose::expectedAccountType()`
  groups `SalesReturn` in its **Expense**-returning match arm (`SystemAccountPurpose.php:168-169`,
  alongside `PaymentToleranceExpense`/`SalesReturnsClearing`/`RealizedFxLoss` — same arm) —
  simply adding the purpose key without changing `'type'` would fail §17's seeder exhaustive-type
  test the moment it runs. **Resolved by aligning to the existing precedent already present in
  these same two files**, not by carving out an exception: each file's neighboring `7091`
  account (`'Remboursements clients — Virements bons d'achat'`, `SystemAccountPurpose::SalesReturnsClearing`)
  is **already** typed `'type' => 'expense'` (`FranceChartOfAccountsSeeder.php:287-288`,
  `TunisiaChartOfAccountsSeeder.php:282-283`) for the identical reason — a contra-revenue,
  class-7-numbered account that this codebase's `expectedAccountType()` model classifies as
  Expense. The 709 row's `'type'` is changed from `'revenue'` to `'expense'` in both files, in
  the same edit that adds the `system_purpose` key — consistent with the convention `7091`
  already established two lines away, not a new one-off.
- The backfill command (`BackfillRefundWriteOffAccountCommand`, §17) is **renamed**
  `BackfillRefundCompensationAccountsCommand` and covers **both** purposes —
  `RefundWriteOff` and `SalesReturn` — idempotently provisioning whichever of the two is missing
  for any already-existing tenant chart (including tenant #1's, if provisioned before this
  seeder change lands).
- **Errata 4.3 (treasury's closing fix — documentation-only): the backfill command writes ONLY
  the missing `system_purpose` key and must NEVER rewrite `type` on an existing account.** This
  mirrors the seeders' own established never-rewrite rule — `TunisiaChartOfAccountsSeeder.php:47-52`'s
  existing-row branch already documents the precedent exactly: *"the seeder never rewrites
  existing rows... Promote ONLY the `is_system` flag (never name/type/purpose — user edits stay
  untouched)."* The backfill command must follow the identical discipline for the same reason it
  applies to the seeder: an account's `type` directly drives
  `ProfitLossService::queryAccountBalances()`'s balance formula (`:149,218` — Revenue accounts
  aggregate `credit − debit`, Expense accounts aggregate `debit − credit`), so **retroactively**
  flipping a live account's `type` on an already-provisioned tenant would re-sign every
  historical journal line ever posted to that account the next time a P&L for a past period is
  (re-)run — silently rewriting a previously-published report's numbers, not merely fixing a
  chart-setup gap. **Consequence, stated explicitly and accepted, not hidden:** the §17 seeder
  fix (above) aligns `type` to `'expense'` only in the **seeder source** — a **fresh** FR/TN chart
  seeded from this point forward gets the correct, aligned type. An **already-provisioned** FR/TN
  tenant whose 709 account predates this change retains its **existing `'revenue'` type
  permanently** — the backfill command adds that tenant's missing `system_purpose` key (closing
  the `hasAccountForPurpose(SalesReturn)` gap so `valid_unbooked` compensation becomes bookable)
  but leaves `type` exactly as it already was on that installed account. This is a deliberate,
  accepted divergence between the installed base (purpose-provisioned, type-as-found) and fresh
  charts (purpose-and-type-aligned from creation) — not an inconsistency the backfill command is
  expected to close.
- **Both** the rollout preflight (§9's `EnableV4RefundAuthoringCommand`) and the write-off
  action's own precheck (`RefundCompensationController`, §5.2) call `hasAccountForPurpose()` for
  **both** `RefundWriteOff` and `SalesReturn` before proceeding — an account missing for either
  purpose blocks enablement (preflight) or that specific compensation class (precheck), never a
  silent partial capability.

**Permission.** New permission `fiscal.refunds.manage_dead_letters` is added to
`apps/api/database/seeders/RolesAndPermissionsSeeder.php`'s flat permission list (mirroring the
existing `'pos.process_returns'` line, `:327`) **and** granted to the same operations/management
role that already holds `pos.process_returns` (the role whose permission array includes
`'pos.process_returns'` at `:530`, alongside `pos.void_receipts`/`pos.view_reports` — the
existing precedent for "who administers POS corrections" in this codebase) — both the seeder
addition and the route's `can:` middleware are in §17's manifest.

---

## 6. Verifier repair — seal-discriminator backfill legality (fold item 6, ⚖️ orchestrator ruling,
Codex §4.5)

### 6.1 The conflict Codex found

The per-row `sealed_hash_algorithm` discriminator design (kept from Revision 3, correct in
direction) requires a backfill command to `UPDATE` existing, already-fiscalized `pos_receipts`
rows — but `prevent_receipt_modification()` (current live version,
`2026_05_26_100001_allow_pos_receipt_fk_cleanup.php:16-65`) rejects any `UPDATE` on a
`fiscal_status = 'fiscalized'` row that doesn't match one of its existing permitted branches
(`pending_seal→fiscalized`, `fiscalized→voided` with immutable-field equality, or the
customer-FK-nulling branch with immutable-field equality) — and Revision 3's own §10.4
simultaneously said no existing `pos_receipts` row may ever be touched, directly contradicting
the backfill it required in §6.

### 6.2 ⚖️ Orchestrator ruling, implemented exactly: one-time, trigger-permitted NULL→value
transition

**New migration** `apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`
— `CREATE OR REPLACE FUNCTION prevent_receipt_modification()` again (the repository's own
established pattern for evolving this trigger — three prior migrations have done exactly this:
`2026_01_08_190637`, `2026_05_01_000003`, `2026_05_26_100001`), preserving every existing branch
**verbatim** and adding exactly one new permitted branch, modeled precisely on the existing
customer-FK-nulling branch's structure (same `fiscal_status` unchanged, same
`IS NOT DISTINCT FROM` equality checks on every other immutable column):

```sql
IF OLD.fiscal_status = 'fiscalized'
   AND OLD.sealed_hash_algorithm IS NULL
   AND NEW.sealed_hash_algorithm IS NOT NULL
   AND NEW.fiscal_hash IS NOT DISTINCT FROM OLD.fiscal_hash
   AND NEW.receipt_number = OLD.receipt_number
   AND NEW.total = OLD.total
   AND NEW.subtotal = OLD.subtotal
   AND NEW.tax_amount = OLD.tax_amount
   AND NEW.chain_sequence IS NOT DISTINCT FROM OLD.chain_sequence
   AND NEW.posted_at = OLD.posted_at
   AND NEW.vat_breakdown_hash IS NOT DISTINCT FROM OLD.vat_breakdown_hash
   AND NEW.payment_methods_hash IS NOT DISTINCT FROM OLD.payment_methods_hash
   AND NEW.canonical_bytes IS NOT DISTINCT FROM OLD.canonical_bytes
   AND NEW.fiscal_event_id IS NOT DISTINCT FROM OLD.fiscal_event_id THEN
    RETURN NEW;
END IF;
```

This permits **exactly one** metadata-only transition (`NULL` → a value, never value → a
different value — the `OLD.sealed_hash_algorithm IS NULL` guard makes it structurally one-time
per row, not merely one-time by convention) on the discriminator column alone; every other fiscal
column remains exactly as immutable as it is today, and every other existing transition
(`pending_seal→fiscalized`, void, FK-nulling) is untouched.

**§10.4 (rollout's no-rewrite prohibition) is narrowed, not removed:** it now reads "never
rewrite, backfill, or re-derive any existing `fiscal_events` row, and never modify any
`pos_receipts` column **except** the one-time `sealed_hash_algorithm` transition this migration
explicitly permits" — the prohibition and the backfill coexist because the prohibition was always
about protecting fiscal *content* (hashes, totals, sequence, timestamps), and this transition
touches none of it, by the trigger's own enforcement, not merely by policy.

**Required test** (§17): a Postgres trigger regression test
(`PosReceiptImmutabilityTriggerSealedHashAlgorithmTest.php`) that (a) proves the new transition
succeeds exactly once per row, (b) proves a second attempt to change an already-set
`sealed_hash_algorithm` is rejected by the trigger (SQLSTATE `integrity_constraint_violation`),
and (c) proves every pre-existing immutability case (attempting to change `total`, deleting a
fiscalized row, etc.) still fails exactly as it does today — a true regression test, not merely a
new-behavior test.

### 6.3 NULL-handling gated on backfill completion (fiscal I-5)

Revision 3 ruled a post-backfill `NULL` value a hard verification failure. **Refined:** a new
boolean flag (a single row/setting, or a `terminals.sealed_hash_algorithm_backfill_completed_at`
timestamp — implementation detail for the code phase, but the *contract* is fixed here) governs
the verifier's `NULL` handling: **before** the backfill command has completed for a given
terminal's legacy rows, `NULL` is interpreted as `legacy_pipe_v1` (today's only possible value
for a pre-migration row, since `sealed_hash_algorithm` didn't exist before this feature — every
row that predates the column is, by construction, legacy-pipe-sealed) — this keeps
`verifyLegacyArm()` correctly green for every terminal that hasn't been backfilled yet, avoiding
a false-failure storm the moment the column is added and before the backfill command has had a
chance to run. **After** backfill completion is recorded for that terminal, any remaining `NULL`
row is a genuine anomaly (the backfill command explicitly logs-and-skips rows where neither/both
algorithms matched, §6 kept from Revision 3) and the verifier fails closed on it, exactly as
Revision 3 specified.

### 6.4 Model changes (fold item 10)

`apps/api/app/Modules/POS/Domain/Receipt.php` — `casts()` method (Laravel 11+ style, confirmed
current pattern at `:235-271`) gains an entry alongside the existing `'receipt_type' =>
ReceiptType::class,`-style rows; `sealed_hash_algorithm` is a plain nullable string, needing no
cast entry beyond ensuring it is in `$fillable`/query-selectable — named explicitly in the
manifest so it is not silently omitted from the model the way it was from Revision 3's manifest.
`apps/api/app/Modules/POS/Domain/Terminal.php` — `casts()` (confirmed current pattern at
`:118-133`) gains `'v4_refund_authoring_enabled' => 'boolean'` alongside the existing
`'is_active' => 'boolean',`-style entry.

---

## 7. `offline_receipts` ruling and the expected-cash filter contract (fold item 2 — treasury C-2,
orchestrator ruling, superseding Revision 3's §7 entirely)

### 7.1 Ruling

**The v4 refund DOES insert a row into `offline_receipts`.** This is not optional and not merely
"also possible" — it is required because the AVOIR must be reprintable, and today's only reprint
mechanism, `getOfflineReceiptForPrint()` (`apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts:66-68`,
confirmed reading `SELECT * FROM offline_receipts WHERE idempotency_key = $1` from local SQLite,
never a server call), has no other data source for a refund. This ruling **replaces** Revision
3's `refund_intents`-only design for Z/expected-cash purposes — `refund_intents` (§4.4) still
exists, but its job narrows to durable pre-signing intent/approval/payout-reconciliation state,
not to being the Z-math source of truth.

### 7.2 Schema change and insert

**New column on `offline_receipts`** (same SQLite migration that creates `refund_intents`, §17,
so both land in one device migration version): `receipt_kind TEXT NOT NULL DEFAULT 'sale' CHECK
(receipt_kind IN ('sale', 'refund'))`. Every existing row implicitly backfills to `'sale'` (the
default), so this is a zero-behavior-change migration for every row written before this feature.

**The v4 refund's write-gate transaction** (§4.3, unchanged append-first ordering) gains a third
write, after the fiscal-event append and the `refund_intents` update, still inside the same
transaction: an `INSERT INTO offline_receipts (...)` row with `receipt_kind = 'refund'`,
`idempotency_key = refund_intents.id` (the intent's own stable UUID, reused — this is what makes
`getOfflineReceiptForPrint()`'s existing idempotency-key lookup work unmodified for a refund
row, §4.5), and `lines`/`total`/`subtotal`/`tax_amount` stored in the **same negative-signed
shape** the AVOIR was originally built and printed from (`hydrateFromReceipt.ts`'s negative
`CartItem`s, unchanged, §3.2) — this is a deliberate, stated **difference** from the positive-
magnitude signed fiscal payload (§3.1): the `fiscal_events` row is the canonical, positive-
magnitude, signed record; the `offline_receipts` row is the local, negative-signed, print/report
representation, exactly mirroring how the legacy server-side `pos_receipts.total` for a return is
**also** stored negative (`ReceiptReturnRefactorTest.php`'s `test_return_receipt_type_is_return_and_totals_are_negative`)
while the v3+ canonical fiscal payload is positive-magnitude — two coexisting sign conventions
for two different layers, already an established pattern in this codebase, not a new one.

### 7.2a `offline_receipts` refund row — complete contract (errata T2, treated with the weight
this lane exists to close: this is the exact silent-drop class §7 was written to close)

Revision 4 specified the insert's sign convention (above) but not its full column contract or
its sync-completion lifecycle. Both are load-bearing — an unspecified `hash_sequence` silently
excludes the row from every Z report ever run for that shift, which is precisely the class of
bug this lane exists to close, not a lesser wording gap.

**Status lifecycle — the bug and its fix.** `offline_receipts.status` starts `'pending'` for
every row, sale or refund (`receiptService.ts:563`'s convention, reused unchanged). For a
**sale**, the sync-completion flip is
`syncService.ts:382-390`: once the fiscal event's push succeeds, `updateReceiptStatus(db,
event.source_event_id, 'synced')` fires **only when `event.source_event_class ===
'offline_receipts'`** (`:388`, exact condition, confirmed) — and a sale's fiscal event is
authored with exactly that `source_event_class`, `source_event_id` equal to the
`offline_receipts` row's own primary key. **A refund's fiscal event is authored with
`source_event_class: 'refund_intents'`** (§4.3, unchanged — this is what makes the *append*
idempotent on the intent's own stable ID) — so today's condition at `:388` **never matches a
refund's sync**, and the refund's `offline_receipts` row would stay `'pending'` forever: the
device's sync-status badge counts it as perpetually outstanding, and any purge/retention routine
that only collects `'synced'`/`'error'` rows never reclaims it.

**Fix:** a second, parallel flip branch in the same `syncService.ts` success block (`:382-390`),
keyed on the refund's `source_event_class` and resolving the **linked** `offline_receipts` row
via the idempotency-key relationship §7.2 establishes (`offline_receipts.idempotency_key =
refund_intents.id = event.source_event_id`), not the row's own primary key:

```ts
if (event.source_event_class === 'offline_receipts' && event.source_event_id !== null) {
  await updateReceiptStatus(db, event.source_event_id, 'synced'); // unchanged, sales
} else if (event.source_event_class === 'refund_intents' && event.source_event_id !== null) {
  await updateReceiptStatusByIdempotencyKey(db, event.source_event_id, 'synced'); // new, refunds
}
```

New function `updateReceiptStatusByIdempotencyKey(db, idempotencyKey, status)` —
`UPDATE offline_receipts SET status = $1, synced_at = datetime('now'), sync_error = NULL WHERE
idempotency_key = $2` (**errata 4.2 — the `sync_error = NULL` clause added**, mirroring
`updateReceiptStatus`'s existing `'synced'`-branch column-set exactly, including that clause,
`offlineReceiptRepository.ts:200` — not merely its `status`/`synced_at` columns; a prior sync
attempt could otherwise leave a stale `sync_error` string on a row that has since synced
successfully) — keyed by `idempotency_key` instead of `id` — new file location
`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts`
(the existing home of `updateReceiptStatus`, extended, not a new repository file).

**Full column sign/source contract for the refund's `offline_receipts` insert**, every column
named:

| Column | Value / source |
|---|---|
| `id` | Fresh UUID, generated at insert time — **distinct** from `idempotency_key` (which is `refund_intents.id`) |
| `idempotency_key` | `refund_intents.id` (§7.2, print-lookup key) |
| `receipt_number` | The refund's own printed receipt number (device-generated, same numbering convention a sale uses — NOT NULL, required for print/display; never borrows the original sale's number) |
| `terminal_id`, `terminal_code` | The refunding terminal's own identity (same-device only, §0/§13) |
| `operator_id`, `operator_name` | The cashier who authored the refund (NOT NULL — sourced from `PosOverrideContext.cashierUserId`/session identity, same as a sale's) |
| `lines` | Negative-signed `OfflineReceiptLine[]` JSON (§7.2, unchanged) |
| `subtotal`, `tax_amount`, `total` | Negative magnitude (§7.2's sign convention) |
| `discount_amount` | Negative-signed line-level discount total (mirrors the negative `lines` convention — §3.5 already refuses any refund whose *original* carried a non-zero `transaction_discount_amount`, so this column reflects only the sum of the refunded lines' own per-line discounts, never a transaction-level figure) |
| `currency` | The original's currency, unchanged |
| `fiscal_hash`, `previous_hash` | NOT NULL — populated from the just-appended v4 fiscal event's own `current_hash`/`previous_hash` (`engine.append()`'s return value, available at this point in the write-gate transaction per §4.3's append-first ordering) — the same values a sale's `offline_receipts` row already stores from its own append result |
| **`hash_sequence`** | **NOT NULL — the just-appended v4 fiscal event's own `sequence_number`** (same append-result source as `fiscal_hash` above). This is the column the Z query windows on (`zReportService.ts:176-183`'s `hash_sequence > anchor.opening_hash_sequence` predicate) — an unset or wrongly-derived value here silently drops the refund from every Z report for the shift, which is the exact failure class this section exists to close. Because the refund event and a sale event share the **same** `'operational'` chain context and its single monotonic `sequence_number` counter (§1, kept), a refund's `hash_sequence` sorts and windows correctly alongside sale rows using the identical predicate — no separate windowing logic is needed. |
| `transaction_discount_amount`, `transaction_discount_reason` | `NULL` / `NULL` — §3.5 guarantees this is always the case (a non-zero-original-discount refund never reaches this insert) |
| `tendered_amount`, `change_due` | `NULL` / `NULL` — a refund has no tender/change concept (the payout is the entire `total`, not a tendered-minus-change computation); explicitly `NULL`, not `'0'`, so §7.3/§7.2b's per-row branching can distinguish "not applicable" from "zero" |
| `payment_method_id`, `payment_repository_id` | NOT NULL — the tenant's cash method/repository (§3.5's single-cash-leg contract), resolved the same way a cash sale resolves them |
| `payments_json` | **Positive-magnitude** `[{ method_code: 'CASH', amount: <positive total>, ... }]` — stated explicitly: unlike `lines`/`total`/`subtotal` (negative, §7.2), `payments_json` mirrors the **signed fiscal payload's** `payments[]` convention (§3.1, always non-negative) because `payments_json` is consumed by `aggregateReportData`'s payment-method breakdown (§7.3) as a **magnitude** to be added or subtracted depending on `receipt_kind`, not as a pre-signed delta — storing it negative would double-negate against §7.3's subtraction branch |
| `cash_rounding_adjustment` | Signed, mirrors the fiscal payload's `cash_rounding_adjustment` (§5's E1 rounding, cash-only-gated) — canonical zero when unrounded, never `NULL` (matches the mandatory-not-optional rounding-key contract this spec already establishes for the fiscal payload) |
| `tolerance_shortfall` | `NULL` — no tender-tolerance concept applies to a refund payout (tolerance is a sale-side over/under-tender concept only) |
| `status` | `'pending'` (this section's fix, above) |
| `synced_at`, `sync_error` | `NULL` at insert, set by the sync-completion flip (this section's fix) |
| `fiscal_schema_version` | `4` (the refund's own event version, not the terminal's schema version — mirrors how a sale row stores its own authored version) |
| `is_training` | `0` — §3.7's training-original refusal (§3.7, T4 below) guarantees a refund is never authored against a training original, so this is unconditionally `0`, never inherited from the original |
| `canonical_bytes` | The just-appended event's own `canonical_bytes` (same append-result source as `fiscal_hash`/`hash_sequence` above) |

### 7.3 `aggregateReportData` filter contract — exact routing

`zReportService.ts`'s existing receipt query (`:176-193`, unchanged — it already selects
unfiltered-by-kind from `offline_receipts` for the terminal/shift window with `is_training = 0`)
now naturally returns **both** sale and refund rows in one result set, since refund rows now live
in the same table. **`aggregateReportData()`'s signature loses its fourth parameter** —
`refundRecords: LocalRefundRecord[]` is removed; the function now derives refund totals from the
same `receipts` array it already receives, branching per row on `receipt_kind`:

- **`receipt_kind === 'sale'`** (today's entire loop body, `:791-858`, unchanged): contributes to
  `salesCount`, `grossSales`, `netSales`, `taxAmount`, the VAT breakdown (additive), and the
  payment-method breakdown (additive — a sale's cash leg **adds** to `paymentByType.get('CASH')`).
- **`receipt_kind === 'refund'`** (new branch): does **not** touch `salesCount`/`grossSales`/
  `netSales`/`taxAmount` — those remain sale-only aggregates, matching the existing server-pinned
  semantics comment kept from today's code (`refunds_amount` is tracked as its own field,
  `:769-785`, never folded into gross/net sales). Instead: `refundsCount++`;
  `refundsAmount = bcadd(refundsAmount, bcabs(receipt.total))` (magnitude — `receipt.total` is
  already negative per §7.2's storage convention, so `bcabs()` recovers the existing positive-
  magnitude `refunds_amount` semantics unchanged); the VAT breakdown is **subtracted** (each
  line's net/vat/gross, recovered via `bcabs()` from the negative-stored `lines` JSON, is
  subtracted from the running per-rate totals — a refund reverses VAT collected); the payment-
  method breakdown is **subtracted** (`paymentByType.get('CASH')`'s `amount` is reduced by the
  refund's cash magnitude, `count` still increments by one transaction) — this is what makes
  `payment_methods` in the returned `ZReportData` correctly show net (sales-minus-refunds) cash,
  not gross.

**`expected_cash` formula simplification, exact:** because the payment-method breakdown (above)
now already nets refunds against sales, the standalone `cashRefundImpact` term
(`zReportService.ts:229-232`, today summed independently from `local_refund_records` via
`getRefundRecordsForShift`) is **removed** — `expectedCash`'s formula (`:254-262`) drops the
`bcsub(..., cashRefundImpact, decimals)` term entirely, because the `cashSales` value it already
reads (`reportData.payment_methods.find(p => p.payment_type === 'CASH')`, `:227-228`) is now
**already net of refunds** by construction of the new per-row branching above. This is a genuine
simplification, not merely a rename: one code path computes cash correctly instead of two paths
(sale aggregation + separate refund-impact subtraction) that had to be kept in sync by hand.

`getRefundRecordsForShift`/`local_refund_records`/`localRefundRecordRepository.ts` are deleted
entirely (kept from Revision 3's retirement decision, now with a concrete replacement mechanism
rather than an under-specified one).

### 7.3a `endOfDayPreview.ts` needs its own explicit refund branch (errata T3)

`endOfDayPreview.ts` is **not** a consumer of `aggregateReportData()` — it runs its **own**,
structurally separate SQL query (`endOfDayPreview.ts:172-173`, selecting `id, total, subtotal,
tax_amount, payments_json, lines, created_at, change_due, payment_method_id,
cash_rounding_adjustment, tolerance_shortfall` from `offline_receipts`) and its **own** per-row
loop (`:216-280`) computing `toleranceTotal`/`roundingTotal`/`cashChangeDueSum`, then
**separately** calls `getRefundRecordsForShift(db, shiftId)` (`:354-355`) to fold refund
magnitude in afterward. §7.3's `aggregateReportData()` fix does **not** touch any of this — it is
a different function in a different file. Left as-is, this file would keep reading the
now-deleted `local_refund_records` table and throw (or silently return stale/empty data,
depending on the deletion's exact shape) the moment §7 lands.

**Fix, mirroring §7.3's contract exactly, in this file's own query and loop:**
- The query at `:172-173` adds `receipt_kind` to its `SELECT` list (no other column changes —
  every column §7.2a names is already either selected here or not needed by this preview).
- `toleranceTotal`/`roundingTotal`/`cashChangeDueSum` (`:216-280`): `'sale'` rows keep today's
  exact behavior; `'refund'` rows **subtract** from `roundingTotal` via the row's own (signed,
  §7.2a) `cash_rounding_adjustment` — `tolerance_shortfall` is always `NULL` on a refund row
  (§7.2a's table), so a refund row never contributes to `toleranceTotal` at all, and
  `cashChangeDueSum` is **not** touched by a refund row either (`change_due` is `NULL` per
  §7.2a — a refund has no change concept).
- The separate `getRefundRecordsForShift` call at `:354-357` and its `cashRefundImpact`
  accumulator are **deleted** — refund magnitude now arrives entirely through the per-row loop's
  new branches (below), not a second pass.

**Errata 4.2 (treasury micro-verify MISS — closing the +2× expected-cash error the deletion
above would otherwise reintroduce, mirroring §7.3's payment-method routing exactly):**

- **`grossSales`/`netSales`/`taxAmount` (`:212-214`) are sale-only**, matching §7.3's already-
  established sale-only semantics for the signed Z: the accumulation only runs for `'sale'` rows;
  a `'refund'` row is skipped entirely for these three totals (never added, never subtracted —
  refunds are their own tracked figure, not folded into gross/net sales, on either file).
- **`perMethod`/`cashTenderedSum` (`:246-273`) get an explicit `receipt_kind` branch.**
  `payments_json` is a **positive magnitude on every row, sale or refund** (§7.2a's table —
  stated once there, binding here too). Left un-branched, deleting `cashRefundImpact` (above)
  would mean a refund's positive `payments_json` amount is simply **added** to `perMethod`/
  `cashTenderedSum` exactly like a sale's, and the separate `expected_cash` subtraction that used
  to compensate for it (`cashRefundImpact`, now deleted) is gone — a refund payout would inflate
  expected cash by its own magnitude instead of reducing it, a **+2× error** against the true
  drawer position (the same class of bug §7.3 closed in `zReportService.ts`, now reappearing here
  because this file's loop was never touched). Fix, per row inside the existing `for (const p of
  payments)` loop (`:253-273`): for `receipt_kind === 'sale'`, unchanged (`existing.total_amount
  = bcadd(...)`, `cashTenderedSum = bcadd(...)`); for `receipt_kind === 'refund'`,
  **`existing.total_amount = bcsub(existing.total_amount, p.amount)`** and, when `key === 'CASH'`,
  **`cashTenderedSum = bcsub(cashTenderedSum, p.amount)`** — subtracting the positive magnitude
  instead of adding it, mirroring §7.3's `paymentByType` subtraction branch exactly. The
  no-`payments_json` legacy-fallback branch (`:282-304`) is unreachable for a refund row (§7.2a's
  contract always populates `payments_json` on a refund insert), so it needs no `receipt_kind`
  branch of its own — stated explicitly so this isn't mistaken for an oversight.
- **`expectedCash`'s formula (`:359-362`)** now needs no `cashRefundImpact` term at all (it is
  deleted, above) — `cashTenderedSum` is already net of refunds by construction of the branch
  just described, exactly mirroring §7.3's `expected_cash` simplification in `zReportService.ts`.

**VAT-breakdown convention — two files, two conventions, one stated equivalence (per this
errata's requirement that the two files not carry an unstated divergence):** `zReportService.ts`'s
§7.3 fix recovers each refund line's magnitude via `bcabs()` and **subtracts** it from the
running per-rate VAT totals ("bcabs-then-subtract"). `endOfDayPreview.ts`'s own VAT loop
(`:231-244`) is left **unchanged** by this fix — it keeps unconditionally `bcadd`-ing each line's
`line_total`/`tax_amount` straight from the row's `lines` JSON, with no `receipt_kind` branch and
no `bcabs()`. This is deliberate, not an inconsistency: because a refund row's `lines` JSON is
**negative**-signed (§7.2), plain addition of a negative value already produces the identical net
result as `zReportService.ts`'s abs-then-subtract — "additive-over-negative-lines" and
"bcabs-then-subtract" are two different implementations of the same arithmetic outcome, one
relying on the stored sign, the other normalizing it explicitly. Both are correct; neither needs
to change to match the other, and no `receipt_kind` branch is needed in this specific loop.

### 7.3b `productSalesAggregateRepository.ts` — net-units ruling (errata T7, minor)

`aggregateProductSales()` (`productSalesAggregateRepository.ts:34-73`) sums **raw** (not
absolute-valued) `quantity` from every non-training, non-voided `offline_receipts.lines` row
within its window (`:38-44`, `:65-69` — `counts.set(rawId, (counts.get(rawId) ?? 0) + qty)`, no
`ABS()`/`Math.abs()` anywhere in the accumulation) — and its query has **no `receipt_kind`
filter today**, so it already includes every row in the table by default. **Ruling: this
requires zero code change.** Once §7.2's refund rows exist with negative-signed `lines` quantities,
this function **automatically and correctly** nets refunded units against sold units — a
refunded unit's negative quantity subtracts from the running count exactly as intended for a
"most sold" projection. This is stated as an **explicit, intentional ruling**, not left to be
silently correct by accident: a future maintainer must not "fix" this by adding a
`receipt_kind = 'sale'` filter or an `ABS()` wrap, either of which would **break** net-units
correctness by counting refunded units as still-sold. **New test** (§17):
`productSalesAggregateRepository.test.ts` — extended with a case seeding one sale row (qty 5) and
one refund row (qty −2) for the same product, asserting the aggregate returns net 3, not gross 5.

### 7.4 Sign convention statement (kept from Revision 3, restated against the new mechanism)

The refund's contribution to expected cash is computed in the same frame as a sale's cash total —
stored as a negative magnitude at the `offline_receipts` row level (§7.2), recovered via `bcabs()`
and **subtracted** at the aggregation level (§7.3) — one convention, stated once, applied
consistently. `TreasuryReceiptBridge::postCashRoundingEntry()`'s existing `$isRefund`-branched GL
direction (unchanged, §7.5) is a structurally separate mechanism operating on the positive-
magnitude signed payload, not the same code path — both are internally consistent with §3.1's
sign contract even though they touch different representations of the same event.

### 7.5 GL — unchanged, still correct (kept from Revision 3, confirmed CLOSED by treasury r3)

### 7.6 `CashDrawerService` — ticketed (fold item 10's citation fix, §3.7)

Unchanged finding, corrected citation: `docs/superpowers/tickets/2026-07-31-cashdrawer-v3-expected-cash-blind.md`
(confirmed to exist).

### 7.7 v2/v3 Z sign divergence (unchanged, kept)

---

## 8. VOID (unchanged — Codex r3 confirmed "mooted/closed for launch scope"; one mechanical fix)

Fail-closed at both boundaries (device `eventVersionFor` throw, server validator rejection),
unchanged. **Mechanical fix (fold item 10):** the server-side rejection is enforced in
`FiscalPayloadConstraintValidator.php`'s version-4 constraint check, not a second registry
branch — restated consistently with §2's correction, removing any remaining implication of a
registry-level VOID special-case.

---

## 9. Rollout — capability state, two-phase enable/acknowledge, single-terminal preflight (fold
item 4, ⚖️ orchestrator ruling, fiscal C-4, Codex §4.7)

### 9.1 The mechanism Revision 3 got wrong

`pullTerminalState`'s `upsertTerminalState` call is guarded by `FiscalRegressionError`
(`terminalStateRepository.ts`, kept finding from Revision 3): when the incoming (server-reported)
`hash_sequence` is behind the device's local value, the **entire** row upsert is rejected, not
just the regressing column. For a v3+ terminal, the server's legacy `current_sequence` counter is
effectively frozen (the v3 sale/refund path never advances it, §1), while nothing resets the
device's own locally-tracked value downward between pulls — so, in the terminal's steady state,
this guard can reject the **whole** `upsertTerminalState` call on every subsequent pull. Bundling
a brand-new field like `v4_refund_authoring_enabled` into that same guarded upsert means the flag
could never actually reach the device after its first successful pull.

**Fix — a dedicated, non-regressive setter, mirroring the existing precedent exactly:**
`setShiftNumberSeed()` (`terminalStateRepository.ts:169-181`, confirmed existing pattern: `UPDATE
terminal_state SET shift_number_seed = MAX(shift_number_seed, $1) WHERE terminal_id = $2` — a
plain, guard-independent `UPDATE`, called unconditionally in `pullTerminalState`'s success path
**and** again inside its `FiscalRegressionError` catch branch, `syncService.ts`, confirmed) is the
exact template. New `setV4RefundAuthoringEnabled(db: Database, terminalId: string, enabled:
boolean): Promise<void>` — a plain `UPDATE terminal_state SET v4_refund_authoring_enabled = $1
WHERE terminal_id = $2` (no `MAX`/monotonicity needed — a boolean flag, not a counter) — called
in **both** branches of `pullTerminalState`, exactly where `setShiftNumberSeed` already is,
guaranteeing the capability flag reaches the device even on a pull that fails the main
regression-guarded upsert.

### 9.2 Real dispatch seam (fold item 4, fiscal C-4)

The capability check is **not** in `cartClassification.ts` (a pure classifier with no I/O,
confirmed) — the actual dispatch from a classified `'start-refund'` decision happens in
`HomePage.tsx` (`:1265-1330` region, confirmed as the real call site for both `handlePayCash`/
`handleAdvancedPayments`'s Pay-button interception). The capability check reads the local
`terminal_state.v4_refund_authoring_enabled` column (via a new, small
`terminalStateRepository.ts` read function alongside the existing state reads) at this exact
dispatch point — before the refund flow proceeds to draft a `refund_intents` row — showing the
same typed refusal copy §9.4 defines if the capability isn't yet locally known to be enabled.

### 9.3 ⚖️ Two-phase enable/acknowledge protocol

**Phase 1 — server offers.** An operator action (§9.5's rollout step) sets
`pos_terminals.v4_refund_authoring_enabled = true` server-side. This does **not** yet activate
§9.1's legacy-endpoint guard (`LegacyCorrectionGuard`, kept from Revision 3) for that specific
terminal.

**Phase 2 — device acknowledges.** On its next successful pull, the device receives the `true`
flag via §9.1's dedicated setter, writes it locally, and — as part of the **same** sync round —
sends back an explicit acknowledgement (a small new sync call, or a field on an existing
periodic sync payload; exact wire shape is a code-phase choice, but the contract is fixed here:
it must be a distinct, explicit signal, not inferred from "the device pulled successfully," since
a pull can succeed while `setV4RefundAuthoringEnabled` still lands via the fallback branch during
a period the main upsert is regression-blocked). The server records this as
`pos_terminals.v4_refund_authoring_acknowledged_at`.

**`LegacyCorrectionGuard` (§9.1) is conditioned on acknowledgement, not merely on
`fiscal_schema_version >= 3`.** The guard fires only when
`v4_refund_authoring_acknowledged_at IS NOT NULL` for that terminal. **Stated honestly, not
hidden:** this means a terminal that has been flagged server-side but has not yet synced/
acknowledged keeps using the **legacy** refund endpoint in the interim — reproducing §1's
original chain-corruption failure mode for that window, exactly as it existed before this
feature shipped. This is a deliberate, bounded trade-off: an **unconditional** guard risks a
worse outcome (zero refund capability at all on a stale/offline device, an outright launch-day
outage), while the acknowledgement-conditioned guard is **never worse than pre-launch reality**
for a not-yet-acknowledged terminal and **strictly better** the moment it acknowledges. For
tenant #1's single, actively-used terminal, this window is expected to be one ordinary sync
cycle, not an extended exposure.

### 9.4 Single-DEVICE-terminal preflight (⚖️ ruling, Codex §4.7)

Before Phase 1 (§9.3) can be triggered for any tenant, a new preflight check — part of §9.5's
rollout command — queries active `TerminalType::Device` terminals for that tenant's company and
**refuses** (typed error, operator-facing) if the count is not exactly one. This directly
addresses Codex's finding that D1 still permits terminal creation through multiple endpoints
(`TerminalController.php`) and does not itself enforce the single-terminal premise — the premise
is now a **provisioning-time-independent, enablement-time-enforced** guarantee: even if a second
terminal is later created for tenant #1, the capability cannot be (re-)enabled while more than
one active device terminal exists, and — stated as the explicit consequence, not left implicit —
**provisioning a second active device terminal for a tenant whose capability is already
acknowledged is an operator action this spec does not gate**; closing that gap (auto-disabling
refund authoring the moment a second terminal appears) is deferred to §16.6, named as a real gap
rather than silently assumed away.

**Typed refusal copy correction (fold item 10):** the guard's error message must **not** say "use
the legacy path" — per §9.1's design, the legacy path is itself 409-blocked once acknowledgement
completes; telling a cashier to "use the legacy path" would describe a dead end. The exact copy
is: *"Refunds are temporarily unavailable on this terminal — contact support"* (matching §9.4's
existing kept wording elsewhere), never a "try the other path" instruction.

### 9.5 Rollout sequence (updated)

1. Server-first: §2's payload contract, §6's verifier repair + backfill, §3's projector policy/
   evidence logic, §5's compensation infrastructure (including the account-provisioning
   preflight, §5.3) — all shipped with `v4_refund_authoring_enabled = false` everywhere.
2. Disabled device rollout — new build ships (§9.2's local gate keeps it inert).
3. §9.4's single-device-terminal preflight, then §9.3 Phase 1 (server offer) for tenant #1's one
   terminal.
4. Phase 2 (device acknowledgement) completes on the device's next sync; `LegacyCorrectionGuard`
   activates for that terminal at that moment, not before.
5. Cloned-staging tests (§1.1) before any production terminal's Phase 1, covering both
   `current_sequence` topologies and an explicit offline/stale-device test of the Phase-1-without-
   Phase-2-yet window (§9.3's stated trade-off, tested not merely asserted).
6. Absolute prohibitions (kept, restated per §6.2's narrowing): never rewrite/backfill any
   `fiscal_events` row; never modify any `pos_receipts` column except the one-time
   `sealed_hash_algorithm` transition (§6.2); never reset or copy server counters into a device's
   `fiscal_event_*` chain-head columns (the confirmed-conditional `FiscalRegressionError` guard,
   §9.1, is not to be weakened or bypassed for those columns — only the new, unrelated capability
   flag gets its own guard-independent setter).

### 9.6 v2-original limitation, tenant-fact honesty (fold item 10; errata F4, X3)

§9's legacy-endpoint guard blast radius for v2 originals on any cutover terminal (kept from
Revision 3) is restated with the "tenant #1 originals are always cash" claim **removed** — the
merged D1 launch-contract test proves an active CASH tender **exists** for a fresh tenant, not
that every eligible original a cashier might attempt to refund actually used cash. The cash-only
launch payload (§3) simply cannot represent a refund of an original that wasn't cash-tendered —
such an attempt is refused by the same typed mechanism as any other unsupported destination, not
a scenario this spec claims cannot occur.

**D1 blast radius (errata F4):** every terminal created **after** D1's merge defaults to
`fiscal_schema_version = 3` at creation (D1's provisioning-time guarantee, unconditional across
its terminal-creation paths — the three `TerminalController.php` sites named in §15) — so this
launch's legacy-guard blast radius (§9.4) is **not** limited to explicitly-cutover terminals: **any**
terminal provisioned post-D1, tenant #1's or otherwise, is v3 from birth and therefore in scope
for the guard the moment it processes its first return, whether or not it was ever run through
`FiscalSchemaCutoverService`. The v2-original limitation (§9's blast radius) is broader than "cutover
terminals only" — it is every terminal, cutover or born-v3, that has any v2-sealed original in
its history; a born-v3 terminal simply has zero such originals by construction, which is a
narrower, D1-provisioning-time fact, not a v4-launch-time one.

**X3 — v3-from-birth is a deployment check, not a repository-provable fact:** whether tenant #1's
*specific* terminal was actually provisioned through the post-D1 path (and therefore genuinely
has no v2 history) is not something this specification can establish by reading code — it is a
fact about how tenant #1 was actually deployed, which the rollout preflight (§9.5's
`EnableV4RefundAuthoringCommand`) must **verify at enablement time** (confirm the target
terminal's `fiscal_schema_version` was never `2`, or equivalently that it has zero
`fiscal_event_id IS NULL` fiscalized receipts) rather than this document asserting it as an
established repository fact.

---

## 10. `store_voucher` — excluded from the enum plus the proven-unreachable gate (unchanged
mechanism, one wording fix)

Unchanged from Revision 3: excluded from the launch enum (§3.4), plus the defense-in-depth
`redeemVouchers()` gate. **Wording fix (fold item 10 — "voucher/VOID gate keyed on
`ReceiptType::Sale` form"):** the gate is restated to match the **exact conditional shape** of
the existing `earnLoyaltyPoints` precedent it mirrors (`$receiptType !== ReceiptType::Sale`,
`PosCoreReceiptProjection.php:987`) rather than a differently-shaped `invoice_type_code !==
'REFUND'` check — both are logically equivalent for this launch (a REFUND event always resolves
to `ReceiptType::Return`, never `Sale`), but keying the new gate on the **same enum and the same
comparison form** as the sibling gate it is modeled on keeps the two defense-in-depth checks
visually and structurally consistent for a future reader, rather than introducing a second,
differently-worded convention for the identical purpose.

**Additional wording fix:** "v3-REFUND payloads rejected outright (no legitimate producer
exists)" — restated for precision: an `event_version = 3` payload with `invoice_type_code =
'REFUND'` has no legitimate authoring path (only `event_version = 4` REFUND payloads are ever
device-authored, §2), so the validator's existing per-version constraint checks reject this
combination as a structural mismatch, not a new rule requiring new code — it is a **consequence**
of the version-aware validation already specified in §2/§3, restated here so it is not mistaken
for an unaddressed gap.

---

## 11. Cross-terminal lookup — deferred (unchanged, §16)

## 12. Quantity cap — reverse lookup on existing schema, sign normalization, mixed-population
test (fold item 3, fiscal C-3)

Unchanged core mechanism from Revision 3: `idx_pos_receipts_original_receipt` and
`pos_receipt_lines.original_line_id` are existing schema elements, reused; the `FOR UPDATE` lock
+ aggregate-quantity query; PG-mode concurrency test.

**Sign normalization, closed (fiscal C-3):** the reverse-lookup query aggregates
`pos_receipt_lines.quantity` for prior return rows — but the **legacy** return-line quantity
convention is negative (kept, confirmed via `ReceiptReturnService.php:955-975`), while the v4
projector, per §3.1's positive-magnitude contract, writes **positive** quantities on the lines it
creates. A cap query that sums both without normalizing would silently miscompute the
already-refunded total the moment both legacy and v4 refunds exist against the same original (the
mixed-legacy-population case). **Fix:** the aggregation query wraps every summed quantity in
`ABS()` (`SUM(ABS(prl.quantity))`), so both sign conventions contribute their true magnitude
regardless of which path wrote them.

**New mixed-legacy-population test** (§17): `PosCoreReceiptProjectionRefundQuantityCapMixedLegacyTest.php`
— seeds an original with **one** prior legacy-negative return line and **one** prior v4-positive
return line against the same original line, then attempts a third refund that would exceed the
cumulative cap only when both prior refunds are correctly counted — proving the `ABS()`
normalization closes the gap, not merely asserting it.

---

## 13. v2 originals — unchanged (§9.6 restates the tenant-fact honesty fix)

## 14. Constraints honored — unchanged from Revision 3

---

## 15. Wording and citation fixes (fold item 10, full sweep)

- **D1-moved line citations corrected**: `TerminalController.php`'s three `current_sequence`
  assignments are at `:120`, `:400`, `:465` against current `dev` (the round-3 fold list's
  `:120`/`:395`/`:458` citation was itself stale — propagated from the pre-merge worktree
  numbering rather than re-checked against the merged D1 commit; corrected here).
- `apps/pos/src/lib/db/__tests__/migrations.v65.test.ts` — pinned exactly (current max migration
  version confirmed at `64`; no "or next free" hedge).
- `apps/pos/src/lib/operatorApproval/__tests__/posOverrideAuthoring.test.ts` — marked **new**
  (confirmed does not exist today).
- `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` — marked **existing,
  modified** (confirmed exists with 20+ cases already; not a new file).
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` — marked **extend**
  (confirmed exists, 192 lines, §2).
- Orphaned test files of the deleted `local_refund_records`/`refundZAccounting.ts` repos are
  listed for deletion alongside their source files in §17 (not left as dangling references).
- `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php` — full path used consistently (F-16
  remains the confirmed next-free fixture number; F-15-large exists on disk but unregistered,
  unrelated to this feature).
- The "tenant #1 originals are always cash" claim is removed (§9.6).
- The refusal i18n copy never says "use the legacy path" once the guard is active (§9.4).
- **Lane B's promoted eslint gate (mechanical note (a)):** the merged first wave includes Lane B's
  cart-mutator ESLint guard (`apps/pos/eslint.config.js`, extended `no-restricted-syntax` block).
  Every rewritten refund store/service in this manifest (`refundCheckoutStore.ts`,
  `refundReceiptService.ts`, `refundIntentRepository.ts`) that mutates cart state must route
  through the **gated helper functions** that guard already requires (`cartMutatorSelectors` or
  the equivalent sanctioned mutation surface), not raw store-set calls — stated explicitly so the
  code phase does not introduce new lint violations in a file the guard already covers.

---

## 16. Post-launch roadmap (NON-NORMATIVE)

Unchanged from Revision 3's §16.1–§16.4 (`original_payment`, cross-terminal, `store_voucher`
issuance, VOID full integration), plus:

### 16.5 Signed device-side policy evidence

The full window/cap/manager-threshold/disposition signed-snapshot mechanism §3.6 explicitly
declined to build for launch (server-advisory only) — a genuine future capability: an exact
signed policy snapshot format, device cache/enforcement files, and server-side verification
against that snapshot (rather than the current accept-and-flag-after-the-fact advisory).

### 16.6 Auto-disable on second-terminal provisioning

§9.4's preflight prevents *enabling* the capability with more than one active device terminal,
but does not *retroactively disable* an already-acknowledged terminal's capability the moment a
second device terminal is provisioned for the same tenant. A real gap for any tenant that grows
past one terminal post-launch — deferred, not silently assumed away.

### 16.7 Whole-discount-receipt refunds

§3.5's refusal (any non-zero `transaction_discount_amount` blocks a refund entirely) is a launch
simplification, not a permanent product limitation. A real future feature needs either a
proration algorithm for partial refunds of a discounted receipt, or a verified-1:1-with-original
exact-full-refund path that preserves the original discount unmodified — an open design question,
not attempted here.

---

## 17. Frozen code-phase write manifest (exact)

**`apps/api` — payload contract:**
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` — add `4` to
  `SALE_RECEIPT`'s supported-version list only (§2; VOID rejection lives in the validator, not
  here).
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` —
  `SALE_RECEIPT_PAYLOAD_KEYS_V4`; §3.3's exact `original_line_references[]` shape/enum; §3.4's
  `refund_destination`/`settlement_allocation`; §3.5's single-cash-leg `payments[]`; §3.5's
  transaction-discount-zero-iff-refused invariant; VOID rejection at v4 parse; §4.5 errata T6's
  `'payout_dispute_evidence'` literal added to the two `approval_scope` `assertEnum()` call sites
  at `:580` and `:613`.
- `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php` (`:62-116`).
- New DTO: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/OriginalLineReferenceDTO.php`.
- `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php`,
  `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php`.
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — v4 cases; VOID
  rejection case; corpus assertion extended from `assertCount(14, ...)` to `assertCount(15, ...)`.
- New golden fixture: `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/`
  (`payload.json` + `expected.json`). `apps/api/tests/Helpers/Fiscal/GoldenFixtureBuilder.php` —
  add the `F-16` case (F-15-large remains unregistered, out of scope for this feature).
- New parity tests: `apps/api/tests/Feature/Fiscal/CanonicalByteHashV4ParityTest.php` (PHP),
  `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.parity.test.ts` (TS).

**`apps/api` — engine/projector/business logic:**
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` — §3.3's
  original-line resolution + `pos_receipt_lines.original_line_id` write; §3.6's
  `refund_policy_alerts` write; §4.2's approval-evidence resolution/verification; §10's
  `redeemVouchers()` gate (keyed on `ReceiptType::Sale`, matching `earnLoyaltyPoints`'s form);
  §12's `FOR UPDATE` lock + `ABS()`-normalized quantity-cap query +
  `RefundQuantityExceededException`; disposition-aware stock restore; training-original defense
  check.
- New migration (errata X1, exact filename): `apps/api/database/migrations/tenant/2026_07_31_910000_add_sealed_hash_algorithm_to_pos_receipts.php`
  — adds `pos_receipts.sealed_hash_algorithm` (nullable string enum). Must precede the trigger
  migration below (the trigger references this column).
- New migration (errata X1): `apps/api/database/migrations/tenant/2026_07_31_920000_add_refund_policy_alerts_to_pos_receipts.php`
  — adds `pos_receipts.refund_policy_alerts` (JSONB, nullable).
- New migration: `apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`
  (§6.2's exact trigger amendment — filename unchanged from Revision 4, now given its full
  directory path per errata X1).
- New exception files: `apps/api/app/Modules/Fiscal/Domain/Exceptions/RefundQuantityExceededException.php`
  (implements `NonRetryableProjectionException`),
  `apps/api/app/Modules/Fiscal/Domain/Exceptions/ApprovalEvidenceUnresolvedException.php`
  (implements `NonRetryableProjectionException`), new marker interface
  `apps/api/app/Modules/Fiscal/Domain/Exceptions/NonRetryableProjectionException.php`.
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php` — §4.2's
  non-retryable catch branch + immediate `$this->fail($e)`.
- `apps/api/app/Modules/POS/Domain/Receipt.php`, `apps/api/app/Modules/POS/Domain/Terminal.php` —
  `casts()` additions (§6.4).
- `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php` — write
  `sealed_hash_algorithm` at seal time.
- `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` — `verifyLegacyArm()`
  branches on `sealed_hash_algorithm`, §6.3's backfill-completion-gated NULL handling.
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` — identical repair.
- New Artisan command: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/BackfillSealedHashAlgorithmCommand.php`.
- New Artisan command: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/InventoryV3LegacyCorrectionsCommand.php`.
- New Artisan command: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnableV4RefundAuthoringCommand.php`
  (§9.4's single-device-terminal preflight + §9.3 Phase 1 trigger + §5.3's account-provisioning
  check).
- New migration (errata X1, exact filename): `apps/api/database/migrations/tenant/2026_07_31_950000_create_fiscal_refund_compensations_table.php`
  — creates the `fiscal_refund_compensations` table (§5.2), including the
  `fiscal_refund_compensations_event_unique` partial unique index.
- New file: `apps/api/app/Modules/Fiscal/Presentation/Controllers/RefundCompensationController.php`
  (`POST /fiscal/refund-compensations`, §5.2), route added to
  `apps/api/app/Modules/Fiscal/routes.php` (existing file, existing middleware stack
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` +
  `->middleware('can:fiscal.refunds.manage_dead_letters')`).
- New file: `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
  (list + detail, read-only — §5.1's operator visibility, unchanged from Revision 3).
- `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` — **existing, modified**
  (not new): add `RefundWriteOff` case + exhaustive `label()`/`expectedAccountType()` arms.
- `apps/api/database/seeders/GenericChartOfAccountsSeeder.php` — unchanged (already seeds
  `SalesReturn`, `:196`); add the `RefundWriteOff` account row.
- `apps/api/database/seeders/FranceChartOfAccountsSeeder.php`,
  `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` — add `'system_purpose' =>
  SystemAccountPurpose::SalesReturn->value` **and** change `'type'` from `'revenue'` to
  `'expense'` on the existing 709 row (`:282`/`:277` respectively — errata T1/4.2, aligning to
  the same file's own `7091`/`SalesReturnsClearing` expense-typed precedent, `:287-288`/`:282-283`);
  add the `RefundWriteOff` account row.
- New Artisan command: `apps/api/app/Modules/Accounting/Infrastructure/Commands/BackfillRefundCompensationAccountsCommand.php`
  (renamed from `BackfillRefundWriteOffAccountCommand`, T1 errata — idempotent per-tenant
  provisioning covering **both** `RefundWriteOff` and `SalesReturn`).
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php` — add
  `'fiscal.refunds.manage_dead_letters'` to the permission list (mirroring `:327`'s
  `'pos.process_returns'`) and grant it to the same role already holding `pos.process_returns`
  (`:530`'s role).
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`,
  `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` — call
  `LegacyCorrectionGuard::assertLegacyCorrectionAllowed()` (§9.1/§9.3 — now conditioned on
  `v4_refund_authoring_acknowledged_at`, not raw schema version).
- New file: `apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php`.
- New exception: `apps/api/app/Modules/POS/Domain/Exceptions/LegacyCorrectionRetiredException.php`.
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php` — map to HTTP 409,
  `LEGACY_CORRECTION_RETIRED` (§9.4's corrected copy).
- New migration (errata X1, exact filename): `apps/api/database/migrations/tenant/2026_07_31_930000_add_v4_refund_authoring_capability_to_pos_terminals.php`
  — adds `pos_terminals.v4_refund_authoring_enabled` (boolean, default false),
  `pos_terminals.v4_refund_authoring_acknowledged_at` (nullable timestamp).
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` — expose both fields.
- Tests: `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php`;
  `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3VoidGuardTest.php`;
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundQuantityCapTest.php` (PG-mode);
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundQuantityCapMixedLegacyTest.php`
  (§12); `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundPolicyAlertTest.php`;
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionApprovalEvidenceTest.php`;
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionVoucherRefundNoRedemptionTest.php`;
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTrainingRefundRefusedTest.php`;
  `apps/api/tests/Feature/Fiscal/ApplyFiscalEventProjectionJobNonRetryableTest.php` (§4.2);
  `apps/api/tests/Feature/POS/ReceiptHashServiceVerifyLegacyArmV4Test.php`;
  `apps/api/tests/Feature/Fiscal/PosReceiptImmutabilityTriggerSealedHashAlgorithmTest.php`
  (§6.2's PG trigger regression test);
  `apps/api/tests/Feature/Fiscal/Nf525VerifyChainParityTest.php`;
  `apps/api/tests/Feature/Fiscal/BackfillSealedHashAlgorithmCommandTest.php`;
  `apps/api/tests/Feature/Fiscal/InventoryV3LegacyCorrectionsCommandTest.php`;
  `apps/api/tests/Feature/Fiscal/EnableV4RefundAuthoringCommandTest.php` (§9.4's preflight
  refusal + §5.3's account-check);
  `apps/api/tests/Feature/Fiscal/RefundCompensationControllerTest.php` (both classes,
  idempotency-replay, posting/atomicity, permission gate);
  `apps/api/tests/Feature/Fiscal/DeadLetteredProjectionsControllerTest.php`;
  `apps/api/tests/Feature/POS/LegacyCorrectionGuardTest.php` (409 + acknowledgement-conditioning);
  `apps/api/database/seeders/__tests__` equivalent — **scope corrected, errata 4.3**: this test
  asserts `system_purpose` **provisioning** only — that a fresh chart seeds both `RefundWriteOff`
  and `SalesReturn` with their purposes resolvable via `hasAccountForPurpose()`, and that
  `BackfillRefundCompensationAccountsCommand` idempotently provisions whichever purpose is
  missing on an already-existing chart. It must **not** assert purpose↔`type` consistency (e.g.
  "every account with `system_purpose = SalesReturn` has `type = expense`") as a general
  invariant — such an assertion would pass against a fresh seed but **fail** against any
  backfilled (pre-existing) FR/TN tenant, whose 709 account intentionally keeps its original
  `'revenue'` type per this section's accepted-divergence ruling (§5.3, above). Any `type`
  assertion in this test is scoped narrowly to the **seeder source** (a fresh chart), never to a
  backfilled tenant's already-provisioned row.
- Every new bcmath comparison carries a `// precision-ok: scale-4` (quantity) or currency-scale
  marker per rule 19; every new projection test calls `app(CompanyContext::class)->clear()`
  before `apply()` per rule 20.

**`apps/pos`:**
- `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts` — §2's `eventVersionFor(type, payload)`;
  `VoidAuthoringProhibitedError`.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` — threaded `eventVersion`; new
  `SALE_RECEIPT_PAYLOAD_KEYS_V4`.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — extended (V1-V3 regression +
  VOID negative + V4 positive).
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` — **extend** (confirmed
  exists, 192 lines; payload-less-call green-path assertion stated explicitly, §2).
- `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — extended for V4 parity,
  V1/V2/V3 assertions preserved verbatim.
- New payload builder: `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts` — §3.2's
  normalization step, composing through `buildSaleReceiptV3Payload`.
- `apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts` — Revision 3's discount-field carry-through
  fix, unchanged (sign behavior unchanged, §3.2).
- `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts` — `resolveOriginalFiscalEventLocally()`
  (original's `line_items[]`/`payments[]`/`training_flag`/`transaction_discount_amount` for §3.5's
  refusal check).
- New SQLite migration (single version, both changes): `apps/pos/src/lib/db/migrations.ts` — new
  `refund_intents` table (§4.4); new `offline_receipts.receipt_kind` column (§7.2); new
  `terminal_state.v4_refund_authoring_enabled`/`v4_refund_authoring_acknowledged_at` columns
  (§9.1).
- New test: `apps/pos/src/lib/db/__tests__/migrations.v65.test.ts` (§15's pinned exact version).
- New file: `apps/pos/src/lib/db/repositories/refundIntentRepository.ts`.
- `apps/pos/src/lib/db/repositories/terminalStateRepository.ts` — new
  `setV4RefundAuthoringEnabled()` (§9.1) and a capability-read helper.
- `apps/pos/src/lib/db/repositories/__tests__/terminalStateRepository.test.ts` — extended for the
  new setter's guard-independence (§9.1).
- New file: `apps/pos/src/lib/offline/refundReceiptService.ts` — §4.3's append-first transaction,
  now including §7.2's `offline_receipts` insert as the third write.
- `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts` — §4.2's optional `sourceEventIds`
  parameter; §4.5 errata T6's `'payout_dispute_evidence'` literal added to
  `PosOverrideApprovalScope` (`:8-10`) and `assertApprovalScope()` (`:60-68`).
- New file: `apps/pos/src/lib/operatorApproval/__tests__/posOverrideAuthoring.test.ts`
  (**new**, confirmed does not exist today).
- New file: `apps/pos/src/lib/refundFlow/refundApprovalV3.ts` —
  `authorRefundReturnApprovalV3()`.
- `apps/pos/src/stores/refundCheckoutStore.ts` — rewritten around `refund_intents` state
  transitions, routed through Lane B's gated cart-mutator helpers (§15's mechanical note (a)).
- New component: `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx` — §4.5's
  payout-confirmation **and** reprint-recovery screens.
- `apps/pos/src/lib/refundFlow/refundZAccounting.ts`,
  `apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts` — **deleted**.
- `apps/pos/src/lib/refundFlow/__tests__/refundZAccounting.test.ts` — **deleted** alongside its
  source file (errata F2 — corrected path: this test lives under `__tests__/`, not flat in
  `refundFlow/`; `localRefundRecordRepository.ts` has no corresponding test file today, so there
  is no test to delete for it — Revision 4's manifest entry for that non-existent test file is
  dropped).
- `apps/pos/src/lib/offline/__tests__/zReportService.test.ts`,
  `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts` — **extended** (errata F2, both
  currently reference `local_refund_records`/`getRefundRecordsForShift` and must be updated for
  §7.3/§7.3a's `receipt_kind`-branched replacement rather than left referencing a deleted table).
- `apps/pos/src/lib/offline/zReportService.ts` — §7.3's exact `aggregateReportData` re-signature
  and per-row `receipt_kind` branching; §7.3's `expected_cash` formula simplification.
- `apps/pos/src/lib/offline/endOfDayPreview.ts` — its **own** `receipt_kind`-branched refund
  handling (§7.3a errata T3 — not shared code with `zReportService.ts`); deletes its own
  `getRefundRecordsForShift` call.
- `apps/pos/src/lib/db/repositories/productSalesAggregateRepository.ts` — **no production
  change** (§7.3b errata T7 — net-units behavior is already correct by construction).
- `apps/pos/src/lib/db/repositories/__tests__/productSalesAggregateRepository.test.ts` —
  extended with the net-units case (§7.3b).
- `apps/pos/src/lib/sync/syncService.ts` — `pullTerminalState` calls
  `setV4RefundAuthoringEnabled()` in both branches (§9.1); sends the Phase-2 acknowledgement
  (§9.3); new `source_event_class === 'refund_intents'` sync-completion-flip branch alongside the
  existing `'offline_receipts'` branch at `:382-390` (§7.2a errata T2 — closes the
  stuck-`'pending'`-forever bug).
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts` — new
  `updateReceiptStatusByIdempotencyKey()` (§7.2a, extends the existing `updateReceiptStatus()`
  file, not a new repository).
- `apps/pos/src/lib/sync/__tests__/syncService.refundStatusFlip.test.ts` — new, proving a
  refund's `offline_receipts` row reaches `'synced'` (not stuck `'pending'`) after its fiscal
  event syncs (§7.2a).
- `apps/pos/src/lib/buildReceiptData.ts` — unconditional AVOIR rounding line (kept from
  Revision 2/3, unaffected by this round).
- `apps/pos/src/pages/HomePage.tsx` — §9.2's real capability-check dispatch seam, at the
  `'start-refund'` interception point.
- `apps/pos/src/pages/__tests__/HomePage.refundCapability.test.tsx` — new, asserting the
  dispatch-seam gate (§9.2).
- `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` — new keys inside the
  existing `"refundFlow"` object for: §9.4's corrected 409 refusal copy (no "use the legacy
  path"), §3.5's whole-discount-receipt refusal, the training-refusal message (kept), the payout
  **and** reprint-recovery modal copy (§4.5).
- Tests: `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.test.ts` (§3.2's
  normalization proof, §3.3's parallel-array assertions, §3.5's discount-refusal cases);
  `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.trainingRefusal.test.ts`
  (§3.7 errata T4 — training-original refusal site + non-conflation with session training mode);
  `apps/pos/src/lib/refundFlow/__tests__/hydrateFromReceipt.discount.test.ts`;
  `apps/pos/src/lib/db/repositories/__tests__/refundIntentRepository.test.ts` (§4.4's corrected
  active-index exclusion of `synced`);
  `apps/pos/src/lib/offline/__tests__/refundReceiptService.test.ts` (§4.3's append-first ordering
  + §7.2's `offline_receipts` insert, asserted directly);
  `apps/pos/src/stores/__tests__/refundCheckoutStore.test.ts`;
  `apps/pos/src/components/pos/__tests__/RefundPayoutReconciliationModal.test.tsx` (both payout
  and reprint-recovery flows);
  `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts` — extended (Lane B's
  file, never forked) with §7.3's refund-row-in-`offline_receipts` cases.
- **Errata T7, minor, device-side precision rule:** every new `bcabs`/`bcadd`/`bcsub`/`bcmul`
  call this manifest introduces (§3.2's normalization steps, §7.3/§7.3a's per-row branching,
  §7.3b unaffected — no new calls there) passes its scale argument **explicitly** — the
  device `lib/decimal.ts` helpers default to scale 3 when no scale is supplied, which silently
  truncates a scale-2 currency or a scale-4 quantity if a call site omits it (rule 19's
  device-side equivalent of the API manifest's `precision-ok` marker note, above). No new call
  site in this manifest may rely on the scale-3 default.

**Explicitly NOT touched:** `apps/pos/src/lib/payment/cashRounding.ts`;
`apps/api/app/Modules/Voucher/Application/Services/VoucherIssuanceService.php`;
`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` (confirmed dead code
for v3/v4); `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php`; the VOID
authoring surface (does not exist, is not created). **`apps/pos/src/lib/refundFlow/refundSettlementService.ts`
is removed from the manifest entirely** (fold item 10 — Revision 3 retained it as a "legacy
fallback" with no concrete change described; per its own no-change-free rule it is dropped —
tenant #1 has no reachable case that calls it, since §9.6 establishes only cash-tendered
originals are refundable at all, and v2-original/non-cash-original refunds are the explicit §9.6/
§13 manual off-system workaround, not a code path this file needs to serve).
