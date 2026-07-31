# v3 Refund/Void Chain Integration — Design Spec (REVISION 4 — FOLD ROUND, TENANT-#1 LAUNCH SLICE)

**Lane:** C (first-tenant launch program). **Phase:** SPEC ONLY — zero code changes.
**Round-3 verdicts:** fiscal-pos-reviewer **APPROVE-WITH-FIXES** ("no round-4 full review needed
if C-1..C-4 + I-1..I-9 land verbatim"), treasury-reviewer **APPROVE-WITH-FIXES** (three bounded
money rulings), Codex **REJECT** (7 items, same underlying issues, 4 requiring genuine boundary
choices). Reviews: `docs/superpowers/reviews/2026-07-31-lane-c-spec-r3-fiscal-treasury-reviews.md`,
`docs/superpowers/reviews/2026-07-31-codex-refund-chain-spec-r3-review.md`.

**This is the fold round, not a fourth design round.** Per the fiscal-pos reviewer's explicit
condition and three rounds of convergence, verification from here is **scoped** (fiscal-pos
verifies its C-1..C-4/I-1..I-9 landed verbatim; Codex confirms its 7 items), not a full re-read.
Every item below is tagged with its source finding(s) so the fold can be checked mechanically
against the converged fold list. Where two reviews touched the same issue, both formulations are
satisfied explicitly (called out inline).

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
it computes whatever policy questions it can from data it already has (the original receipt's own
timestamp, the resolved cashier's own permission set) **after** the event is signed, and flags
discrepancies for manual review; it never claims this was device-proven in advance. A **signed**
device-side policy snapshot (the device asserting, in the payload itself, "I checked the window
and it was N days") is deferred to §16.5 — a real future capability, not attempted here. This is
a narrowing of the claim, not new code: it removes the false "device proves it" prose and states
plainly what already existed (the accept-and-flag advisory mechanism), satisfying Codex's
"pick one" instruction by picking server-advisory.

### 3.7 `payments[]`, training refusal, and the corrected ticket citation (unchanged mechanism,
citation fixed)

`payments[]` is exactly one cash leg (unchanged from Revision 3). Training-original refusal
(device-side, unchanged). **Citation fix (mechanical note (b) from the orchestrator):** the
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
audit event (reusing the existing operator-evidence authoring pattern, §4.2, with
`approval_scope` extended by one new literal value for this specific audit purpose) carrying the
refund's `refund_fiscal_event_id` and the disputing operator's identity — giving the finance team
a signed, server-side record that "the local device could not confirm this cash left the drawer"
for their own investigation, entirely separate from and non-blocking of the Z's own arithmetic.

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
is called with a `MovementIntent` keyed on `idempotencyKey = "refund-writeoff:{fiscal_event_id}"`
(the port's own idempotency mechanism, `record()`'s documented contract, backstops the table-level
unique index above rather than duplicating its logic) to move the cash repository's balance —
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
  purpose** (`SystemAccountPurpose::SalesReturn`, `'sales_return'`, GL account 709 — already
  seeded in all three chart seeders for credit notes) — the **same reversal shape** a
  successfully-booked refund would have received via the normal path, because this class *is* a
  normal refund; only its booking was delayed by infrastructure, not its validity.

**Seeding and provisioning (§17, fold item 5's remaining sub-findings):** `RefundWriteOff` is
added to all three chart seeders (`GenericChartOfAccountsSeeder.php`,
`FranceChartOfAccountsSeeder.php`, `TunisiaChartOfAccountsSeeder.php`), each following the
existing `PaymentToleranceIncome`/`PaymentToleranceExpense` seeding line shape exactly (e.g.
`GenericChartOfAccountsSeeder.php:199-200`'s `['code' => ..., 'system_purpose' =>
SystemAccountPurpose::PaymentToleranceIncome->value, 'is_system' => true]` pattern). A new
idempotent backfill command provisions the account for any **already-existing** tenant chart
(including tenant #1's, if provisioned before this seeder change lands) rather than requiring a
fresh migration run — named in §17. The rollout preflight (§9) checks the account exists for
tenant #1 before the capability can be enabled, closing the "rollout does not preflight that
account" finding.

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

### 9.6 v2-original limitation, tenant-fact honesty (fold item 10)

§9's legacy-endpoint guard blast radius for v2 originals on any cutover terminal (kept from
Revision 3) is restated with the "tenant #1 originals are always cash" claim **removed** — the
merged D1 launch-contract test proves an active CASH tender **exists** for a fresh tenant, not
that every eligible original a cashier might attempt to refund actually used cash. The accurate
statement: tenant #1's originals are v3-from-birth (D1's provisioning guarantee) and the
cash-only launch payload (§3) simply cannot represent a refund of an original that wasn't
cash-tendered — such an attempt is refused by the same typed mechanism as any other unsupported
destination, not a scenario this spec claims cannot occur.

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
  assignments are at `:120`, `:395`, `:458` (re-verified in this checkout — not `:400`/`:465` as
  an earlier pass claimed).
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
  transaction-discount-zero-iff-refused invariant; VOID rejection at v4 parse.
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
- New migration: add `pos_receipts.refund_policy_alerts` (JSONB, nullable).
- New migration: add `pos_receipts.sealed_hash_algorithm` (nullable string enum).
- New migration: `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php` (§6.2's
  exact trigger amendment).
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
- New migration: `fiscal_refund_compensations` table (§5.2).
- New file: `apps/api/app/Modules/Fiscal/Presentation/Controllers/RefundCompensationController.php`
  (`POST /fiscal/refund-compensations`, §5.2), route added to
  `apps/api/app/Modules/Fiscal/routes.php` (existing file, existing middleware stack
  `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` +
  `->middleware('can:fiscal.refunds.manage_dead_letters')`).
- New file: `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
  (list + detail, read-only — §5.1's operator visibility, unchanged from Revision 3).
- `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` — **existing, modified**
  (not new): add `RefundWriteOff` case + exhaustive `label()`/`expectedAccountType()` arms.
- `apps/api/database/seeders/GenericChartOfAccountsSeeder.php`,
  `apps/api/database/seeders/FranceChartOfAccountsSeeder.php`,
  `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` — add the `RefundWriteOff` account
  row, mirroring the existing `PaymentToleranceIncome` line shape in each.
- New Artisan command: `apps/api/app/Modules/Accounting/Infrastructure/Commands/BackfillRefundWriteOffAccountCommand.php`
  (idempotent per-tenant provisioning for already-existing charts).
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
- New migration: add `pos_terminals.v4_refund_authoring_enabled` (boolean, default false),
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
  `apps/api/database/seeders/__tests__` equivalent — a seeder test asserting `RefundWriteOff` is
  provisioned by all three chart seeders and the backfill command is idempotent.
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
  parameter.
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
- `apps/pos/src/lib/refundFlow/refundZAccounting.test.ts`,
  `apps/pos/src/lib/db/repositories/__tests__/localRefundRecordRepository.test.ts` — **deleted**
  alongside their source files (§15, orphaned-test-file fix).
- `apps/pos/src/lib/offline/zReportService.ts` — §7.3's exact `aggregateReportData` re-signature
  and per-row `receipt_kind` branching; §7.3's `expected_cash` formula simplification.
- `apps/pos/src/lib/offline/endOfDayPreview.ts` — same rewiring.
- `apps/pos/src/lib/sync/syncService.ts` — `pullTerminalState` calls
  `setV4RefundAuthoringEnabled()` in both branches (§9.1); sends the Phase-2 acknowledgement
  (§9.3).
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
