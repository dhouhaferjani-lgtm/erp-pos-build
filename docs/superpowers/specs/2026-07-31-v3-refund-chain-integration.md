# v3 Refund/Void Chain Integration — Design Spec (REVISION 3 — TENANT-#1 LAUNCH SLICE)

**Lane:** C (first-tenant launch program). **Phase:** SPEC ONLY — zero code changes.
**Revisions 1 and 2 were REJECTED** (1: ×3; 2: ×3, but converged — E1/rounding, the failure-mode
trace, and the verifier-partition determination were all confirmed CLOSED in round 2; the
residue was executability and the money paths). Reviews:
`docs/superpowers/reviews/2026-07-31-codex-refund-chain-spec-r2-review.md` (11 re-work items),
`docs/superpowers/reviews/2026-07-31-lane-c-spec-r2-fiscal-treasury-reviews.md` (fiscal C-1..C-4,
treasury scorecard).

**Orchestrator scope directive for this revision (§0 below):** stop iterating the full design.
Re-scope the launch slice to tenant #1's actual shape — greenfield, provisioned at v3 day 1,
single terminal, no v2 originals will ever exist for it — and move the full multi-terminal /
multi-destination design to a clearly non-normative post-launch roadmap appendix (§16). This is
not a retreat from rigor: every item the r2 reviews required to be closed for the **narrow
slice** is closed below, with the same citation discipline as revisions 1–2.

**Review gate:** fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex adversarial pass, again.

---

## 0. Scope decision

**Launch slice, ruled exactly:**

| Axis | Launch scope | Rationale / what moves to §16 |
|---|---|---|
| Refund destination | **`cash` only** — the v4 payload's `refund_destination` field is typed as the single literal `'cash'`, not a union (§3.2) | Deletes the `original_payment` criticals entirely: the dead `treasury_payment_id` backlink, the proration-algorithm mismatch (opposite operand order, wrong residual-assignment leg, no ordinal→Payment mapping), and the double-booking `PaymentRefundService` vs. bridge conflict the treasury r2 review found. Moved to §16.1 with the r2 findings attached. |
| Original terminal | **Same-device only** — tenant #1 is provisioned with one terminal at v3 from day one | Deletes the cross-terminal lookup endpoint, its authz/anti-enumeration/capability contract, and the "three indistinguishable local-miss causes" problem — there is structurally only one cause (genuinely not on this device, i.e., not this tenant's data) for tenant #1. Moved to §16.2. |
| v2 originals | **Impossible for tenant #1** (never provisioned below v3) | §9's guard still ships (protects staging/demo terminals that *do* have v2 history) with an explicit blast-radius statement and operator workaround (§9.4), not a silent gap. |
| VOID | **Prohibited, fail-closed at both boundaries** (§8) | Device authoring rejects `invoice_type_code = 'VOID'`; server validator/version-resolution rejects a v4 VOID payload. No dual-option fallback, no contradictory positive VOID vector. |
| `store_voucher` destination | **Not in the launch enum**, plus a defense-in-depth projector gate (§10) | The chicken-and-egg unminted-serial problem from Revision 2 stands; moved to §16.3. The gate closes the treasury r2 Critical that an original part-paid by voucher would get the voucher **redeemed again** on refund even though the enum excludes it — cash-only `payments[]` should make the burn path unreachable, and §10 proves that, not merely asserts it. |

This scope, combined with Model 1 (kept from Revision 2, §4), makes the Model-1 rejection matrix
(§5) dramatically smaller than Codex's r2 audit found it — most of the classes Codex enumerated
were `original_payment`/proration-specific and no longer exist in the launch payload at all.

---

## Item-closure map (all three r2 reviews)

| Source | Item | Closed in |
|---|---|---|
| Codex r2 | 1. v4 authoring executability (`FiscalEventEngine.ts` in manifest) | §2 |
| Codex r2 | 2. One exact v4 schema (line↔reference mapping, discount, payments[], policy evidence) | §3 |
| Codex r2 | 3. Approval protocol (deterministic UUID source IDs, recovery, projector verification) | §4.2 |
| Codex r2 | 4. Intent transaction/state model (append-first, payout/print states, uniqueness, sync-stop) | §4.3–§4.5 |
| Codex r2 | 5. Model-1 rejection matrix, every class | §5 |
| Codex r2 | 6. Store-voucher / VOID contradictions | §8, §10 |
| Codex r2 | 7. Server policy semantics (window/cap/manager/disposition classification) | §3.4 |
| Codex r2 | 8. Verifier repair (per-row discriminator, not terminal-version) | §6 |
| Codex r2 | 9. Rollout capability enactability | §9 |
| Codex r2 | 10. Exact §9 manifest | §17 |
| Codex r2 | 11. Citation/wording defects | inline + §15 |
| fiscal-pos r2 | C-1 `FiscalEventEngine.ts` absent | §2 |
| fiscal-pos r2 | C-2 verifier unsound (terminal-version branch) | §6 |
| fiscal-pos r2 | C-3 discount contract unspecified | §3.3 |
| fiscal-pos r2 | C-4 `original_payment` silent no-op | §16.1 (deleted from launch scope) |
| fiscal-pos r2 | 8 Importants (guard/reverse-lookup/PG test/dead-letter/enum/Z-sign/rollout outage) | §7, §8, §9, §7.4, §9.4 |
| treasury r2 | `pos_cash_rounding_refund` reuse | kept CLOSED, unchanged (§7.3 note) |
| treasury r2 | `original_payment` backlink dead / double-booking | §16.1 (deleted from launch scope) |
| treasury r2 | Voucher-guard-unreachability contradiction | §8.4, §10 |
| treasury r2 | v3-guard-removes-all-legacy-paths (v2 originals, ex-VOID) | §9.4 |
| treasury r2 | Expected-cash targets dead code | §7.1 |
| treasury r2 | Rounding-adjustment sign convention unstated | §7.2 |
| treasury r2 | `CashDrawerService` still wrong on v3 shift close | §7.5 (ticketed) |
| treasury r2 | VOID no fail-closed server rejection | §8 |
| treasury r2 | Retiring `refundZAccounting` orphans legacy device Z | §9.4 |
| treasury r2 | S4 write-off artifact missing | §5.2 |
| treasury r2 | Training-gate leak not ticketed | §3.5 (ticket path named) |

---

## 1. Today's failure mode (kept from Revision 2, two wording defects fixed)

Revision 2's state-dependent trace (`current_sequence = 0` → PG CHECK `23514`; `current_sequence
= 1` colliding with the first projected sale → PG unique `23505`; a sequence gap → conditional
orphan commit; full rollback; controller 422) was **verified correct** by Codex r2 §12.1 against
`TerminalController.php:120,395,458` (confirmed exact by re-check, not `:111-124` etc. as
paraphrased loosely before), `DemoPharmacySeeder.php:752-771`,
`ReceiptFinalizationService.php:83-111`,
`2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:25-33`, and
`ReceiptController.php:246-356`. The verifier-partition determination (§6 below) was also
verified correct in its diagnosis, though its *remedy* changes in this revision. **Kept
unchanged**, with two fixes:

1. **VOID removed from the orphan-verifier symptom sentence.** Revision 2 still said a
   legacy-sealed "return/void" produces the permanent-red state, while its own §1.1 (kept, §1
   above) correctly established that `ReceiptVoidService.php:53-135` creates no correction row
   and never calls `ReceiptFinalizationService` at all — VOID cannot poison the legacy-arm
   verifier, because it never writes into the legacy chain in the first place. The symptom is
   **return-only**. This sentence is corrected wherever it appears.
2. **Acceptance-scenario version fixed.** The integration test (§1.1 below) authors the initial
   sale at `event_version = 3` and the refund at `event_version = 4` — never a "`SALE_RECEIPT`
   event_version 4 sale," which contradicted the ruling that SALE/TRAINING remain version 3
   forever (§2.1).

### 1.1 Acceptance test (corrected)

`apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php`, run in **Postgres test mode**
(SQLite cannot reproduce either SQLSTATE, §1's table):

1. Terminal at `fiscal_schema_version = 3`, `v4_refund_authoring_enabled = true` (§9), both
   `current_sequence` topologies (0 and 1) as separate cases.
2. Author a genuine device **v3** `SALE_RECEIPT` sale (`event_version = 3`) through the real
   ingest/job/projection lifecycle.
3. Author a genuine device **v4** `SALE_RECEIPT` refund (`event_version = 4`,
   `invoice_type_code = 'REFUND'`) against it via §2–§5's new path.
4. Author a second v3 sale — assert its `previous_hash` equals the refund event's `current_hash`
   (chain unbroken, refund is *in* it).
5. Close the session; assert the Z close's `cash_rounding_summary` and totals cover **both** the
   sale and the refund.
6. Run `fiscal:verify-event-chain --tenant=<id> --terminal=<id> --actor-id=<system-actor-uuid>`
   and assert exit `0`; assert `pos:verify-chains --terminal=<id>` also reports `✓`.
7. Negative companion: the legacy `/return` endpoint against the same terminal, post-guard —
   typed 409 (§9.1), not a raw SQL error.
8. **v2-non-regression companion**: identical scenario on a `fiscal_schema_version = 2` terminal
   — legacy `/return`/`/void` behave exactly as `ReceiptReturnRefactorTest.php` already proves;
   the guard never fires for v2.

---

## 2. v4 authoring executability (Codex r2 item 1 / fiscal C-1)

Revision 2's fatal gap: it edited `FiscalEventPayloadRegistry.ts` but never touched
`FiscalEventEngine.ts`, which is where `SALE_RECEIPT_PAYLOAD_KEYS_V3` lives, where
`validateSaleReceiptPayload()` unconditionally checks against that fixed key set
(`FiscalEventEngine.ts:1451-1463`, `assertExactKeySet(p, SALE_RECEIPT_PAYLOAD_KEYS_V3,
'SALE_RECEIPT')`), and where `append()` calls `this.registry.eventVersionFor(request.event_type)`
(`:567-569`) with **no payload argument at all** — a type-only, payload-blind call. A v4 refund
payload is rejected by the device's own validator today, independent of anything the server does.

### 2.1 Exact API change

**`FiscalEventPayloadRegistry.ts`** — `eventVersionFor()` gains an optional payload parameter,
inspected **only** for `SALE_RECEIPT`; every other event type's behavior is byte-identical to
today:

```ts
eventVersionFor(type: FiscalEventTypeValue, payload?: unknown): number {
  if (type === 'SALE_RECEIPT') {
    const invoiceTypeCode = isRecord(payload) ? payload['invoice_type_code'] : undefined;
    if (invoiceTypeCode === 'SALE' || invoiceTypeCode === 'TRAINING') return 3;
    if (invoiceTypeCode === 'REFUND') return 4;
    if (invoiceTypeCode === 'VOID') throw new VoidAuthoringProhibitedError();
    throw new FiscalEventTypeNotImplementedError('SALE_RECEIPT'); // malformed/missing — fail closed
  }
  // unchanged for every other type
  ...
}
```

New error class `VoidAuthoringProhibitedError` (same file) — this is the device-side fail-closed
boundary §8 requires: **calling `engine.append()` with `invoice_type_code = 'VOID'` throws before
any state read or mutation**, symmetric with the existing `ServerAuthoredEventTypeError` boundary
check that already runs one step earlier in `append()` (`FiscalEventEngine.ts` Step -1).

**`FiscalEventEngine.append()`** (`:567-571`) — thread the payload into the version call, and
thread the resolved version into payload validation:

```ts
const eventVersion = this.registry.eventVersionFor(request.event_type, request.payload);
this.validateChainContext(request, chainContext);
this.validateRequestPayload(request, chainContext, eventVersion); // new 3rd arg
```

**`validateRequestPayload()`** (`:855-862`) — thread `eventVersion` to the `SALE_RECEIPT` case
only:

```ts
private validateRequestPayload(request, chainContext, eventVersion: number): void {
  switch (request.event_type) {
    case 'SALE_RECEIPT':
      validateSaleReceiptPayload(request.payload, eventVersion);
      return;
    // every other case unchanged, ignores eventVersion
  }
}
```

**New `SALE_RECEIPT_PAYLOAD_KEYS_V4`** (own const, V3's 30 keys plus `original_line_references`,
`refund_destination`, `settlement_allocation` — inserted at the PHP authority's sorted position,
pinned by the drift test, §2.3) — **never mutates `SALE_RECEIPT_PAYLOAD_KEYS_V3`**, matching the
existing "a NAMED constant, never a mutation" convention already documented on that const.

**`validateSaleReceiptPayload()`** (`:1451-1463`) gains the version parameter and branches the
key-set assertion:

```ts
function validateSaleReceiptPayload(payload: unknown, eventVersion: number): void {
  ...
  const keys = eventVersion >= 4 ? SALE_RECEIPT_PAYLOAD_KEYS_V4 : SALE_RECEIPT_PAYLOAD_KEYS_V3;
  assertExactKeySet(p, keys, 'SALE_RECEIPT');
  // v4-only field validation (original_line_references, refund_destination,
  // settlement_allocation — settlement_allocation is unreachable in this launch's
  // cash-only enum, §3.2, but the type accepts null and the validator still asserts
  // "must be null" for defense-in-depth) runs only when eventVersion >= 4.
  ...
}
```

### 2.2 Tests (mandatory manifest entries — Revision 2 omitted all of them)

- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — **extended**, not forked:
  (a) V1/V2/V3 non-regression — every existing assertion in this file must still pass unmodified
  (a version-aware registry that defaults wrong would silently break every existing sale test);
  (b) a `SALE_RECEIPT` + `invoice_type_code: 'VOID'` append attempt throws
  `VoidAuthoringProhibitedError` **before** any `fiscal_events` row is written (assert the table
  is empty after the throw) — this is the negative test item 6 of the orchestrator directive
  requires; (c) a v4 REFUND payload with the exact new key set appends successfully and is
  persisted with `event_version = 4`.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` — new file (this
  registry currently has no dedicated test file) — pins the SALE/TRAINING→3, REFUND→4, VOID→throw,
  malformed→throw matrix directly, independent of the engine.
- `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — **extended**: preserves the
  existing V1/V2/V3 PHP↔TS parity and strict-superset assertions verbatim, adds V4-as-strict-
  superset-of-V3 parity against the new PHP `SALE_RECEIPT_PAYLOAD_KEYS_V4` const (§17).

---

## 3. The exact v4 schema (Codex r2 item 2 / fiscal C-3)

### 3.1 `original_line_references[]` ↔ `line_items[]` — strict parallel arrays, not a loose
second array

**Ruling, closing Codex's "insufficient for a strict parallel-array contract" finding:**
`original_line_references[]` is **positionally aligned with `line_items[]`, same length, same
order** — index `i` of one describes index `i` of the other. Validator invariants (both TS and
PHP, symmetric):

- `len(original_line_references) === len(line_items)` — no extras, no omissions.
- `original_line_references[i].product_id === line_items[i].product_id` (exact string equality).
- `original_line_references[i].quantity === line_items[i].quantity` (exact bcmath equality at
  quantity scale — the reference's declared "how much of the original I am claiming to refund"
  must equal the actual signed refund quantity on that line; this is what makes the reference
  authoritative rather than decorative).
- `original_line_references[i].original_line_index` must be a valid 0-based index into the
  **original** sale's own `line_items[]` array (bounds-checked server-side against the resolved
  original fiscal event, §7.4's reverse-lookup machinery).

### 3.2 `refund_destination` — single literal, launch scope

`refund_destination: 'cash'` — a single-member literal type for this launch (§0), not a union.
Every prior draft's "3 vs 2" enum contradiction is resolved by having only one value exist at
all. The field is still present (not omitted) so the wire shape doesn't change shape again when
`original_payment`/`store_voucher` land post-launch (§16) — extension discipline: new values are
**added**, never retrofitted onto already-signed v4 data, matching the "Events are Immutable
Forever" rule applied to payload *versions*, not just event types.

`settlement_allocation: null` is **required** on every launch v4 payload (not omitted) — the key
exists in `SALE_RECEIPT_PAYLOAD_KEYS_V4` and the validator asserts it is exactly `null` for this
launch's cash-only enum. This is deliberate: a future `original_payment`-destination payload adds
meaning to an *existing* key rather than introducing a new one, so a v4 parser written today
already knows the key exists and null-checks correctly against tomorrow's populated form.

### 3.3 Discount contract (fiscal C-3, the concrete bug)

Confirmed: `hydrateFromReceipt.ts`'s `OfflineReceiptLine` interface (`:15-35`) carries
`discount_amount?: string | null` from the stored JSON, but the function's returned `CartItem`
(`:67-84`) **does not include `discount_amount` or `discount_reason` at all**. The stored
`offline_receipts.lines` JSON (written by `receiptService.ts` at sale time) *does* carry
`discount_type`, `discount_percent`, `discount_amount`, and `discount_reason` per line — the data
exists, it is simply dropped on the way into the refund cart. The consequence is concrete and
already provable from the inherited invariant: `SaleReceiptPayload.ts:264` computes
`discountAmount = bcformat(item.discount_amount ?? '0', scale)` — defaulting to `'0'` when the
field is absent — then `:281-294`'s `LineArithmeticInvariantError` check asserts `line_total ===
unit_price × quantity − discountAmount`. For any refund line whose **original** sale carried a
non-zero line discount, the hydrated cart item's `line_total` reflects the discounted amount but
`discount_amount` silently defaults to `'0'`, so `expectedGross ≠ actualGross` and the refund
builder throws `LineArithmeticInvariantError` on every discounted-line refund — a hard crash, not
an edge case, the instant a real tenant refunds any line that had a discount.

**Fix (in §17's manifest): `hydrateFromReceipt.ts`** — extend `OfflineReceiptLine` with
`discount_reason?: string | null` (currently missing from the interface even though the stored
JSON carries it) and map both `discount_amount`/`discount_reason` straight through onto the
returned `CartItem` (both fields already exist on `CartItem`, `types/cart.ts:43-44` — no sign
flip needed, a discount is a magnitude reduction regardless of sale/return direction).

**`transaction_discount_amount` ruling (unaddressed in Revisions 1–2):** a receipt-level discount
does not decompose cleanly across a *partial* line-subset refund without an allocation
algorithm this launch does not need to build. **Ruling: a v4 refund's
`transaction_discount_amount` is always canonical zero.** If the original sale carried a non-zero
`transaction_discount_amount`, a **partial** refund (a strict subset of its lines) is refused
device-side with a typed error ("this receipt has a whole-receipt discount; refund all lines, or
use the legacy path") before any approval authoring; a **full-receipt** refund (every line) is
permitted with `transaction_discount_amount = 0` on the refund payload — no re-representation is
needed because the per-line discounts (now correctly carried through per the fix above) already
account for the discounted amounts, and refunding every line naturally reverses the whole
discounted transaction.

### 3.4 Policy evidence — Model 1 classification (Codex r2 item 7)

The legacy path enforces return window, daily cashier cap, manager-override threshold, and
disposition/regulated-stock rules synchronously, before commit
(`ReceiptReturnService.php:278-319,366-467,536-645,1058-1137`). Under Model 1 (§4), the device
signs first — these checks cannot be synchronous gatekeepers at the server anymore, because the
event is already immutable evidence by the time the server sees it. Ruling, precisely: **every
one of these is device-enforced-with-signed-evidence, server-advisory** — the device is the
sole real-time gatekeeper (it already has the local shift/cap/window state needed to enforce
these, being the source of truth for the sale it's refunding), and the server's role is to
**verify the device's evidence is internally consistent and, when it is not, accept the event
and flag it** — never silently reject an already-signed, cryptographically valid correction.

**The in-repo precedent for this exact pattern already exists and is reused verbatim, not
invented:** `PosCoreReceiptProjection.php:1051-1071`'s late-sale flag. A signed sale can never be
rejected server-side (device is source of truth); if it lands against an active sales-blocking
inventory count, the projector **accepts it (stock already moved above), appends an advisory
flag** to the count's `late_sales_flags` JSON for manual reconciliation, and wraps the flag-append
in `try/catch` so a flagging failure can **never** fail or retry the fiscal projection itself.

**New, symmetric mechanism for refund policy evidence:** `PosCoreReceiptProjection` gains a
`refund_policy_alerts` JSON column on `pos_receipts` (return rows only), written when the
`approval_references[]` evidence resolved against the actual approval/override fiscal events
(§4.2) is internally *valid* (the events exist, are the right type/scope, and reference the
correct target) but the **business facts** it attests to are questionable given what the
projector can independently observe — e.g., the refund's `posted_at` falls after a return-window
policy period the projector can compute from the original's own timestamp, or the destination's
implied cashier lacks (per the projector's own permission read) the daily-cap-extension
permission the legacy controller would have required. Each alert is `{code, detail}`; the
append, like the late-sale flag, is wrapped in `try/catch` and **never** fails or dead-letters the
refund — booking always proceeds once the evidence itself (§4.2) is structurally valid. This is
the accept+flag disposition §5's rejection matrix formalizes.

### 3.5 `payments[]` — single cash leg, and the training-gate leak

`payments[]` on a launch v4 refund is exactly **one** entry: `method_code` resolving to the
tenant's cash payment method, `amount` equal to the (possibly rounded, §7.3) refund total,
`instrument_type: null`. No second leg, no card/voucher leg — enforced by the same key-set/enum
mechanism as every other exact-shape assertion in this contract; a second leg or a non-cash
`method_code` is a validation failure, not a silent truncation.

**Training-gate leak (treasury r2 Important, not previously ticketed):**
`TreasuryReceiptBridge`'s core Payment-row/GL-posting loop has no training gate at all — only
the supplementary rounding/tolerance entries are training-gated
(`TreasuryReceiptBridge.php:402`). §3's own §6.6 rule (kept from Revision 2: the device refuses
to author a refund against a training original) makes this unreachable for the v4 path
specifically, but the underlying bridge defect is real and broader than this feature. **Ticketed,
not fixed here**, per the orchestrator's instruction that the orchestrator will create it:
`docs/superpowers/tickets/2026-07-31-treasury-bridge-training-gate-leak.md`.

---

## 4. Settlement authority, durable intent, approval protocol (Codex r2 items 3–4)

### 4.1 Model 1, kept; payout moment, kept

Model 1 (immutable device-authored correction is authoritative; payout happens only after local
commit) is unchanged from Revision 2 — endorsed, not re-litigated. What changes here is making
the transaction order and the approval protocol actually executable, and adding the payout/print
durability Codex r2 found missing.

### 4.2 Approval protocol — deterministic source IDs, IN the manifest

Confirmed: `authorPosOverride()` generates `approvalId = crypto.randomUUID()` **internally**
(`posOverrideAuthoring.ts:70`) — the caller cannot know it in advance for a recovery lookup — and
the override event's `source_event_id` is `` `${input.targetReferenceId}:${input.approvalScope}` ``
(`:143-145`) — a composite string, **not a UUID**, while the server's ingestion envelope requires
every non-null `source_event_id` to be a UUID (`FiscalEventEnvelope.php:201-228`) stored in a
UUID column. Both approval events, as currently authored, cannot reliably sync or be recovered
by a stable, pre-known identifier.

**Fix: `posOverrideAuthoring.ts` is modified** (not "no changes," Revision 2's error) — 
`authorPosOverride()` gains an optional parameter:

```ts
export interface AuthorPosOverrideInput {
  ...
  /** When provided, both events use these caller-supplied UUIDs as source_event_id instead
   *  of generating one internally. Existing callers (discount/tender-tolerance overrides)
   *  omit this and keep today's exact behavior — byte-identical, zero regression risk. */
  sourceEventIds?: { approval: string; override: string };
}
```

Inside the function: `const approvalId = ...` (still the **payload's** `approval_id` field,
unchanged) but `source_event_id: input.sourceEventIds?.approval ?? approvalId` for the approval
append, and `source_event_id: input.sourceEventIds?.override ?? \`${input.targetReferenceId}:${input.approvalScope}\``
for the override append.

**The refund flow's new caller** (`authorRefundReturnApprovalV3()`, §17) pre-generates both UUIDs
at `refund_intents` row creation (§4.3, **before** the manager PIN is even entered) and stores
them as `refund_intents.approval_source_event_id` / `refund_intents.override_source_event_id` —
durable, known in advance, unaffected by restart.

**Exact recovery query**, closing Codex's "recovery is not implementable" finding:

```sql
SELECT * FROM fiscal_events
WHERE source_event_class = 'operator_approval' AND source_event_id = :approval_source_event_id;
SELECT * FROM fiscal_events
WHERE source_event_class = 'pos_override' AND source_event_id = :override_source_event_id;
```

Both against the device's local `fiscal_events` mirror — no network call, resolvable at any point
after `refund_intents` row creation regardless of what crashed and when.

**Projector-side verification** (closing Codex's "no linkage check" finding): `PosCoreReceiptProjection`,
when projecting a v4 REFUND event, resolves each entry in `approval_references[]` by
`approval_event_id`/`override_event_id` against the **actual** `fiscal_events` rows (not merely
syntactic shape validation, which is all `FiscalPayloadConstraintValidator.php:1046-1081` does
today) and asserts: the referenced events exist, are `OPERATOR_APPROVAL_GRANTED` /
`OVERRIDE_VOID_OR_RETURN` respectively, carry `approval_scope = 'void_or_return_override'`, and
their `payload.target.original_local_receipt_id` matches this refund's own
`original_receipt_reference`. A mismatch or missing reference is a **structural** authoring
defect (not a late business-policy question, §3.4) — it throws a new, non-retryable
`ApprovalEvidenceUnresolvedException` (§5's rejection matrix), because retrying cannot fix
evidence that was wrong at signing time.

### 4.3 Durable intent — append-first, correct transaction order

Revision 2's fatal bug: it stored `refund_fiscal_event_id` **before** calling `engine.append()`,
but the event's `id` does not exist until `engine.append()` generates it internally
(`FiscalEventEngine.ts:637`, `const id = generateUuidV4();`, well after validation/version
resolution/idempotency lookup/hash construction). **Corrected order, inside the same
`withWriteTransaction('fiscal', …)` call** (both statements execute against the same `tx`
parameter, so they commit or roll back together):

```ts
await withWriteTransaction('fiscal', async (tx) => {
  const appended = await engine.append(tx, {
    event_type: 'SALE_RECEIPT',
    payload: v4Payload,
    source_event_class: 'refund_intents',
    source_event_id: refundIntentId, // the INTENT's own stable UUID — known in advance
    ...
  });
  await updateRefundIntent(tx, refundIntentId, {
    refund_fiscal_event_id: appended.id, // only known NOW
    state: 'refund_event_appended',
  });
});
```

`source_event_id: refundIntentId` (the intent row's own PK, generated at `begin()`, §4.4) is what
makes the **append itself** idempotent on retry — `engine.append()`'s own
`(tenant_id, terminal_id, source_event_class, source_event_id)` dedup (`:576-588`) returns the
already-appended event on a retried call with the same intent ID, before ever reaching a second
`INSERT`. The `refund_fiscal_event_id` column is a **read-back convenience**, not the
idempotency mechanism.

### 4.4 `refund_intents` schema — payout/print durability, active-intent uniqueness

New SQLite table (device migration, §17), replacing Revision 2's under-specified version:

| Column | Purpose |
|---|---|
| `id` (UUID, PK) | Stable — this **is** the `source_event_id` for the refund's own `engine.append()` call |
| `original_local_receipt_id`, `original_fiscal_event_id` | Device-stable original identity |
| `line_snapshot_json`, `line_snapshot_fingerprint` (indexed) | The frozen `original_line_references[]`/`line_items[]` pair (§3.1) and a stable hash of it, used by the uniqueness rule below |
| `approval_source_event_id`, `override_source_event_id` | Pre-generated at row creation, §4.2 |
| `refund_fiscal_event_id` | Populated **after** `engine.append()` returns (§4.3), not before |
| `state` | `drafted → approval_authored → refund_event_appended → synced` (terminal for local purposes, §4.5), or `→ dead_lettered_local` (append itself permanently failed, no event exists — distinct from server-side dead-lettering, §5) or `→ abandoned` |
| `payout_confirmed_at` (nullable) | Set only by an explicit cashier action **after** `refund_event_appended` (§4.5) |
| `printed_at` (nullable) | Set when the AVOIR actually printed |
| `created_at`, `updated_at` | Recovery bookkeeping |

**Active-intent uniqueness** (closing Codex's "nothing prevents a second active intent"
finding): a partial unique index on `(original_local_receipt_id, line_snapshot_fingerprint)
WHERE state NOT IN ('applied', 'dead_lettered_local', 'abandoned')` — SQLite supports partial
unique indexes. A cashier retry, double-click, or reopened cart for the **same** original+line
selection reuses the existing active row (looked up by this same key before drafting a new one)
rather than creating a second one; a genuinely different line selection against the same original
is a different fingerprint and is allowed to proceed independently (a legitimate second partial
refund).

### 4.5 Payout/print reconciliation — the crash-around-handover window, named and closed

Codex r2's correct finding: `refund_event_appended` alone cannot distinguish "commit → crash →
no cash handed over" from "commit → cash handed over → crash." **New, explicit cashier
reconciliation screen**, shown on app start whenever any `refund_intents` row has
`refund_fiscal_event_id IS NOT NULL AND payout_confirmed_at IS NULL`: *"A refund for
[receipt/amount] was recorded but this device restarted before confirming the cash handover — did
you give the customer their cash?"* — **Yes** sets `payout_confirmed_at = now()` and proceeds
(the row's fiscal effect was already correct; this only closes the local bookkeeping gap);
**No / Not sure** sets a `payout_disputed_at` timestamp (new nullable column, same table) and
surfaces the row on the manager's shift-close checklist for manual reconciliation against the
physical drawer count — the fiscal event itself is never touched (it is immutable, §4.1), only
the *local* payout-confirmation bookkeeping is affected. This is the concrete state/screen the
orchestrator's item 4 requires; it is not deferred.

### 4.6 Local state stops at `synced` (the orchestrator's recommended launch choice, adopted)

Confirmed: `FiscalEventSyncResultItem` (`fiscalEventRepository.ts:62-71`) reports only
`stored`/`fiscal_event_id`/`sequence_conflict`/`exception_class` — ingestion acceptance, not
projection outcome — and `pushOfflineReceipts()` marks an event `synced` the moment ingest
accepts the envelope (`syncService.ts:382-399`). Projection status (`applied`/`dead_lettered`)
lives exclusively in server `fiscal_event_projections`, with no device-facing poll/push contract
today. **Ruling: the local `refund_intents.state` machine stops at `synced` for this launch.**
The device UI shows "refund submitted" once synced, and **never claims** local knowledge of
server-side booking success or failure — that is exclusively the server operator dead-letter
surface's job (§5.3, §17). Building a device projection-status pull contract is explicitly
deferred, not silently assumed.

---

## 5. Model-1 rejection matrix (Codex r2 item 5) — narrowed by §0's cash-only scope

With `original_payment`/`store_voucher` deleted from the launch payload (§0), the matrix Codex
audited collapses from eleven rows to five real classes. Every remaining class gets one of two
dispositions, both already grounded in an existing repository pattern:

| Class | Current repository behavior | Disposition | Mechanism |
|---|---|---|---|
| Local validation fails before SQLite commit | `FiscalEventEngine.ts:559-571` validates before insert | **Safe — no payout yet** | Retry authoring; nothing signed, nothing to compensate |
| Return window / daily cap / manager-threshold / disposition policy questionable | Legacy: synchronous reject. v4: no synchronous check exists | **Accept + flag** | §3.4's `refund_policy_alerts` — the late-sale-flag pattern, `PosCoreReceiptProjection.php:1051-1071` |
| Approval evidence structurally unresolved (non-UUID/missing/mismatched — §4.2's residual risk if the fix above is ever bypassed by a code defect) | Not enumerated before this revision | **Reject, permanent — compensate+write-off** | New `ApprovalEvidenceUnresolvedException`, non-retryable → §5.2 |
| Quantity cap exceeded / concurrent double-refund (S5, kept from Revision 2) | New permanent exception (§7.4) | **Reject, permanent — compensate+write-off** | §7.4's lock + §5.2 |
| Payment-method/repository/purpose-account config failure (POS or Treasury) | POS throws on unresolved method (`PosCoreReceiptProjection.php:846-880`); Treasury has multiple fail-closed dependencies | **Reject, permanent (after retry exhausts) — compensate+write-off** | Existing `catch (Throwable)` → dead-letter lifecycle → §5.2 |
| Ingress/quarantine (corrupted signature/canonical bytes) | Existing integrity workflow, unrelated to business logic | **Existing workflow, escalated to §5.2 if payout already confirmed** | A refund specifically (unlike a sale) may already have paid out cash before quarantine resolves — §5.2's write-off applies here too, not only to booking rejections |
| POS applies, Treasury dead-letters (sibling-projector partial success) | Independent jobs (`ApplyFiscalEventProjectionJob.php:324-410`; `TreasuryReceiptBridge.php:224-273`) | **Distinct operator-visible state; narrower write-off** — only the missing money/GL leg needs compensation, receipt/stock already correctly applied | §5.1's operator API distinguishes this state explicitly; §5.2's write-off, scoped to the money leg only |
| Original-receipt dependency temporarily missing (cross-device sync race — cannot occur for tenant #1's single terminal, kept for completeness since the guard/exception machinery is shared code) | `OriginalReceiptUnresolvableException extends ProjectionDependencyMissingException` | **Retry** | Existing Horizon retry-then-dead-letter; resolves automatically once the original syncs |

### 5.1 Operator dead-letter API (kept, extended for the partial-POS-applied state)

`GET /fiscal/dead-lettered-projections` / `{id}` (§17) now reports, per row, whether the
**sibling** POS projection already applied (distinguishing "nothing happened" from "receipt and
stock exist, money doesn't") — read from `fiscal_event_projections`, joined by `fiscal_event_id`
across both `POS` and `Treasury` projector rows.

### 5.2 S4 write-off — named purpose, entry shape, drawer adjustment, operator procedure (all IN
the manifest, treasury r2 I-6)

A dead-lettered refund whose `refund_intents.payout_confirmed_at IS NOT NULL` (§4.5) means cash
genuinely left the drawer with no corresponding books. This is **never automatic** — Model 1's
own philosophy (never silently alter signed intent's effective meaning) requires a human
decision. New operator action on the dead-letter API: `POST
/fiscal/dead-lettered-projections/{id}/write-off`, permission-gated, requiring the operator to
confirm they have reconciled the physical drawer:

- **New `SystemAccountPurpose` case: `RefundWriteOff`** — resolved via the same
  `GeneralLedgerService::hasAccountForPurpose()` company chart-of-accounts mechanism the existing
  `PaymentToleranceIncome`/`PaymentToleranceExpense` purposes already use (§7.3) — precheck before
  posting, same fail-closed pattern (missing account → the write-off action itself is refused
  with a clear "configure this account first" error, never a silent skip).
- **Compensating entry shape:** `Dr RefundWriteOff (expense) / Cr Cash` at the refund's signed
  amount — a genuine loss booking, not a reversal of the refund (the refund itself, and its
  original stock/receipt effects if the POS side already applied, stay exactly as signed; only
  the missing money leg is compensated).
- **Drawer adjustment:** a manual `CashDrawerService` operation recorded for the shift the
  payout actually happened in (read from `refund_intents`/the resolved event's `business_date`),
  so the physical drawer reconciliation isn't permanently orphaned by an entry the automated path
  never wrote.
- **Operator procedure**, named in the manifest as the exact sequence the dead-letter UI walks
  the operator through: (1) attempt `fiscal:retry-projections` first (the failure may be
  transient config, e.g. a missing purpose account that gets configured and retried
  successfully — no write-off needed); (2) only if retry is confirmed futile (a structural
  defect like `ApprovalEvidenceUnresolvedException` or a permanently-exceeded quantity cap),
  invoke the write-off action.

### 5.3 Server-side only — no device consumer needed (per §4.6's ruling)

The dead-letter surface and write-off action are pure operator/API surfaces; nothing in this
launch's device manifest consumes them, consistent with §4.6.

---

## 6. Verifier repair — per-row sealed-algorithm discriminator, not terminal-version branching
(fiscal C-2 / Codex r2 item 8)

**Revision 2's remedy was unsound, confirmed by direct verification of
`FiscalSchemaCutoverService.php`:** it upgrades a terminal's `fiscal_schema_version` from 2 to 3
**in place**, on an already-active terminal that may already carry prior, validly-v2-sealed
receipts — Gate 2 (`:73-97`) explicitly *permits* cutover with prior fiscalized receipts (only
requiring they're already Z-reported), and the mutation itself
(`$locked->fiscal_schema_version = 3; $locked->save();`, `:119-121`) is a live, in-place update
of the existing row. Branching the verifier on the terminal's **current** `fiscal_schema_version`
would therefore run `V3ReceiptHashComputer` over every pre-cutover v2-sealed row the instant that
terminal cuts over — breaking valid v2 history that was never touched by this feature at all.

**Correct fix: a per-row, immutable, sealed-algorithm discriminator, written once at seal time
and never re-derived from mutable state.**

- **New nullable column `pos_receipts.sealed_hash_algorithm`** (`'legacy_pipe_v1' |
  'canonical_v3'`), written by `ReceiptFinalizationService::finalize()` (`:97-103`) **at the same
  point** it already branches on `$terminal->fiscal_schema_version` to choose which hash function
  to call — the discriminator simply records which branch fired, permanently, on that row. Every
  future write through this path is self-describing from day one; only existing rows need
  backfill.
- **New migration** — adds the column only (fast, no data movement).
- **New Artisan command** (dual-recomputation backfill, `Fiscal/Infrastructure/Commands/`, §17):
  for every existing `fiscal_event_id IS NULL, sealed_hash_algorithm IS NULL` row, compute
  **both** the legacy pipe hash and the v3 canonical hash, compare each to the row's stored
  `fiscal_hash`; set the column to whichever matches. If **neither** or **both** match, the row
  is logged and skipped, never guessed — a genuine anomaly requiring manual fiscal-officer
  review, not an automated decision. Batched and idempotent (re-running only touches
  still-`NULL` rows), run as part of §9's rollout preflight, before the guard ships.
- **`ReceiptHashService::verifyLegacyArm()` and `Nf525DataProvider::verifyReceiptChain()`** both
  branch per-row on `$receipt->sealed_hash_algorithm` (a `NULL` value post-backfill is a hard
  verification **failure**, never a silent pass — fail-closed) to select the recomputation
  function, while **preserving the single ordered `$previousHash` walk exactly as it exists
  today** (`ReceiptHashService.php:315-350`; `Nf525DataProvider.php:380-446`) — only the per-row
  hash *computation* changes; the link-walk structure, which correctly spans a mixed-algorithm
  chain because chain linkage is algorithm-independent (each row's `previous_hash` just has to
  equal the prior row's `fiscal_hash`, regardless of which function produced either), is
  untouched.

**Tests** (all named, §17): pure-v2 legacy history (regression — must still pass unmodified);
pure-v3-hashed orphan (this feature's new case); a real v2→v3 cutover terminal with **both**
algorithms present in one ordered legacy chain (the case Revision 2's remedy would have broken);
tamper detection under each algorithm independently (2 tests); and an NF525-parity test asserting
`pos:verify-chains` and the NF525 verify-chains endpoint agree on the same terminal — this test
must also account for the one confirmed **asymmetry** between the two: `VerifyPosChainCommand`
filters `is_voided = false` (`:181-183`) while `Nf525DataProvider`'s legacy query does not filter
`is_voided` at all (treasury r2 M-3) — the parity test pins whether this is intentional (NF525
must see voided rows for audit completeness; the operational command need not) or is itself a
pre-existing bug, and states the answer explicitly rather than silently asserting equality on a
fixture that happens to have no voided rows.

---

## 7. Expected-cash and Z-report semantics (treasury r2 Critical + Importants)

### 7.1 The real bug was targeting dead code — corrected

Revision 2's fix targeted `ReportGenerationService::buildExpectedPerMethod()`. Confirmed by
direct verification: `generateXReport()`/`generateZReport()` (`ReportGenerationService.php`) both
call `assertServerReportAuthoringAllowed()` (`:69-73`), which throws
`ServerFiscalAuthoringRetiredException::zSessionDeviceAuthority(...)` whenever
`fiscal_schema_version >= 3` (`:94-108,159-169`) — **before** either entry point can ever reach
`buildExpectedPerMethod()` (`:219,486`, its only callers). This function is **entirely dead code
for v3/v4 terminals** — the exact "rule-20 trap" the orchestrator named. **This entry is removed
from the manifest entirely; fixing it would be fixing an unreachable path.**

### 7.2 The real path is 100% device-side

Confirmed: `ZReportProjection::legacyReportData()` (`:140-158`) reads `expected_cash` /
`actual_cash` / `variance` **directly from the device-authored `Z_REPORT` payload's own
`cash_count` block** (`$payload['cash_count']['expected_cash']` etc.) — for v3/v4, expected-cash
is computed **on the device**, signed into the Z_REPORT event, and the server projection is a
pure pass-through. There is no server-side computation to fix at all. The fix is entirely in
`apps/pos/src/lib/offline/zReportService.ts`, which is what actually computes the value passed
as `expectedCash` into the Z_REPORT payload builder (`zSessionAuthoring.ts:412,457` — `expected_cash:
input.expectedCash`, a parameter, confirming the computation lives in its caller).

**Fix (§17): `refund_intents` gains `shift_id`, `refund_total`, and `cash_impact` columns** —
`cash_impact` is the **rounded payout amount** (§7.3's sign note), computed once at
`refund_event_appended` time and frozen (never recomputed from a live join, matching the
already-frozen `line_snapshot_json` pattern, §4.4). `zReportService.ts`'s expected-cash
computation (replacing `local_refund_records`/`cashRefundImpact` entirely, per Revision 2's §5.4,
kept) sums `refund_intents.cash_impact` for the shift and **subtracts** it from the cash-in side.

### 7.3 Sign convention (treasury r2 Important, previously unstated)

**One rule, stated once:** the refund's `cash_impact` is computed and signed **in the same frame
as a sale's cash total** — a sale's cash tender is a positive contribution to expected cash; a
refund's cash payout is booked as a **negative** contribution (i.e., `cash_impact` is stored as a
positive magnitude, and the aggregation subtracts it, exactly mirroring how
`TreasuryReceiptBridge::postCashRoundingEntry()`'s `$isRefund` flag already selects the opposite
GL direction for the same underlying `cash_rounding_adjustment` value, `:395-470`, kept unchanged
from Revision 2, §7.4 below) — one sentence, one convention, applied consistently at both the
device Z aggregation and the (unchanged, already-correct) GL bridge.

### 7.4 GL — unchanged, still correct (kept from Revision 2)

`TreasuryReceiptBridge::postCashRoundingEntry()` already runs for both sale and refund receipts,
already selects `pos_cash_rounding_refund` via `$isRefund` (`:431`), already has a
probe-before-create idempotency guard backed by a DB partial unique index, and already has a
passing symmetric-refund test (`TreasuryReceiptBridgeRoundingGlTest.php:233-269`) — **no change,
no new source type**, confirmed correct by treasury r2's own scorecard ("CLOSED").

### 7.5 `CashDrawerService` — ticketed, not fixed here (treasury r2 Important)

Confirmed (kept from Revision 2's finding, restated precisely): `CashDrawerService::calculateExpectedCash()`
is called unconditionally for every shift regardless of schema version
(`ReportGenerationService.php:204`, `ShiftManagementService.php:170`,
`CashDrawerController.php:222`), but neither `PosCoreReceiptProjection` nor
`TreasuryReceiptBridge` ever creates a `CashDrawerOperation` row for any v3/v4 event — this
function is silently blind to *all* v3 activity today, sale or refund, independent of this
feature. **This is a pre-existing, broader defect this spec does not introduce and does not fix**
— ticketed for a follow-on, not silently left unaddressed:
`docs/superpowers/tickets/2026-07-31-cashdrawerservice-v3-expected-cash-blind.md` (path named per
the orchestrator's instruction that the orchestrator creates it).

### 7.6 v2/v3 Z sign divergence — documented non-meeting (treasury r2 Minor)

The v2 legacy Z-report and the v3/v4 device-authored Z-report compute/sign expected cash and
refund totals through **entirely separate code paths** (`ReportGenerationService`'s legacy
arithmetic vs. the device's own `cash_count` block) with no shared convention beyond §7.3's
one-sentence rule applying only within the v3/v4 side. **This is stated explicitly as a known,
accepted non-meeting between the two report generations — not a bug this feature must reconcile
—** since v2 and v3/v4 terminals never share a shift or a report.

---

## 8. VOID — fail-closed at both authoring boundaries (Codex r2 item 6, orchestrator item 3)

**One ruling, restated with the contradiction actually fixed:** VOID is prohibited from
device-authored v4 scope, at **both** boundaries, with no positive VOID vector anywhere in the
contract:

1. **Device boundary** (new, §2.1): `FiscalEventPayloadRegistry.eventVersionFor('SALE_RECEIPT',
   payload)` throws `VoidAuthoringProhibitedError` for `invoice_type_code = 'VOID'` — before
   validation, before any state mutation. Negative test in `FiscalEventEngine.test.ts` (§2.2)
   proves no `fiscal_events` row is ever written for an attempted VOID append.
2. **Server boundary** (new): the server-side `FiscalEventPayloadRegistry.php`/
   `FiscalPayloadConstraintValidator.php` version-resolution for `event_version = 4` **explicitly
   rejects** `invoice_type_code = 'VOID'` at parse time (a defense-in-depth backstop for any
   client that bypasses the device guard — e.g. a compromised or pre-fix build) — new negative
   golden vector (§17) proving a `VOID`-discriminated v4 payload is rejected, replacing
   Revision 2's contradictory positive VOID-vector prose entirely. **There is no v4 VOID golden
   fixture** — only a rejection test.

**§4.3-vs-old-§6.4 contradiction, fixed:** the guard that gates the legacy `/return`/`/void`
endpoints off for `fiscal_schema_version >= 3` terminals (§9.1's single shared helper) applies
identically to both endpoints; the operator workflow for what would previously have been a VOID
on a v3/v4 terminal is "process it as a full-line REFUND to cash" (§0/§3's launch scope; for
tenant #1 the original tender is always cash, so this workflow has no destination mismatch to
resolve — the broader "what if the original wasn't cash" case moves to §16.4 with the fallback
contradiction the r2 reviews found attached).

---

## 9. Rollout — capability state, exact files, atomicity, Lane B baseline (Codex r2 items 9;
fiscal I-8; treasury "v3-guard-removes-all-legacy-paths")

### 9.1 Guard — one shared helper, one status code, one error code

**New shared file** `apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php` —
a single method `assertLegacyCorrectionAllowed(Terminal $terminal): void`, called at the top of
both `ReceiptReturnService::processReturn()` and `ReceiptVoidService::voidReceipt()`, throwing a
new `LegacyCorrectionRetiredException` when `(int) ($terminal->fiscal_schema_version ?? 2) >= 3`
— mirroring the existing `ShiftController.php:60,124` / `SyncController.php:53` /
`ZReportSyncController.php:75` branch pattern exactly. **HTTP status: `409`** — chosen to match
the existing device-authority-retirement convention already in the repository
(`ServerFiscalAuthoringRetiredException` in `ReportGenerationService`, §7.1, is the same class of
"this authority moved to the device" condition and is the direct precedent for the status code
choice). **Error code:** `LEGACY_CORRECTION_RETIRED`, carrying `{ terminal_id,
fiscal_schema_version }` in the response body for client-side typed handling.

### 9.2 Capability state — the exact files that make a disabled rollout enactable

Revision 2's fatal gap here: the only capability field was on an endpoint (§0 deletes the
cross-terminal lookup entirely) the fully-offline same-device path never calls, so a disabled
device build had no way to learn it was disabled. **Fix, named exactly:**

- **New column `pos_terminals.v4_refund_authoring_enabled`** (boolean, default `false`) —
  server-side per-terminal flag, flipped by an operator action (part of §9.3's rollout sequence)
  after §6's verifier repair and §2's payload-contract deploy are both live and preflighted.
- **`TerminalResource.php`** — add `v4_refund_authoring_enabled` to the JSON already returned by
  the existing terminal-state endpoint `pullTerminalState` already calls
  (`syncService.ts:1257-1338`) — no new endpoint, no new network call in the offline path; the
  field rides the sync the device already performs routinely.
- **Device `terminal_state` table** gains a matching `v4_refund_authoring_enabled` column
  (new migration, alongside §4.4's `refund_intents` migration) — read by `pullTerminalState`
  (`syncService.ts`, extended) into the existing upsert.
- **The refund flow's entry point** (`classifyCartForCheckout`'s `'refund'` → `'start-refund'`
  dispatch, unchanged routing logic, §0 of Revision 1) checks this local cached field **before**
  drafting a `refund_intents` row — if disabled, the cashier sees the same typed guard experience
  §9.1 defines server-side, entirely client-side, with zero wasted local commits or network
  calls.

### 9.3 Sequencing — the step-5/atomicity contradiction fixed

Revision 2's rollout step 5 said the guard (§9.1) and the capability enablement (§9.2) "must not
create a window" but described them as separately sequenced steps, which Codex correctly read as
self-contradictory. **Fixed: the guard and the capability-enablement flip for a given terminal
cohort are the SAME deploy action, applied in the SAME migration/operator-script run** — the
`v4_refund_authoring_enabled` flip and the `LegacyCorrectionRetiredException` guard's effective
scope (which is unconditional on `fiscal_schema_version`, not on the new flag — the guard is
always-on for v3+ once deployed, §9.1) are deployed as one server release; there is no
per-terminal "guard on, capability still off" gap, because the guard's condition never depends on
the capability flag at all — it depends only on `fiscal_schema_version`, which was already true
before this feature existed. The only thing the capability flag gates is whether the **device**
attempts the *new* path; it never re-opens or narrows the *legacy* guard's scope.

### 9.4 Legacy-path blast radius — v2 originals, ex-VOID, on a v3+ terminal (treasury r2
Critical)

**Stated plainly, not silently absorbed:** once §9.1's guard ships, **there is no refund path at
all on a v3/v4 terminal for a v2-sealed original** — the entire pre-cutover receipt population on
any terminal that has ever run `FiscalSchemaCutoverService` (§6). For **tenant #1 this is
structurally impossible** (provisioned at v3 day one, never has v2 history, §0) — but staging and
any future demo/cutover terminal **do** carry this exposure. **Explicit caveat and operator
workaround, not deferred:** on a cutover terminal, a refund against a pre-cutover (v2) original is
**out of scope until the post-launch roadmap (§16.2/§16.5) lands** — the documented operator
workaround is to process such a refund through the legacy endpoint's inverse: since the guard
blocks `ReceiptReturnService`/`ReceiptVoidService` unconditionally for `fiscal_schema_version >=
3`, and there is no destination-specific carve-out (§0 deliberately removes the store-voucher
carve-out contradiction Revision 2 had), the only correct workaround for a genuinely pre-cutover
original on a cutover terminal for now is a **manual, off-system correction** (documented
accounting adjustment + physical cash handling outside the POS, logged by the operator) —
stated here as an explicit, acknowledged launch-time limitation for non-tenant-#1 terminals, not
silently absorbed into "the guard handles it."

**Retiring `refundZAccounting.ts`/`local_refund_records`** (kept from Revision 2) similarly
leaves any *surviving* legacy-path refund (i.e., the manual-workaround case above, if it is ever
routed back through the legacy endpoint on a non-guarded, pre-launch-guard window) with no device
Z record — acceptable only because §9.1's guard is unconditional the moment this feature ships;
there is no window where the legacy endpoint runs on a v3+ terminal without `refundZAccounting.ts`
already having a live device path — but this is named explicitly as a sequencing dependency the
rollout (§17's manifest) must not violate: the `refundZAccounting.ts` retirement and the §9.1
guard ship in the **same** release.

### 9.5 Rollout sequence (kept from Revision 2, restated with the fixes above folded in; Lane B
baseline corrected)

**Baseline correction:** Lane B is **already merged** at `4bb87b483` on this checkout (confirmed:
`git show --stat --oneline 4bb87b483` touches only `apps/pos/eslint.config.js` and three test
files — it never touched the registry, server validator, canonical DTO/reader,
`FiscalPayloadKeyDrift.test.ts`, or the golden-fixture corpus). §17's manifest lists the **exact
current file paths**, not a post-merge-location placeholder.

1. Server-first: §2's payload contract, §6's verifier repair (including the backfill command run
   against the actual eight staging receipts and every demo tenant), §3's projector policy/
   evidence logic — all shipped with `v4_refund_authoring_enabled = false` everywhere.
2. Disabled device rollout — new build ships; §9.2's local capability check keeps it inert.
3. §9.1's guard and §9.3's capability flip ship together, per terminal cohort, coordinated with
   confirmed minimum client version on that cohort.
4. Cloned-staging tests (§1.1's scenario) before any production terminal's cohort flip, covering
   both `current_sequence` topologies.
5. Absolute prohibitions (kept, unchanged): never rewrite/backfill any existing `fiscal_events`/
   `pos_receipts` row; never reset or copy server counters into a device's `terminal_state` chain
   head. On the latter: confirmed precisely (not "unconditional" as Revision 2 overclaimed) —
   `pullTerminalState`'s `upsertTerminalState` call is guarded by `FiscalRegressionError`
   (`terminalStateRepository.ts:231-245`): when the local `hash_sequence` is already ahead of the
   server's, the **entire** upsert is rejected and skipped (not partially applied) — `hash_sequence`/
   `last_hash` are only overwritten when the server's value is not behind the device's own. This
   rollout must not introduce any code path that weakens or bypasses that guard.

---

## 10. `store_voucher` — excluded from the enum, plus a proven-unreachable defense-in-depth gate
(treasury r2 Critical, Codex r2 item 6)

§0 removes `store_voucher` from the launch `refund_destination` type entirely — a v4 refund
payload cannot express it. But the treasury r2 review found a **separate, real** bug this
exclusion alone does not close: `PosCoreReceiptProjection`'s `redeemVouchers()` is called
**unconditionally** for every `store_voucher`-instrument payment leg, for both SALE and REFUND
payloads (`:405`, `:941-966`) — if an original sale was **part-paid** by a store voucher, and a
refund is later authored against it, any code path that (incorrectly) echoed the original's
voucher leg into the refund's `payments[]` would cause `redeemVouchers()` to burn that voucher a
**second** time.

**Proof this is unreachable under this launch's exact schema, not merely an assertion:** §3.5
requires `payments[]` to be **exactly one cash leg** — the v4 validator (both TS and PHP) rejects
any payload with a second leg or a non-cash `method_code` outright, at the same
`assertExactKeySet`-adjacent validation layer that already exists. A `store_voucher`-instrument
leg is therefore structurally impossible to construct in a way that reaches
`PosCoreReceiptProjection::writePayments()` at all for a v4 REFUND — the loop that calls
`redeemVouchers()` never receives a voucher-typed leg from this payload shape.

**Defense-in-depth gate added anyway** (the orchestrator's explicit instruction — "prove that,"
not "assert that, so skip the code"): `PosCoreReceiptProjection::redeemVouchers()` gains an
`invoice_type_code !== 'REFUND'` guard at its own call site (mirroring the existing
`earnLoyaltyPoints` gate, which already checks `$receiptType !== ReceiptType::Sale` at `:987`) —
so that even a **future** schema change that widened `payments[]` (§16.3's post-launch voucher
work) cannot silently reintroduce the double-burn without an explicit, reviewed removal of this
gate. New test (`PosCoreReceiptProjectionVoucherRefundNoRedemptionTest.php`, §17) constructs a
malformed/hand-built fiscal event (bypassing the payload validator entirely, simulating "what if
the gate is the last line of defense") with a REFUND `invoice_type_code` and a `store_voucher`
leg, and asserts `redeemVouchers()` is never called — proving the gate independently of whether
the schema-level exclusion holds.

---

## 11. Constraints honored (kept, unchanged from Revision 2)

- `hydrateFromReceipt.ts` never reads `receipt.total` — confirmed unchanged; **now also carries
  `discount_amount`/`discount_reason` through** (§3.3's fix — an addition, not a violation of
  this constraint, since it still never reads a receipt-level total).
- Quantity capping preserved via §12's server-side lock + per-`original_line_index` accounting.
- The AVOIR carries a rounding line unconditionally (kept from Revision 2, §7 unaffected).

---

## 12. Quantity cap — reverse lookup on existing schema (orchestrator item 8, fiscal I-4)

**Confirmed: no new index or column is needed for the reverse lookup itself** — both already
exist. `pos_receipts` already has a partial index `idx_pos_receipts_original_receipt ON
pos_receipts (original_receipt_id) WHERE original_receipt_id IS NOT NULL`
(`2026_03_09_200000_add_return_fields_to_pos_receipts.php:84`), and
`PosCoreReceiptProjection.php:353` already writes `'original_receipt_id' => $originalReceiptId`
(the resolved original's `pos_receipts.id`) on every projected return row today. Separately,
`pos_receipt_lines.original_line_id` already exists as an FK column
(`2026_03_11_300000_add_original_line_id_to_pos_receipt_lines.php`), currently populated only by
the legacy return path — **the v4 projector writes it too**, resolving
`original_line_references[i].original_line_index` (§3.1) to the actual `pos_receipt_lines.id` of
the corresponding original line, reusing the existing column rather than inventing a new one.

**Lock + query, exactly:**

```sql
-- 1. Serialize all concurrent refund projections against the same original.
SELECT * FROM pos_receipts WHERE id = :originalReceiptId FOR UPDATE;

-- 2. Already-refunded quantity per original line, now that the lock is held.
SELECT prl.original_line_id, SUM(prl.quantity) AS already_refunded
FROM pos_receipt_lines prl
JOIN pos_receipts pr ON prl.receipt_id = pr.id
WHERE pr.original_receipt_id = :originalReceiptId
  AND pr.receipt_type = 'return'
  AND pr.is_voided = false
GROUP BY prl.original_line_id;
```

Both run inside the wrapping `DB::transaction()` the projection already opens; the `FOR UPDATE`
on step 1 is what makes step 2's read authoritative against any concurrent projection job for the
same original — the second-arriving transaction blocks on step 1 until the first commits or
rolls back, then re-reads step 2's now-updated aggregate. **PG-mode test required** (fiscal I-4):
this lock's actual serialization behavior cannot be proven under SQLite's connection model —
`PosCoreReceiptProjectionRefundQuantityCapTest.php` (§17) runs two concurrent projection jobs
against the same original in Postgres test mode and asserts exactly one succeeds while the other
observes the updated aggregate and is correctly capped or rejected, not merely that the
SQL text is well-formed.

---

## 13. Cross-terminal lookup — deferred (orchestrator item, §0)

Not built for this launch. Tenant #1 has exactly one terminal (§0); there is no cross-terminal
case to resolve. A local-miss on the original-lookup (§4's local `fiscal_events` query,
unchanged from Revision 2) is a **typed device-side refusal** with operator guidance ("original
receipt not found on this terminal — for tenant #1 this should not happen; contact support") —
no online lookup endpoint, no capability/version negotiation, no anti-enumeration contract. The
full contract Codex r2 §7 required for the general case is preserved verbatim in §16.2 for the
post-launch roadmap, since it will be needed the moment a second terminal is provisioned for any
tenant.

---

## 14. v2 originals — impossible for tenant #1 (§0, §9.4)

Restated for completeness: tenant #1's terminal is provisioned at `fiscal_schema_version = 3`
from creation (Lane D1's provisioning work, referenced not owned by this spec) and will never
have a v2-sealed original, by construction. §9.4 states the blast radius and workaround for
terminals where this is *not* true (staging/demo/any future cutover terminal).

---

## 15. Wording and citation fixes (orchestrator item 11, Codex r2 items 11–12)

- VOID removed from the orphan-verifier symptom sentence (§1).
- `TerminalController.php` counter cites confirmed exact at `:120`, `:395`, `:458` (re-verified
  in this worktree, not paraphrased ranges).
- `pullTerminalState`'s `hash_sequence`/`last_hash` guard restated precisely as conditional, not
  unconditional (§9.5).
- §9.1's guard is now the single cross-referenced section from every place that needs it (§8's
  VOID ruling, §9.4's blast-radius note, §1.1's acceptance test) — no dangling `§2.4` references
  remain (that section number does not exist in this revision's structure).
- `refund_destination` is the single literal `'cash'` everywhere in this document — no remaining
  2-vs-3-value contradiction.
- Training-original refusal (kept from Revision 2, device-side, unchanged) and the bridge
  training-gate leak are two different things, now stated as such (§3.5) with the leak ticketed
  separately rather than conflated with the refusal rule.

---

## 16. Post-launch roadmap (NON-NORMATIVE — nothing here is dispatchable; every item carries the
r2 review findings so follow-on work starts informed, not from scratch)

### 16.1 `original_payment` destination

Deleted from launch scope (§0). r2 findings to resume from: `treasury_payment_id` is never
written by the bridge (only writer, `ReceiptPaymentService`, is retired/unreachable) — a true
silent no-op, not merely incomplete; `PaymentRefundService`'s real proration order/residual-leg
rule is the **opposite** of what Revision 2 proposed (opposite operand order, `scale+10`
intermediates, residual to the **smallest** leg not the largest, device `decimal.ts` defaults to
scale 3 — a rule-19 precision trap); no canonical-ordinal-to-Treasury-Payment-ID mapping exists;
and calling `PaymentRefundService` **and** letting the bridge write its own legacy leg would
double-book (`+X` POS row and `-X` Refund row, one physical repository movement) — any future
design must pick exactly one writer, not both.

### 16.2 Cross-terminal lookup

Full contract as drafted in Revision 2 §4.4 (endpoint, authz, anti-enumeration, eligibility
proof, response payload, capability/version field, race handling) is a reasonable starting point
but was found under-specified by Codex r2 §8 on the "uniform response but UI distinguishes a
cause" contradiction and the "GET cannot hold a row lock through offline authoring" advisory-only
correction — both must be resolved before this ships, not merely inherited.

### 16.3 `store_voucher` destination (issuance)

The chicken-and-egg unminted-serial problem (§0) needs a genuinely different protocol — most
likely a two-phase issuance (device pre-generates a voucher-code UUID, signs it into the refund
event, server activates it on projection) — not attempted here.

### 16.4 VOID full integration

If ever undertaken: `PosCoreReceiptProjection` maps both REFUND and VOID to `ReceiptType::Return`
and always writes `is_voided = false`; `Nf525DataProvider` buckets strictly by `is_voided = true`
for its ANNULATION query; `ReportGenerationService` counts `voidedCount` only on `is_voided` —
none of these are VOID-correct today and all three need new branches, plus an entire POS VOID
authoring surface that does not exist post-Phase-6.

### 16.5 v2-original refunds on a cutover terminal

The blast radius named in §9.4 needs an actual designed path (most likely: allow the legacy
endpoint to remain live specifically for `fiscal_event_id IS NULL` originals even on a v3+
terminal, via a destination/origin-specific carve-out in §9.1's guard, rather than the
terminal-wide unconditional block this launch ships with) — deferred because tenant #1 does not
need it, not because it is unimportant for future cutover tenants.

---

## 17. Frozen code-phase write manifest (exact — no alternatives, no "e.g.", no TBD, no
already-complete or no-change entries)

**`apps/api` — payload contract:**
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` — accept
  `event_version ∈ {1,2,3,4}` for `SALE_RECEIPT`; reject `invoice_type_code = 'VOID'` at v4
  resolution (§8).
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` —
  `SALE_RECEIPT_PAYLOAD_KEYS_V4` exact key set; §3.1's parallel-array invariants; §3.2's
  single-literal `refund_destination` + mandatory-null `settlement_allocation`; §3.5's
  single-cash-leg `payments[]` assertion; VOID rejection at v4.
- `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php` (`:62-116`) —
  construct the new typed nested values (`original_line_references[]`, `refund_destination`).
- New DTO files: `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/OriginalLineReferenceDTO.php`.
- `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php`,
  `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php` — extend for
  the v4 fields (exact current paths, no "post-merge location" hedge — Lane B never touched
  these, §9.5).
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — v4 cases; VOID
  rejection case; preserve the existing `assertCount(14, ...)` corpus assertion, extended to
  `assertCount(15, ...)` for the new fixture below.
- New golden fixture: `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-16-refund-v4-cash-eur/`
  with **both** `payload.json` and `expected.json` (F-10 is taken by `F-10-void-eur`; F-15 is
  occupied on disk by `F-15-large`, not yet in the builder — F-16 is the confirmed next-free
  number). `GoldenFixtureBuilder.php` — add the `F-16` case.
- New PHP/TS parity test: `apps/api/tests/Feature/Fiscal/CanonicalByteHashV4ParityTest.php` (PHP
  side) and `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.parity.test.ts`
  (TS side) — both assert identical canonical bytes/hash for the F-16 fixture.

**`apps/api` — engine/registry-adjacent projector/business logic:**
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` — §3.1's
  original-line resolution + `pos_receipt_lines.original_line_id` write (§12); §3.4's
  `refund_policy_alerts` write; §4.2's approval-evidence resolution/verification +
  `ApprovalEvidenceUnresolvedException`; §10's `redeemVouchers()` `invoice_type_code !== 'REFUND'`
  guard; §12's `FOR UPDATE` lock + quantity-cap check + `RefundQuantityExceededException`;
  disposition-aware stock restore (replacing unconditional restock); training-original defense
  check.
- New migration: add `pos_receipts.refund_policy_alerts` (JSONB, nullable).
- New exception files: `apps/api/app/Modules/Fiscal/Domain/Exceptions/RefundQuantityExceededException.php`,
  `apps/api/app/Modules/Fiscal/Domain/Exceptions/ApprovalEvidenceUnresolvedException.php`.
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`,
  `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` — call
  `LegacyCorrectionGuard::assertLegacyCorrectionAllowed()` (§9.1, new file below).
- New file: `apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php` (§9.1).
- New exception: `apps/api/app/Modules/POS/Domain/Exceptions/LegacyCorrectionRetiredException.php`.
- `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php` — map
  `LegacyCorrectionRetiredException` to HTTP 409, `LEGACY_CORRECTION_RETIRED` (§9.1).
- New migration: add `pos_terminals.v4_refund_authoring_enabled` (boolean, default false).
- `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` — expose
  `v4_refund_authoring_enabled` (§9.2).
- New migration: add `pos_receipts.sealed_hash_algorithm` (nullable string enum, §6).
- `apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php` — write
  `sealed_hash_algorithm` at seal time (§6).
- `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` — `verifyLegacyArm()`
  branches on `sealed_hash_algorithm`, fail-closed on `NULL` (§6).
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` — identical repair in
  `verifyReceiptChain()`'s legacy-row loop (§6).
- New Artisan command: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/BackfillSealedHashAlgorithmCommand.php`
  (§6's dual-recomputation backfill).
- New Artisan command: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/InventoryV3LegacyCorrectionsCommand.php`
  (§9.5 step 1 preflight/inventory of existing orphan rows, dead-lettered projections, cutover
  history).
- New operator API: `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
  (list + detail + `write-off` action, §5.1/§5.2), route entries added to
  `apps/api/app/Modules/Fiscal/routes.php` (existing file, existing middleware stack `['api',
  'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` + per-route
  `->middleware('can:fiscal.refunds.manage_dead_letters')`, matching the module's existing
  `can:` per-route pattern).
- New file: `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` — add
  `RefundWriteOff` case (existing enum file, one new case, §5.2).
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` — add the
  `RefundWriteOff` compensating-entry builder (mirrors `createPosCashRoundingEntry()`'s shape).
- Tests: `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php` (§1.1, PG-mode);
  `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3VoidGuardTest.php` (v2-non-regression);
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundQuantityCapTest.php` (PG-mode
  concurrency, §12); `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundPolicyAlertTest.php`
  (§3.4); `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionApprovalEvidenceTest.php` (§4.2);
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionVoucherRefundNoRedemptionTest.php` (§10);
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTrainingRefundRefusedTest.php`;
  `apps/api/tests/Feature/POS/ReceiptHashServiceVerifyLegacyArmV4Test.php` (pure-v2, pure-v3-orphan,
  mixed-cutover, tamper×2, §6); `apps/api/tests/Feature/Fiscal/Nf525VerifyChainParityTest.php`
  (§6's NF525-parity + `is_voided` asymmetry test); `apps/api/tests/Feature/Fiscal/BackfillSealedHashAlgorithmCommandTest.php`;
  `apps/api/tests/Feature/Fiscal/InventoryV3LegacyCorrectionsCommandTest.php`;
  `apps/api/tests/Feature/Fiscal/DeadLetteredProjectionsControllerTest.php` (list/detail/write-off,
  including the fiscal `can:` middleware assertion); `apps/api/tests/Feature/POS/LegacyCorrectionGuardTest.php`
  (409 + `LEGACY_CORRECTION_RETIRED` on both endpoints).
- Every new bcmath comparison carries a `// precision-ok: scale-4` (quantity) or currency-scale
  marker per rule 19; every new projection test calls `app(CompanyContext::class)->clear()`
  before `apply()` per rule 20, passing entity currency explicitly.

**`apps/pos`:**
- `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts` — §2.1's exact `eventVersionFor(type,
  payload)` change; new `VoidAuthoringProhibitedError`.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` — §2.1's threaded `eventVersion` through
  `append()` → `validateRequestPayload()` → `validateSaleReceiptPayload()`; new
  `SALE_RECEIPT_PAYLOAD_KEYS_V4` const.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — extended (§2.2).
- New file: `apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` (§2.2).
- `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — extended for V4 parity
  (§2.2), existing V1/V2/V3 assertions preserved verbatim.
- New payload builder: `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts` — composes
  through `buildSaleReceiptV3Payload` (kept from Revision 2's §2.1 chokepoint ruling), never
  forks it; implements §3.1–§3.5's exact field contract.
- `apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts` — §3.3's discount fix (new
  `discount_reason` on `OfflineReceiptLine`; both fields mapped onto the returned `CartItem`).
- `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts` — add
  `resolveOriginalFiscalEventLocally()` (original + its `line_items[]`/`payments[]`/
  `training_flag` for index-based reference resolution).
- New SQLite migration (single migration, both tables): `apps/pos/src/lib/db/migrations.ts` —
  new `refund_intents` table (§4.4, including `payout_confirmed_at`/`payout_disputed_at`/
  `printed_at`/`shift_id`/`refund_total`/`cash_impact` columns and the partial unique index,
  §4.4/§7.2), new `terminal_state.v4_refund_authoring_enabled` column (§9.2).
- New test: `apps/pos/src/lib/db/__tests__/migrations.v65.test.ts` (or the next free version
  number at implementation time — the repository's confirmed `migrations.vNN.test.ts` pattern,
  §9.2/§4.4's migration).
- New file: `apps/pos/src/lib/db/repositories/refundIntentRepository.ts`.
- New file: `apps/pos/src/lib/offline/refundReceiptService.ts` — §4.3's corrected append-first
  write-gate transaction.
- `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts` — §4.2's optional
  `sourceEventIds` parameter (backward-compatible; existing callers unaffected).
- `apps/pos/src/lib/operatorApproval/__tests__/posOverrideAuthoring.test.ts` — extended: existing
  callers' behavior unchanged (regression); new deterministic-source-ID path asserted.
- New file: `apps/pos/src/lib/refundFlow/refundApprovalV3.ts` — `authorRefundReturnApprovalV3()`
  (§4.2), replacing `refundApproval.ts`'s server-ID-bound helper for the v4 path.
- `apps/pos/src/lib/refundFlow/refundSettlementService.ts` — retained **only** as the legacy-path
  caller for the §9.4 manual-workaround/off-system-correction case's UI, not the default path for
  any tenant-#1 refund; its online-only `prepareRefundSettlement()` is not invoked by the new
  same-device flow.
- `apps/pos/src/stores/refundCheckoutStore.ts` — rewritten around `refund_intents` state
  transitions (§4.3–§4.6), including §4.5's crash-recovery reconciliation screen trigger.
- New component: `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx` (§4.5's
  named cashier reconciliation screen).
- `apps/pos/src/lib/refundFlow/refundZAccounting.ts`,
  `apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts` — both deleted, replaced by
  `refundIntentRepository.ts` reads.
- `apps/pos/src/lib/offline/zReportService.ts` — §7.2's `refund_intents.cash_impact`-based
  expected-cash computation (replacing `local_refund_records`), §7.3's sign convention.
- `apps/pos/src/lib/offline/endOfDayPreview.ts` — same rewiring (§7.2).
- `apps/pos/src/lib/sync/syncService.ts` — `pullTerminalState` extended to sync
  `v4_refund_authoring_enabled` (§9.2).
- `apps/pos/src/lib/buildReceiptData.ts` — unconditional AVOIR rounding line (kept from
  Revision 2).
- `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json` — new keys inside the
  **existing** `"refundFlow": { ... }` object (both files already contain this namespace at
  `:120` — no new file, no new directory) for: the §9.1 409 legacy-guard message, the §3.3
  whole-receipt-discount partial-refund refusal, the §6.6 training-refusal message (kept), the
  §4.5 payout-reconciliation modal's copy.
- Tests: `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.test.ts` (invariant/
  bind coverage including §3.1's parallel-array assertions and §3.3's discount cases);
  `apps/pos/src/lib/refundFlow/__tests__/hydrateFromReceipt.discount.test.ts`;
  `apps/pos/src/lib/db/repositories/__tests__/refundIntentRepository.test.ts` (including the
  active-intent uniqueness index, §4.4); `apps/pos/src/lib/offline/__tests__/refundReceiptService.test.ts`
  (§4.3's append-first ordering, asserted directly); `apps/pos/src/stores/__tests__/refundCheckoutStore.test.ts`
  (rewritten for the new state machine, including §4.6's stop-at-`synced` assertion);
  `apps/pos/src/components/pos/__tests__/RefundPayoutReconciliationModal.test.tsx`;
  `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts` — extended (Lane B's
  file, never forked) with refund cash-impact line items (§7.2).

**Explicitly NOT touched:** `apps/pos/src/lib/payment/cashRounding.ts` (read-only dependency);
`apps/api/app/Modules/Voucher/Application/Services/VoucherIssuanceService.php` (unchanged —
voucher refunds are §16.3, not launch scope); `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
(confirmed dead code for v3/v4, §7.1 — no write); `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php`
(unchanged — §16.1, not launch scope); the VOID authoring surface (does not exist, is not
created, §8).
