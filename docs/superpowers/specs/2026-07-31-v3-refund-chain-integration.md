# v3 Refund/Void Chain Integration — Design Spec (REVISION 2)

**Lane:** C (first-tenant launch program, `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md`
§Lane C). **Phase:** SPEC ONLY — zero code changes in this commit.
**Revision 1** (`e294a2df6`) was **REJECTED ×3** (fiscal-pos-reviewer, treasury-reviewer, Codex
adversarial pass) — `docs/superpowers/reviews/2026-07-31-lane-c-spec-fiscal-treasury-reviews.md`,
`docs/superpowers/reviews/2026-07-31-codex-refund-chain-spec-review.md`. The chain-design
*direction* (device-authored `SALE_RECEIPT` + `invoice_type_code` correction, not a parallel
chain) was endorsed by all three. The failure-mode narrative, the money layer, and payload
feasibility were not. This revision closes every one of the 12 required re-work items and the
treasury reviewer's 6 money Criticals. Every citation was re-verified in this worktree; ~25
Revision-1 citations that had drifted or were simply wrong are corrected in place rather than
listed separately, per Codex's own recommendation that stale citations be fixed, not
catalogued twice.

**Review gate:** fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex adversarial pass, again.

---

## 0. What changed from Revision 1 (map to the 12 required items)

| # | Required item | Closed in |
|---|---|---|
| 1 | Rewrite failure mode as state-dependent trace; resolve the verifier conflict empirically | §1 |
| 2 | Anchor on `buildSaleReceiptV3Payload`; mandatory rounding keys; sign-normalization boundary | §2.1–§2.3 |
| 3 | Cash-only-payout rounding gate (bind 2c) | §5.1 |
| 4 | New immutable payload version OR synchronous preauth — choose one | §2.4 (chose: new version, under Model 1) |
| 5 | One settlement authority model + durable intent + full state machine | §3 |
| 6 | Money layer — all six treasury Criticals | §6 |
| 7 | VOID — one ruling | §4.3 |
| 8 | Cross-terminal lookup — full contract | §4.4 |
| 9 | Device Z production path | §5.4 |
| 10 | Staged rollout for existing v3 terminals/receipts | §7 |
| 11 | Genuinely exact manifest | §9 |
| 12 | Citation corrections + acceptance-command flags | inline throughout + §1.3 |

Kept from Revision 1 because reviewers endorsed them: the two-counter chain diagnosis (now
corrected for the actual initial values and chain contexts, §1), the two-tier offline policy
(now with the approval-flow contradiction actually fixed, §4.1), the §3e-equivalent
quantity-cap finding (now with the locking design it was missing, §4.5), and the
next-sale-chains-off-refund test assertion (§1.3).

---

## 1. Today's failure mode, precisely (state-dependent, corrected)

### 1.1 What Revision 1 got wrong, and why

Revision 1 claimed the legacy correction counter runs `0, 1, 2, …`, that every first return
starts an orphan chain, that a later unique collision is a structural certainty, that the
operator sees a bare 500, and that VOID shares this failure mode. Codex's review (§2.2–2.3)
traced the actual code and found all five claims wrong. The corrected facts:

- **`current_sequence` is `1` at creation, not `0`, on every live creation path.** All three
  `TerminalController` paths (`store()` physical terminal `:111`, `requestTerminal()` pending
  terminal `:387`, web-terminal `store()` `:450`) explicitly set `'current_sequence' => 1`; none
  of the three sets `fiscal_schema_version` (it falls to the model/DB default, currently `2`
  per the `pos_terminals` migration). `VirtualAdminTerminalResolver.php:47-49` also sets `1`,
  and explicitly `'fiscal_schema_version' => 3`. `TerminalFactory.php:29-32` defaults to `1`
  and `fiscal_schema_version => 2` (with a `v3Schema()` trait state for `3`). The migration
  default of `0` (`2026_01_08_190429_create_pos_terminals_table.php:40`) is real but is
  overridden by every runtime creation path — it is **not** the effective creation behavior.
  **One important, staging-relevant exception:** `DemoPharmacySeeder.php:767-769` explicitly
  writes `'current_sequence' => 0, 'fiscal_schema_version' => 3` for its Tunisia terminals —
  this is the topology actually present on staging today.
- **PostgreSQL forbids `chain_sequence = 0` outright.** `pos_receipts` carries `CHECK
  (chain_sequence IS NULL OR chain_sequence > 0)`
  (`2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:30-33`, exact statement text
  `ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_sequence CHECK (chain_sequence IS NULL
  OR chain_sequence > 0)`). Violating it raises SQLSTATE `23514` (check_violation) — a
  **different** SQLSTATE from the `23505` (unique_violation) Revision 1 described, and this
  particular check is PostgreSQL-only: SQLite (used by the default Feature test driver) does
  not enforce it, so a test asserting this failure mode must run in PG mode to be meaningful
  (see §1.3).
- **Chain contexts are split, not shared.** `SESSION_OPEN`, `OPENING_FLOAT`, `SESSION_CLOSE`,
  `X_REPORT`, `Z_REPORT` use `chain_context = 'z_session'`; `SALE_RECEIPT` and the
  approval/override events use `'operational'` (`FiscalEventEngine.ts:209-246`). So a normal
  shift-open does **not** consume operational sequence `1` — a device's first sale on a fresh
  terminal is typically at `fiscal_events.sequence_number = 1` in the `operational` context
  (drift note: the previous-hash/sequence calculation itself is at `FiscalEventEngine.ts:608-612`,
  not `:606-611`).
- **VOID never touches Chain B at all.** `ReceiptVoidService` never calls
  `ReceiptFinalizationService` — it mutates the original receipt in place, reverses
  stock/batches, records a drawer refund, and emits `ReceiptVoided`
  (`ReceiptVoidService.php:53-135`). No caller in the repository routes VOID through
  finalization (`ReceiptReturnService.php:472`, `ReceiptPaymentService.php:436`,
  `ExchangeService.php:299-302` all confirmed void-free). VOID is therefore a **separate
  failure class**, addressed on its own in §4.3/§6.4, not a Chain-B collision risk.

### 1.2 The corrected, state-dependent trace

The legacy `/return` pipeline is: prepare server line IDs → author + force-sync two
`operational`-chain approval events (no `pos_receipts` row) → lock original + terminal,
validate, write draft/lines/side-effects → `ReceiptFinalizationService::finalize()` sets
`chain_sequence = terminal->current_sequence` (read, not yet incremented) and computes the hash
via `V3ReceiptHashComputer::compute()` when `terminal->fiscal_schema_version === 3`
(`ReceiptFinalizationService.php:97-99`) → saves the receipt → **only then** advances
`terminal->current_sequence++` (`:109-111`) — all inside one outer `DB::transaction()`
(`ReceiptReturnService.php:188-265,278-395,397-482`). `pos_receipts` enforces
`UNIQUE(terminal_id, receipt_year, chain_sequence)`
(`2026_01_08_190637_create_pos_receipts_table.php:93,97`), with no carve-out for
`fiscal_event_id` nullness.

| Terminal state | First-return outcome |
|---|---|
| **Staging/demo topology** (`current_sequence = 0`, e.g. `DemoPharmacySeeder`) | Finalization attempts `chain_sequence = 0` and fails the PG `pos_receipts_sequence` CHECK **immediately** (SQLSTATE `23514`). No orphan is ever created. |
| **Normal topology** (`current_sequence = 1`, the terminal's first v3 sale is at `operational` sequence `1` in the same receipt year — the common case, since a return needs a prior sale and nothing usually precedes it in `operational`) | Finalization attempts `chain_sequence = 1` and **immediately** collides with the already-projected sale row on `pos_receipts_terminal_sequence` (SQLSTATE `23505`). No orphan is ever created. |
| **Conditional sequence-gap case** (something else occupies `operational` sequence `1` before the first sale — e.g. an approval/override event fired without a subsequent sale in the same session, or the original sale is cross-terminal/previous-year, so the legacy counter's low integers are momentarily free) | The return **can** commit, with `previous_hash = NULL` (since `pos_terminals.last_hash` is never written by the v3 sale path) — producing the disconnected legacy side-chain. A **later** collision can occur if the legacy counter's climb (`0/1, 1/2, 2/3, …` per correction) reaches an integer a projected sale already occupies that year. This is possible, not certain, and not bounded to "the terminal's lifetime" as Revision 1 claimed — it depends on the terminal's actual event mix. |

**On either standard (immediate-failure) path, nothing commits.** The outer transaction rolls
back atomically — no draft, stock, voucher, payment, GL, or drawer effect lands.
`ReceiptReturnService::processReturn()` rethrows the `QueryException`; `ReceiptController`'s
`processReturn()` action catches `RuntimeException` (`QueryException` extends `PDOException`
extends `RuntimeException`) and returns a `RETURN_FAILED` 422 carrying the raw DB error message
(`ReceiptController.php:246-356`). This is **not** an uncaught bare 500 — it is a loud,
transactionally-safe, but domain-uninformative 422 that leaks a low-level SQL error string to
the client. **§2.4 replaces this entirely** with a typed, translated 409/422 before the
terminal ever reaches `ReceiptFinalizationService`.

### 1.3 Resolving the reviewer conflict empirically: what `pos:verify-chains` and the NF525
endpoint actually report for a committed orphan

Codex framed this as "the orphan is invisible to `pos:verify-chains`" (excluded rows); the
fiscal-pos reviewer framed it as "the verifier goes permanently RED." **Both are correct — they
describe two different verifiers, and I traced the actual hashing code
(`ReceiptHashService.php`) to settle exactly what each one does.**

`ReceiptHashService::verifyTerminalChain()` (`:180-187`) runs **two independent arms**:
`verifyTerminalChainFiscalArm()` (`:208-282` — walks `fiscal_events`, re-hashes
`canonical_bytes`) and `verifyLegacyArm()` (`:313-353`). `VerifyPosChainCommand::verifyReceiptChain()`
first counts `fiscal_event_id IS NULL, fiscalized, not voided, not training` rows for the
terminal (`:179-184`); **if that count is zero it skips verification entirely and reports
`✓ valid` trivially** — this is the "excluded/invisible" behavior Codex described, and it holds
*before* any legacy return exists. But the instant one committed orphan return exists on that
terminal, the count is `≥ 1`, so the command proceeds to call the **full**
`verifyTerminalChain()` (`VerifyPosChainCommand.php:194`) — both arms.

`verifyLegacyArm()` recomputes each `fiscal_event_id IS NULL` row's hash via
`$this->calculateHash($receipt, $previousHash)` — the **legacy pipe-separated format**
(`serializeForHashing()`, `ReceiptHashService.php:84-94`) — and compares it against
`$receipt->fiscal_hash` (`:333-337`). But `ReceiptFinalizationService.php:97-99` computed that
stored `fiscal_hash` via `V3ReceiptHashComputer::compute()` (canonical JSON SHA-256) precisely
**because** the terminal is schema 3. These are two structurally different hash algorithms over
different serializations of the same data — the recomputed legacy hash can **never** equal the
stored v3 hash. `verifyLegacyArm()` therefore returns `false` for that row **every time it is
evaluated**, `verifyTerminalChain()` returns `false`, and `VerifyPosChainCommand` reports `✗
FAILED` for that terminal's Receipts row — **permanently**, for as long as that row exists
(receipts are immutable, never deleted).

The same defect exists independently in the **live NF525 endpoint**:
`Nf525DataProvider::verifyReceiptChain()` (routed from `Nf525ExportController::verifyChains`)
walks its own `$legacyReceipts` set and calls the identical
`$this->receiptHashService->calculateHash($receipt, $previousHash)` recomputation
(`Nf525DataProvider.php:414,425-434`), comparing against the same v3-computed `fiscal_hash` —
returning `isValid: false, error: 'Fiscal hash mismatch: receipt data may have been tampered
with'`. **This is a false-positive tamper alarm on the live compliance audit surface**, not a
silent blind spot — worse than Revision 1's "silent gap" framing.

**Root cause, and why this is a pre-existing bug, not something Lane C introduces:**
`verifyLegacyArm()`/`Nf525DataProvider::verifyReceiptChain()`'s partition assumption —
`fiscal_event_id IS NULL` ⟹ "hashed via the legacy pipe format" — was true only before
`ReceiptFinalizationService` gained its schema-version dispatch (v2 → legacy hash, v3 →
`V3ReceiptHashComputer`, `:97-99`) for receipts that are `fiscal_event_id IS NULL` by
construction (returns/voids never originate from a fiscal event). Once a v3 terminal can
produce a `fiscal_event_id IS NULL` row hashed by a *different* algorithm than the one this
verifier recomputes, the partition is broken — independent of whether Lane C ships anything.

**Compliance-symptom statement (replaces Revision 1's §1.2(a)):** every v3 terminal that has
ever had one legacy-sealed return/void produces a **permanent, loud false-positive tamper
alert** on both `pos:verify-chains` and the live NF525 verify-chains endpoint — not a silent
blind spot. `fiscal:verify-event-chain` (which walks `fiscal_events` only,
`VerifyEventChainCommand.php:19-42`) never sees the refund at all — that part of Codex's
framing is also correct, for a *different* verifier.

**Remediation ruling for existing/future orphan rows (new deliverable — not present in
Revision 1):** the correct fix is not to leave `verifyLegacyArm()`/
`Nf525DataProvider::verifyReceiptChain()` broken and hope §2.4's guard prevents new occurrences
— it must repair the verifier partition itself, branching on the **owning terminal's
`fiscal_schema_version` at verification time** (not merely `fiscal_event_id` nullness): rows on
a v3 terminal with `fiscal_event_id IS NULL` must be re-verified via `V3ReceiptHashComputer`
directly against the `Receipt` model (mirroring what `ReceiptFinalizationService` did to
produce the hash), not the legacy pipe recomputation. This closes both the compliance-symptom
described above **and** the eight existing staging receipts (§7's preflight step must determine
whether any of them are v3-sealed legacy returns and, if so, this fix is what makes their
existing state verifiable rather than requiring any rewrite of the rows themselves — §7's "never
rewrite existing fiscal rows" prohibition still holds; only the *verifier* changes).

### 1.4 Corrected shape of the missing integration test

New file `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php` — never edits
`ReceiptReturnRefactorTest.php` (whose `:494` v2 pin, corrected from Revision 1's `:490`
citation, remains permanently valid and untouched):

1. Create terminals at **both** `current_sequence = 0` (demo/staging topology) and
   `current_sequence = 1` (standard topology) and a `fiscal_schema_version = 3`, and prove the
   §1.2 table's first two rows — the `23514` CHECK failure and the `23505` unique failure
   respectively — occur **in Postgres test mode** (the SQLite default test driver cannot
   reproduce either SQLSTATE; this test MUST run against the Postgres connection, and the test
   file's docblock must say so explicitly, with a companion assertion that this exact scenario
   is **not** provable under SQLite, so a future maintainer does not "fix" the test by relaxing
   the driver).
2. Through the **real** ingest/job/projection lifecycle (queued `ApplyFiscalEventProjectionJob`,
   not a direct `PosCoreReceiptProjection::apply()` call) author a genuine v3 `SALE_RECEIPT`
   (event_version 4, §2.4) sale, then a REFUND against it via the new device-authored path
   (§2–§4), then a second sale, then close the session.
3. Assert: `PosCoreReceiptProjection` projects the refund to `ReceiptType::Return` with the
   correct disposition/voucher/proration effects (§6); the second sale's `previous_hash` equals
   the refund event's `current_hash` (chain unbroken, refund is *in* it); the Z close's
   `cash_rounding_summary` includes the refund's adjustment when applicable (§5.4); **Z totals
   for the session cover both the sale and the refund** (not just one).
4. Run `fiscal:verify-event-chain --tenant=<id> --terminal=<id> --actor-id=<system-actor-uuid>`
   (all three flags are required — `VerifyEventChainCommand.php:69-75,88-145`; Revision 1's
   citation omitted `--tenant`/`--actor-id` and is corrected here) and assert exit `0`, and
   assert `pos:verify-chains --terminal=<id>` also reports `✓` — per §1.3's determination, this
   is the FIRST test in the repository that can make this claim meaningfully, because it is the
   first test that authors a v3 refund through the **new** device-authored path rather than the
   legacy one that permanently reds the legacy arm.
5. Negative companion: attempt the **legacy** `/return` endpoint against the same v3 terminal
   post-guard (§2.4) and assert the typed rejection, not a raw SQL error.
6. **v2-non-regression companion** (explicit new requirement): run the identical scenario
   against a `fiscal_schema_version = 2` terminal and assert the legacy `/return`/`/void`
   endpoints behave exactly as `ReceiptReturnRefactorTest.php` already proves — the §2.4 guard
   must never fire for v2.

---

## 2. The integration design (anchored on the real chokepoint)

### 2.1 The actual authoring chokepoint is `buildSaleReceiptV3Payload`, not `SaleReceiptPayload.ts`

Revision 1's fatal anchoring error: it treated `SaleReceiptPayload.ts` (the V1 builder) as "the
only `SALE_RECEIPT` builder" and proposed a sibling to it. Production sales are authored through
`buildSaleReceiptV3Payload()` (`receiptService.ts:357-399`), which **composes** through a
delegation chain that must never be forked:

```
buildSaleReceiptV3Payload(input, rounding)      SaleReceiptV3Payload.ts:76-101
  → buildSaleReceiptV2Payload({...input, total: exactTotal})   (V1's aggregate assert runs unchanged)
    → buildSaleReceiptPayload(...)               SaleReceiptPayload.ts:106-179  (V1, immutable forever)
  → swap in the ROUNDED total; add cash_rounding_adjustment/denomination
  → assertSaleReceiptAggregatesV3(...)            SaleReceiptV3Payload.ts:113-197
```

**Rule for the new REFUND/VOID builder: compose through the same chain, never fork it.** A new
`buildRefundReceiptV4Payload()` (§2.4 names the version) must delegate to
`buildSaleReceiptV3Payload()` (or, once the version-4 fields are added, an equivalent V4-aware
composition) exactly as V3 delegates to V2/V1 — never re-implement the aggregate/VAT/line
arithmetic independently. This is not stylistic: `assertSaleReceiptAggregatesV3`'s bind 2c
(§5.1) and the V1/V2 aggregate identities are exactly the invariants a refund payload must also
satisfy, and reusing the composition is the only way to guarantee that without duplicating (and
inevitably drifting from) them.

### 2.2 The sign-normalization boundary is `cartClassification.ts`, and it already exists

`classifyCartForCheckout()` (`cartClassification.ts:21-39`) already routes an all-return cart
(negative `quantity`/`line_total` `CartItem`s, `kind: 'return'`) to `'refund'` →
`decidePayInterception()` → `'start-refund'` → the refund settlement flow, **specifically
because** "negative lines fail the FiscalEventEngine money invariant inside
`buildSaleReceiptPayload`" (`cartClassification.ts:4-5`, quoted verbatim). This is the existing,
correct boundary — a mixed or pure-sale cart can never reach the refund authoring path, and a
refund cart can never reach the sale builder. The new work is entirely on the *other* side of
that boundary: the refund payload builder must accept the negative-quantity, negative-line-total
`CartItem[]` the refund cart already produces (via `hydrateFromReceipt.ts`, unchanged, §7) and
**sign-normalize** them to the non-negative canonical magnitudes the payload contract requires
(`FiscalPayloadConstraintValidator.php:2724-2742`) — `bcabs()` on `quantity`, `line_total`,
`tax_amount`; `unit_price` is already positive on a return line (only the derived totals carry
the sign). The builder must assert, as a defense-in-depth invariant (mirroring
`LineArithmeticInvariantError`'s existing fail-loud pattern), that every item it receives has
`kind === 'return'` — a violation is a programmer error (the classification boundary was
bypassed), not a recoverable input error, and must throw rather than silently coerce.

### 2.3 Why the "server side already exists" claim was wrong

Revision 1 said the gap was "entirely device-side." It is not. The current v3 `line_items[]`
key set (`FiscalPayloadConstraintValidator.php:1838-1849`; device mirror
`FiscalEventEngine.ts` line-item keys) has no field for an original-line reference or
disposition. `OriginalReceiptReferenceInput` is receipt-level only
(`FiscalEventEngine.ts:367-376`). `PosCoreReceiptProjection` writes return lines with no
`original_line_id`/disposition and **unconditionally restocks every REFUND/VOID line**
(confirmed: the projector's stock-movement path for `ReceiptType::Return` has no disposition
branch) — it cannot reproduce the legacy path's `RESTOCK`/`SCRAP`/`NOT_RECEIVED` behavior or the
regulated `RestockPolicy::Never` guard `ReceiptReturnService.php:1122-1123` enforces today. The
quantity-cap logic (`ReceiptReturnService.php:1058-1089,1148-1182`) operates on **negative**
stored quantities keyed by `original_line_id` — it cannot be reused unmodified against
canonical rows, which store **positive** magnitudes with no `original_line_id` at all. Store
voucher legs are interpreted as **redemption** (burning an existing voucher — confirmed,
`PosCoreReceiptProjection.php:941-966`, `redeemVouchers()` called unconditionally at `:405` for
every payment leg with `instrument_type = store_voucher`, no `invoice_type_code` gate), whereas
the live legacy path calls `VoucherIssuanceService::issueFromRefund()` — the opposite operation
(`ReceiptReturnService.php:791-830`). The `original_payment` destination is a structural no-op
on v3 today (§6.3). None of this is achievable by "reusing" the current payload with a device-side
change alone.

### 2.4 Ruling: a new immutable payload version, not a synchronous preauth contract

Codex's §12 item 4 requires choosing exactly one of (a) a new payload version carrying the
missing facts, or (b) formally narrowing launch scope with a synchronous server preauthorization
contract before every payout. **Ruling: (a) — a new `SALE_RECEIPT` payload `event_version = 4`.**
This choice is forced by §3's settlement-authority ruling (Model 1, immutable-device-authoritative,
chosen precisely because it is the only model compatible with genuine offline refund authoring —
a synchronous preauth contract requires connectivity at the moment of authoring, which would
silently revoke the offline capability both reviewers explicitly told me to keep). V4 is a strict
superset of V3, built by the same "delegate, then extend" composition as V3 over V2 (§2.1), never
mutating V1/V2/V3 (Events are Immutable Forever — CLAUDE.md rule 8). New fields, precisely:

- **`original_line_references: { original_line_index: integer, product_id: string,
  disposition: 'restock' | 'scrap' | 'not_received', quantity: string }[]`** — one entry per
  refunded line. `original_line_index` is the **0-based array position** of the referenced line
  within the *original* SALE_RECEIPT's own `line_items[]` array. This is the stable identifier:
  canonical bytes are immutable, so the original's line ordering never changes, and the index is
  resolvable **offline** (the device already has the original event's `payload.line_items[]`
  locally, per §2.2's local-lookup design — no server-assigned `line_id` dependency, unlike
  today's server-ID-bound approval target, §4.1). `product_id` is carried redundantly for a
  defense-in-depth server-side cross-check (the referenced original line's `product_id` must
  match). `disposition` is the cashier's *declared intent*; §4.5/§6.4 rule on server-side
  enforcement against `RestockPolicy::Never`.
- **`refund_destination: 'cash' | 'original_payment' | 'store_voucher'`** — first-class, not
  inferred from `payments[]` (today's payload has no such field at all — confirmed absent by
  search in both `FiscalEventEngine.ts` and `FiscalPayloadConstraintValidator.php`).
- **`settlement_allocation: { original_payment_leg_ordinal: integer, amount: string }[] | null`**
  — present iff `refund_destination = 'original_payment'`; null otherwise. `amount` values are
  non-negative canonical magnitudes summing to the refund total. `original_payment_leg_ordinal`
  is the 0-based position of the referenced leg in the *original* sale's `payments[]` array
  (same stable-by-position principle as line references). §6.3 defines the deterministic
  proration algorithm that computes this array — it must be byte-identical between device
  (signing) and server (verification).
- **Approval evidence needs no new field** — `approval_references[]`
  (`FiscalEventEngine.ts:444-452`) already carries all seven `PosOverrideEvidence` fields
  (`approval_event_id`, `approval_id`, `approval_scope`, `override_event_id`, `policy_version`,
  `supervisor_user_id`, `target_reference_id`). The defect is that today's *authoring* helper
  (`authorRefundReturnApproval`) drops two of them and renames the rest when building
  `RefundApprovalEvidence` (§4.1) — the fix is in the authoring code, not the schema.

This necessarily touches Lane B's currently-open surface: device
`FiscalEventPayloadRegistry.ts` (author version 4 for `SALE_RECEIPT` when `invoice_type_code ∈
{REFUND, VOID}`, keep version 3 for `SALE`/`TRAINING`), server `FiscalEventPayloadRegistry.php`
(accept `{1,2,3,4}` on parse), `FiscalPayloadConstraintValidator.php` (version-4 exact key set +
the new invariants), the canonical DTO/reader, and drift + golden-fixture tests (a genuine v4
REFUND vector and a v4 VOID vector — the existing `F-09-refund-eur` fixture is a pre-v3, 27-key
fixture with no V2 variant keys and no mandatory V3 rounding keys; it proves nothing about the
current, let alone a v4, shape). **Per v4 dispatch plan §Lane C: "may not begin while Lane B is
unmerged."** §9's manifest is therefore explicitly POST-LANE-B and version-bumps the payload
contract rather than silently avoiding Lane B's files — silence would not be compliance with the
fence, it would be a correctness hole (Codex §9.4).

---

## 3. Settlement authority model, durable intent, and the complete state machine

### 3.1 The chosen model

**Ruling: Model 1 — the immutable device-authored correction event is authoritative.** Once
signed and durably committed on the device, the server must project it deterministically and
must never erase its money/inventory effects; any later-discovered problem is handled by a
compensating fiscal event or an operator-recorded write-off, never by silently dropping or
mutating the original. This is chosen over server reservation/preauthorization because
preauthorization requires connectivity at the moment of authoring, which is incompatible with
the offline same-device refund capability both reviewers told me to preserve (§4.1) — and
because it is **the same risk shape the codebase already accepts for every offline sale**: a
cashier hands over goods against a locally-signed, not-yet-synced `SALE_RECEIPT` today, and the
server can in principle later find that sale invalid (e.g. a since-revoked price override); this
design applies the identical acceptance to refunds rather than inventing a new risk category.

**The payout moment is defined explicitly (this was undefined in Revision 1 and is Codex's
§4.2 Critical):** physical cash or voucher handover to the customer happens **only after** the
refund `SALE_RECEIPT` event is signed and durably committed to the device's local SQLite (inside
the write-gate transaction, §3.3) — never before, and never contingent on sync. This mirrors
exactly how a sale's payout (handing over goods) already happens today, after the sale event
commits locally and before any server round trip.

### 3.2 Durable refund intent (new, replacing Zustand-only state)

Today's `refund_request_id` and approval evidence live only in `refundCheckoutStore.ts`'s
Zustand state (`:222-234,331-377`) — reset, cancellation, process death, or restart loses them,
and a retry can then author a second approval pair or a second refund event. **New SQLite table
`refund_intents`** (new device migration), one row per refund attempt, created **before** the
manager PIN is entered:

| Column | Purpose |
|---|---|
| `id` (UUID, PK) | Stable identifier for this attempt — the durable idempotency key, generated at `begin()`, survives restart |
| `original_local_receipt_id`, `original_fiscal_event_id` (nullable until resolved) | Device-stable original identity (§4.1) |
| `line_snapshot_json` | The exact `original_line_references[]` this attempt is settling — frozen at `begin()` so a later cart mutation cannot silently redirect an in-flight attempt (mirrors today's `returnLinesFingerprint` stale-cart guard, kept) |
| `destination`, `settlement_allocation_json` (nullable) | §2.4 fields, computed once and frozen |
| `state` | enum: `drafted → approval_authored → refund_event_appended → synced → applied` (terminal success) or `→ dead_lettered` (terminal failure) or `→ abandoned` (cashier cancelled before append — safe to discard) |
| `approval_id`, `approval_fiscal_event_id`, `approval_override_event_id` | Populated when `state` reaches `approval_authored` |
| `refund_fiscal_event_id` | Populated when `state` reaches `refund_event_appended` — this is the moment payout is authorized (§3.1) |
| `created_at`, `updated_at` | Recovery bookkeeping |

**Recovery rules:** on app restart, any row in `drafted` or `approval_authored` state is safe to
resume (re-show the approval step) or abandon (nothing irreversible has happened — no cash moved,
no fiscal event signed) — abandoning sets `state = abandoned`. Any row at
`refund_event_appended` or later **must never be re-authored** — the UI resumes by showing the
already-signed refund's outcome (pending sync / synced / applied / dead-lettered), reusing the
existing `refund_fiscal_event_id`, exactly as `FiscalEventEngine`'s own
`(tenant_id, terminal_id, source_event_class, source_event_id)` idempotency already guarantees
for the append call itself (a retry of `engine.append()` with the same `source_event_id` — the
`refund_intents.id` — returns the existing event, `FiscalEventEngine.ts:576-588`).

**This table also becomes the single local source of truth for refund Z accounting**, replacing
`local_refund_records` (§5.4) — closing Codex's §4.3 "idempotency and durable intent are
missing" finding and Codex's §8.4 "device Z production writes are missing" finding with one
mechanism.

### 3.3 What the local write-gate transaction actually contains (correcting Revision 1's S3)

Revision 1 asserted the refund transaction "atomically includes local receipt, voucher and Z
bookkeeping" without specifying any such writes — Codex correctly flagged this as unsupported.
The transaction, inside the existing `withWriteTransaction('fiscal', …)` single-writer gate
(mirroring `receiptService.ts:479-505`'s pattern exactly), explicitly contains, in order:
(1) update `refund_intents.state = 'refund_event_appended'` and `refund_fiscal_event_id`;
(2) `engine.append('SALE_RECEIPT', v4Payload, { source_event_class: 'refund_intents',
source_event_id: refundIntentId })`; (3) update the local voucher-mirror balance **only** for a
`store_voucher` **redemption** leg pre-resolved before the transaction (same pattern
`receiptService.ts`'s 5b comment already documents for sales); no local voucher balance write for
a **store_voucher issuance** destination (§6.2 rules that the v4 payload does not issue new
vouchers device-side at all — issuance stays server/legacy-only for launch). Local receipt
printable data and cart cleanup happen **after** this transaction commits, reading back the
committed `refund_intents`/`fiscal_events` rows — a crash between commit and print/cleanup loses
UI convenience state only, never fiscal or payout state, and is recoverable via §3.2's resume
rules.

### 3.4 Complete state machine

| State | Meaning | Recovery / next transition |
|---|---|---|
| **drafted** | `refund_intents` row created, cart frozen, no approval yet | Resumable; abandonable with no side effects |
| **approval-orphan** *(new — Codex §5 missing-state)* | Approval fiscal events committed locally, but `refund_intents.state` update to `approval_authored` failed/crashed before recording their IDs | The approval events themselves are chain-immutable and harmless (they are evidence, not payout) — on resume, the device re-reads its own just-appended `OPERATOR_APPROVAL_GRANTED`/`OVERRIDE_VOID_OR_RETURN` events by `source_event_id` (idempotent lookup) and repairs the `refund_intents` row rather than re-authoring |
| **approval_authored** | Both approval events committed, evidence cached in `refund_intents` | Sync of these two events is **no longer force-synced before proceeding** (§4.1) — they ride the ordinary outbox alongside the refund event itself |
| **append-before-crash** *(new)* | Crash between building the v4 payload and `engine.append()` returning | No event was appended (the write-gate transaction is all-or-nothing); `refund_intents` stays at `approval_authored`; resume re-attempts the append with the same frozen line snapshot |
| **refund_event_appended** | The refund `SALE_RECEIPT` v4 event is signed and locally committed | **This is the payout-authorized moment (§3.1).** Sync-pending; local Z (§5.4) already reflects it, because §5.4 reads directly from this state, not from a settlement response |
| **sync-pending / transport-retryable** | Standard outbox retry, `getPendingFiscalEventsForSync` | Retries automatically; no operator action |
| **ingress-rejected/quarantined** *(new)* | Server-side signature/canonical-bytes verification fails (corruption, not business logic) | `IntegrityStatus::Quarantined` — existing mechanism, unrelated to business-rule rejection; surfaced to the existing fiscal-integrity operator workflow, out of this spec's new scope |
| **POS-applied / Treasury-dead-lettered** *(new — Codex §5 sibling-projector gap)* | `PosCoreReceiptProjection` succeeds (receipt/lines/stock exist) but the sibling `TreasuryReceiptBridge` projection dead-letters (e.g. a purpose-account misconfiguration) | Money/GL effects are missing while the receipt exists — this is a genuinely inconsistent intermediate state; §6's operator dead-letter surface must show it distinctly from "nothing applied at all," because customer-facing receipt/inventory state has already changed |
| **dead_lettered** | Either projector permanently failed after Horizon's 5 retries (`ApplyFiscalEventProjectionJob.php` — every `Throwable` retries, `catch (Throwable)` at `:394`, no non-retryable branch exists today; `failed()` flips to `ProjectionStatus::DeadLettered` only after `$tries` exhaust, `:428-458`) | **Manual only** — `fiscal:retry-projections` (`RetryFiscalProjectionsCommand.php`) resets and retries; there is no automatic recovery when a dependency later becomes available, contrary to Revision 1's claim. §6's new operator-facing report (§9 manifest) is required so a dead-lettered refund is discoverable, not merely loggable |
| **cross-device double-refund (S5)** | Two terminals author refunds against the same original before either syncs | **Not preventable in real time when both are genuinely offline** — named as an accepted residual risk, not solved away. §4.5 defines the server-side per-original lock that guarantees only one of the two ever *books*; the loser dead-letters. **Cash may already have been paid out on the losing terminal before the lock resolves** — this is a real, bounded operational risk, mitigated by (a) device pre-flight duplicate detection for the common same-device case, (b) an operational recommendation to keep sync cadence short, and (c) manual dead-letter reconciliation that surfaces the double-payout for a loss write-off, not a reversal |
| **VOID-of-a-refund** *(new — explicitly out of scope)* | Correcting a refund itself | The current UI has no VOID surface at all post-Phase-6 (`refundApproval.ts:4-9`'s docblock: refund is the only correction surface). This design does not add one. A refund, once appended, is corrected only by the same class of new compensating event a dead-letter reconciliation would use — not a new "void the refund" feature. Explicitly out of Lane C's launch scope |
| **applied** | Both projectors succeeded | Terminal success state |

---

## 4. The (five) mandatory decision areas

### 4.1 Offline / unsynced refunds — approval redesign closes the real contradiction

Revision 1 kept "phase 1" (approval authoring) unchanged while claiming full offline capability
— a direct contradiction Codex caught: `authorRefundReturnApproval()`'s `target` binds the
**server** `pos_receipts.id` and mapped **server** `line_ids` (`refundApproval.ts:11-30,68-96`),
which only exist after `prepareRefundSettlement()`'s online resolution
(`refundSettlementService.ts:264-288`) — and then **force-syncs** both approval events before
proceeding (`refundApproval.ts:108-121`, `approvalFiscalSync.ts:24-78`). Neither dependency
disappears just because the original is resolvable locally.

**The fix is the redesign itself.** New `authorRefundReturnApprovalV2()`:
- **Target binds device-stable identity, not server IDs**: `target_reference_id =
  refund_intents.id` (§3.2); `target = { original_local_receipt_id, original_fiscal_event_id
  (nullable — see below), original_line_references (§2.4's frozen snapshot), reason }` —
  entirely resolvable from `refund_intents` and the local `fiscal_events` mirror, no server
  round trip.
- **Preserves all seven `PosOverrideEvidence` fields verbatim** into
  `approval_references[]` — no renaming, no dropping `policy_version`/`target_reference_id`
  (Revision 1's `RefundApprovalEvidence` type is retired).
- **No force-sync before append.** The approval events and the refund event are authored in the
  same local session and ride the ordinary outbox together, in sequence order
  (`getPendingFiscalEventsForSync`'s existing `ORDER BY chain_context ASC, sequence_number ASC`,
  `fiscalEventRepository.ts:76-88`, already guarantees they sync in the order they were
  appended). The server verifies the evidence against the synced chain at projection time, not
  at submission time — there is no "submission" anymore in the HTTP sense.

**Two-tier resolution policy, restated precisely (kept, endorsed by both reviewers):**
- **Same-device original** — resolved via the local `fiscal_events` lookup by
  `(source_event_class = 'offline_receipts', source_event_id = original_local_receipt_id)`
  (`fiscalEventRepository.ts`, existing columns). Fully offline: no network call at any point
  in the refund flow.
- **Not resolvable locally** — collapses into exactly one of three indistinguishable local
  causes (§4.4 fixes this ambiguity): a v2 receipt, a v3/v4 receipt authored on another
  terminal, or a same-device receipt whose local event is genuinely unavailable. The device
  cannot tell these apart without either parsing trusted receipt metadata (the printed/scanned
  receipt does not carry `fiscal_event_id`) or performing the online lookup (§4.4). **A v2
  original is always refunded through the legacy `/return` endpoint** (never gated off for v2,
  §2.4) — the v4 payload's `original_receipt_reference` can only ever point at a
  `fiscal_event_id`-backed original, by the same fail-closed resolution
  `PosCoreReceiptProjection::resolveOriginalReceiptId()` already enforces
  (`:617-637`) — so a v2 original genuinely cannot be represented, correctly.

### 4.2 Atomicity / failure ordering — superseded by §3

§3's state machine is the complete answer to this decision area; it is not repeated here.

### 4.3 VOID — one ruling, no fallback

**Ruling: VOID is prohibited from this launch's device-authored scope. The legacy in-place
mutation path remains the only VOID mechanism, gated by a single explicit guard, with a named
operator workflow — no dual option, no code-phase choice.**

This reverses Revision 1's "integrate VOID too" stance, for reasons the reviews established
precisely: a canonical VOID would not even be *reported* correctly by the current server, and
fixing that is materially more work than the refund path alone, disproportionate to launch
scope. Specifically: `PosCoreReceiptProjection` maps both `REFUND` and `VOID` to
`ReceiptType::Return` and **always writes `is_voided = false`**
(`resolveReceiptType()` `:537-544`, insert row `:347`). `Nf525DataProvider` buckets strictly by
`is_voided = true` for its void/ANNULATION query (`:160-166`) versus `receipt_type = Return AND
is_voided = false` for its returns query (`:179-187`) — a canonical VOID, carrying
`is_voided = false` by construction, lands in the **returns** bucket, not the NF525 void bucket.
`ReportGenerationService`'s receipt-classification code increments `voidedCount` only on
`is_voided` and otherwise counts any `ReceiptType::Return` as a refund (`:936-953`) — same
misclassification. Building a v4-correct VOID would require: a `SALE_VOID`-shaped projection
outcome that sets `is_voided = true` on **both** the projected VOID row and a link back to the
original (the original itself remains immutable — untouched — per the "the fiscal chain never
rewrites" principle, so "voided" must be a *derived* status computed from the existence of a
linked VOID correction, not a mutated column on the original at all), a new NF525 mapping
branch, a new report-counting branch, and an entire POS VOID authoring surface/UI/print/Z path
that **does not exist today** — the refund flow is the only correction surface post-Phase-6
(`refundApproval.ts:4-9`). None of this is in §9's manifest; it is explicitly out of scope.

**The compensating control (single, not a fallback among options):** `ReceiptVoidService`'s
existing in-place-mutation path is **gated off for `fiscal_schema_version >= 3` terminals**,
identically to §2.4's return guard — a v3/v4 terminal cannot void at all through the API for
this launch. The operator workflow for a v3-terminal correction that would previously have been
a VOID is: **process it as a REFUND** (full-line refund to the original tender, which the
refund path already handles completely) — this is not a workaround invented for this spec, it
is the documented existing convention (`refund model = SALE_RECEIPT + invoice_type_code=REFUND`,
`SALE_VOID`/`REFUND_RECEIPT` are vestigial reserved-unimplemented `FiscalEventType`s). This
closes the ruling with zero dual-option fallback and zero new manifest surface for VOID beyond
the one guard (shared with the return guard in the same PR).

### 4.4 Cross-terminal and legacy-original lookup — full contract

The projector resolves an original strictly by `pos_receipts.fiscal_event_id`
(`PosCoreReceiptProjection.php:617-637`) and fail-closes when absent — a v2 original can never
be validly referenced by a v4 correction; prohibiting that bridge is sound and kept. But (per
§4.1) the device cannot locally distinguish "v2 original," "v3/v4 original on another terminal,"
and "same-device original whose local event is unavailable" — all three look identical: a
local-lookup miss.

**New endpoint: `GET /pos/receipts/lookup-for-refund`**, query params `receipt_number` (required)
and `qr_token` (optional, preferred when present — mirrors the existing scan-first pattern in
`refundSettlementService.ts`). Contract, closing every gap Codex's §7 named:

- **Scope/authorization**: company-scoped via `CompanyContext` (exactly like the existing
  `GET /pos/receipts/{id}`, `ReceiptController.php:537-576`, which is company-scoped and
  permission-gated but **not** terminal-scoped — that precedent is the right shape here too,
  since a cross-terminal lookup is the entire point). Gated by `pos.process_returns` (the
  existing return permission). **Not** the same as `ReceiptLookupService`, which is deliberately
  single-terminal (`ReceiptLookupService.php:25-29,53-63,92`) — this is a **new, separate**
  service, not a scope change to the existing one.
- **Anti-enumeration**: rate-limited per cashier/terminal (standard Laravel throttle
  middleware, count TBD by the code phase against existing POS rate-limit conventions); returns
  a uniform "not found or not eligible" response for both a genuinely missing receipt number and
  one that exists but fails an eligibility check below — never distinguishes the two in the
  response body (only in server logs), so the endpoint cannot be used to enumerate valid
  receipt numbers.
- **Eligibility proof**: the resolved original must be `fiscal_event_id IS NOT NULL` (v3/v4-authored),
  `receipt_type = Sale` (or training-excluded, §6.6), `is_voided = false`,
  `fiscal_status = Fiscalized`. A v2 original, or one failing any of these, returns the uniform
  not-eligible response — the cashier is told "settle this original at a v2-capable terminal via
  the legacy flow" only when the miss is specifically because `fiscal_event_id IS NULL`
  (distinguishable server-side even though the response is uniform on enumeration grounds — the
  UI copy can safely differ because the *cashier* already knows which receipt they scanned; the
  anti-enumeration concern is about a stranger probing arbitrary numbers, not the cashier's own
  workflow).
- **Response payload** — everything the v4 signing step needs, sourced from the **authoritative
  fiscal event**, not mutable server state: `original_fiscal_event_id`, `original_business_date`,
  `original_line_references` (index/product_id/max-returnable-quantity per line, computed
  server-side using the §4.5 lock+cap logic so the device never under- or over-estimates),
  `allowed_dispositions` per line (regulated products pre-flagged `never` via
  `RestockPolicyResolver`), `allowed_destinations` (server-computed from the original's tender
  mix; per §6.2, `store_voucher` is never advertised for launch), `original_payments` (leg
  ordinals + amounts, for the §6.3 proration algorithm to run identically device-side), and a
  **capability/version field** (`min_client_payload_version: 4`) so an old device build can
  detect it cannot safely complete this refund and fail closed with an upgrade prompt, rather
  than silently authoring an incomplete/rejected event.
- **Race handling**: once this lookup has returned a row, that original is by definition already
  projected — the "original still mid-sync" race Revision 1 worried about does not describe the
  lookup-success path at all. If a *dependency* later dead-letters for some other reason, §3.4
  applies — recovery is manual (`fiscal:retry-projections`), never automatic.

### 4.5 Quantity-cap enforcement and per-original serialization (hardened from Revision 1's §3e)

Kept core finding: no already-returned-quantity check exists anywhere in
`PosCoreReceiptProjection` today (confirmed absent by search), so an authoritative cap requires
both a device pre-flight (best-effort, same-device history only) and a server-side backstop.
**What Revision 1 was missing, per Codex's §3: the backstop must be an actual lock, not a
racy read-then-check.** Design: before inserting the projected refund's lines, the server-side
check runs `SELECT ... FROM pos_receipts WHERE fiscal_event_id = :originalFiscalEventId FOR
UPDATE` (a real row lock on the **original** receipt, serializing all concurrent refund
projections against the same original within the wrapping `DB::transaction()`), then computes
already-refunded quantity per `original_line_index` from **all** prior REFUND projections
against that original (both this v4 path and, for the transition window, any surviving legacy
v2-original refunds), then either proceeds or throws a new, permanent `RefundQuantityExceededException`
(does not extend `ProjectionDependencyMissingException` — retrying five times changes nothing
about an over-quantity refund) that reaches the existing `catch (Throwable)` →
`ProjectionStatus::DeadLettered` lifecycle with no new job infrastructure required. This lock is
also what makes S5 (§3.4) resolve deterministically at the booking layer: whichever refund event
reaches this transaction first wins; the second dead-letters. It does **not** prevent a double
physical payout when both terminals were offline at authoring time — that residual risk is named,
not solved, in §3.4.

---

## 5. E1 refund rounding — corrected against the real binds and the real GL implementation

### 5.1 Cash-only-payout gate (replaces the "proportional mixed-tender" idea, which is
mathematically impossible under the current bind)

`assertSaleReceiptAggregatesV3`'s bind 2c (`SaleReceiptV3Payload.ts:189-196`) requires: whenever
`cash_rounding_adjustment ≠ 0`, `payload.total` (the **whole** receipt total) must be an exact
multiple of `cash_rounding_denomination`. This makes Revision 1's "round only the proportional
cash leg of a mixed-tender refund" idea unimplementable — if only part of the total is cash and
gets rounded while a card leg stays exact, the whole `total` generally will not be a denomination
multiple, and bind 2c rejects the payload outright.

**Ruling: refund rounding applies only when the entire refund payout is cash — mirror
`isCashOnlyTender()` (`cashRounding.ts:180-186`) applied to the refund's own tender/destination
legs, exactly as it already gates the sale side.** Any refund whose destination mixes cash with
`original_payment`/`store_voucher`, or whose destination is `original_payment`/`store_voucher`
outright, gets **`cash_rounding_adjustment = canonical zero` and `cash_rounding_denomination =
canonical zero`** — no rounding at all, full stop, stated as an explicit consequence (Codex's
§8.1, closed). Full refunds naturally reproduce the original sale's rounded amount (same base,
same rounding) when the destination is cash; partial cash refunds round independently on their
own computed amount, per the adopted research pattern — unchanged.

### 5.2 Rounding keys are mandatory, never optional, on every v3/v4 payload

Revision 1 stated the two rounding fields "may be omitted" on the TypeScript interface's optional
markers. In practice: `FiscalPayloadConstraintValidator.php:844-853` throws
`payload_missing_required` if either key is absent on any `event_version >= 3` payload, and
production authoring (`buildSaleReceiptV3Payload`) **always** sets both, using `canonicalMoney()`
(`SaleReceiptV3Payload.ts:70-74`) to collapse an unrounded state to the canonical zero string, not
omission. The v4 refund builder must do exactly the same — always both keys present, canonical
zero when §5.1's gate disables rounding.

### 5.3 GL: reuse the landed `pos_cash_rounding_refund` contract; do not invent
`pos_refund_rounding`

Revision 1 claimed no refund-rounding GL work existed and proposed a new `pos_refund_rounding`
source type. Both are wrong. `TreasuryReceiptBridge::postCashRoundingEntry()`
(`:419-470`) already runs for **both** sale and refund receipts — `$isRefund` selects
`$sourceType = $isRefund ? 'pos_cash_rounding_refund' : 'pos_cash_rounding'` (`:431`), already
posts through `PaymentToleranceIncome`/`PaymentToleranceExpense` purpose accounts (`:442-459`),
already has a probe-before-create idempotency guard backed by a DB partial unique index
(`:433-439`; migration `2026_07_28_100200_add_cash_rounding_to_pos_receipts.php:126-129`), and
already has a passing symmetric-refund test
(`TreasuryReceiptBridgeRoundingGlTest.php:233-269`). `GeneralLedgerService` enforces the enum of
valid source types and throws on an unrecognized one (`:3471,3509-3512`) — introducing
`pos_refund_rounding` would either throw on every rounded refund or require a parallel,
unreviewed migration to add it, for zero benefit over the type that already works. **Ruling: no
new GL source type; the v4 refund builder need only compute `cash_rounding_adjustment`
correctly (§5.1) and the already-shipped bridge code handles the rest.** State purposes by
enum (`PaymentToleranceIncome`/`PaymentToleranceExpense`), never by raw chart-of-accounts
number, matching the existing pattern.

### 5.4 Device Z production path (new — entirely absent from Revision 1's manifest)

`zReportService.ts`'s cash-rounding summary loop (`:283-291`) iterates **only** `offline_receipts`
— `local_refund_records` never enters it; that table only feeds `cashRefundImpact` for
`expected_cash` (`:230-232`) and refund *counts* in `aggregateReportData` (confirmed: 3 real
readers, not 4 as guessed in the dispatch brief — `zReportService.ts:205`,
`endOfDayPreview.ts:354` [which **is** the cash-impact expected-cash feed, invoked from
`EndOfDayPreviewModal.tsx` — not a separate fourth reader], and the repository query itself;
`HomePage.tsx:1158` only contains an explanatory comment, it performs no query).
`local_refund_records`'s schema (`localRefundRecordRepository.ts:19-40`) has no rounding columns
at all and is keyed to a server return-receipt id/number that no longer exists in this design (no
HTTP settlement response to mirror).

**Ruling: `local_refund_records` is retired and replaced by `refund_intents` (§3.2) as the single
local source of truth.** `zReportService.ts`, `endOfDayPreview.ts`, and the `cash_impact`
expected-cash computation are all rewired to read `refund_intents` joined with the local
`fiscal_events` row for each `refund_fiscal_event_id` (present once a refund reaches the
`refund_event_appended` state, §3.4) instead of `insertLocalRefundRecord`'s post-settlement
write. This requires a device SQLite migration (new `refund_intents` table, §3.2) and production
changes to all three files — explicitly in §9's manifest, not deferred.

Server-side, `ZReportProjection::cashRoundingSummary()` (`:204-233`) already sums **all**
projected non-void v3+ receipts including canonical returns, receipt-type-agnostically — this
requires no server change; it already produces the correct aggregate the instant refund events
start carrying correct `cash_rounding_adjustment` values. **Explicit ruling on the Z-summary
question: yes, refund rounding adjustments enter the Z `cash_rounding_summary`, unconditionally**
— this inherits Lane B's `zReportService.cashRounding.test.ts` and can only land once Lane B
merges (dispatch plan's explicit sequencing), at which point the code phase **extends** that
file, never forks it.

**AVOIR rounding line: unconditional in-scope for this launch**, correcting Revision 1's
conditional-on-E1-being-in-the-same-batch framing, which the fiscal-pos reviewer flagged as
violating the owner's "correction-chain integration plus E1 is one Lane C scope" ruling.
`buildReceiptData.ts`'s current hardcoded `cash_rounding_adjustment: null, has_cash_rounding:
false` (confirmed at `:713-714`, not `:712-713` as Revision 1 cited) is corrected in the same PR
that ships the refund path, not deferred.

---

## 6. Money layer (new section — closes all six treasury Criticals)

### 6.1 GL contract — see §5.3 (reuse `pos_cash_rounding_refund`; no new source type; purpose
enums, not PCG account numbers)

### 6.2 Store-voucher refunds — launch scope choice, ruled

Confirmed: `PosCoreReceiptProjection::redeemVouchers()` unconditionally **redeems** (burns) any
`store_voucher` payment leg for both SALE and REFUND payloads — there is no `invoice_type_code`
gate at the call site (`:405`) or inside the method (`:941-966`), unlike `earnLoyaltyPoints`,
which explicitly gates on `$receiptType !== ReceiptType::Sale` (`:987`). A v4 REFUND destined
for `store_voucher` would need to **issue** a brand-new voucher — but `writePayment()`'s
`InstrumentRequiredException` (`PosCoreReceiptProjection.php:856-859`, unconditional for any
`instrument_type` requiring an instrument, `PaymentInstrumentKind::requiresInstrumentForMethodCode()`)
would throw, because a freshly-issued voucher has no serial to sign at authoring time — the
device cannot mint the voucher code before the server projects the event, and the server cannot
verify a signed serial that didn't exist yet. This is a genuine chicken-and-egg the payload
format cannot resolve without a materially different design (e.g., a device-generated
voucher-code UUID plus a two-phase issuance protocol) that is out of proportion to launch scope.

**Ruling: store-voucher refunds are OUT OF LAUNCH SCOPE for the device-authored path.**
`refund_destination = 'store_voucher'` is **not** a legal value in the v4 payload for this
launch (§2.4's field is defined as `'cash' | 'original_payment'` only for now — voucher listed
in §2.4 as the eventual third value, deliberately not enabled). The cashier flow for a
voucher-destination refund on a v3/v4 terminal falls back to the legacy `/return` endpoint
(same fallback carve-out as VOID, §4.3) — the existing, tested `VoucherIssuanceService::issueFromRefund()`
path continues to serve this case until a follow-on spec designs the two-phase issuance
protocol. §2.4's lookup endpoint's `allowed_destinations` must never advertise `store_voucher`
for the v4 path.

### 6.3 `original_payment` destination — proration design (currently a silent no-op on v3)

Confirmed: `TreasuryReceiptBridge`'s v3 refund leg insert always sets `payment_type =>
PaymentType::POS` (never `PaymentType::Refund`) and has **no `original_payment_id` key at all**
in the insert (`:1316-1339`) — the backlink is structurally absent, not merely unpopulated. The
real proration engine, `PaymentRefundService::refundReceiptPayments()`
(`PaymentRefundService.php:628-828`), is **live today, but only reachable from the legacy
`/return` endpoint** (`ReceiptReturnService.php:853` is its sole caller in the entire codebase) —
it loads originals via `pos_receipt_payments.treasury_payment_id`, caps the refund total against
the sum of original payments (`:677-681`, throws `"totalToRefund exceeds receipt total"`), writes
negative `PaymentType::Refund` rows with `original_payment_id` preserved (`:762-786`), and
enforces cumulative-exhaustion via a partial unique index on
`(company_id, original_payment_id, refund_request_id) WHERE payment_type = 'refund' AND
original_payment_id IS NOT NULL` (`2026_05_03_000004_add_refund_audit_columns_and_unique_index_to_payments.php:64-66`).

**Ruling: the v4 refund projection path, for `refund_destination = 'original_payment'`, calls
this same `PaymentRefundService::refundReceiptPayments()` engine — not a parallel
`TreasuryReceiptBridge`-native reimplementation — using the §2.4 `settlement_allocation[]` the
device signed as the proration input, converted to the leg-amount map the service already
expects.** The **deterministic algorithm** the device must compute (and the server must
reproduce byte-identically to verify the signed allocation, before ever calling
`refundReceiptPayments()`): proportional-to-original-leg-amount, `legRefund_i = floor(refundTotal
× originalLegAmount_i / originalTotal, scale)`, remainder assigned to the leg with the largest
original amount (ties broken by ordinal) — the standard largest-remainder method, chosen because
it is simple, symmetric, and reproducible without floating point on both sides. The amount cap
(`totalToRefund <= receiptTotal` today) becomes, per leg, `Σ prior refunds against leg_i +
legRefund_i <= originalLegAmount_i`, enforced server-side before booking (mirrors the existing
cumulative-exhaustion check). `original_payment_id` linkage and the partial unique idempotency
index are preserved exactly as they exist today — nothing about `PaymentRefundService`'s own
contract changes; only its **caller** gains a new, v4-event-driven entry point alongside the
existing legacy-return entry point.

### 6.4 VOID double-count fix — folded into §4.3's prohibition

Since VOID is launch-prohibited on v3/v4 terminals (§4.3), the projector's `is_voided = false`
default and `buildExpectedPerMethod`'s missing receipt-type filter (§6.5) cannot be exercised by
a canonical VOID event at all for this launch — there is no live VOID-double-count path to fix,
because there is no live canonical VOID. The existing legacy VOID path (still serving v2 and, per
§4.3, the v3 fallback-workaround-via-refund case doesn't apply here since v3 VOID is prohibited
outright, not routed to REFUND) is unaffected by this design.

### 6.5 Expected-cash semantics — the real, currently-live bug this design's refunds would
trigger

Confirmed: `ReportGenerationService::buildExpectedPerMethod()`
(`:493-502`) sums `pos_receipt_payments.amount` grouped by `payment_method_id` for **every**
fiscalized, non-voided, non-training receipt in the shift window, with **no `receipt_type`
filter**. `pos_receipt_payments.amount` carries a DB `CHECK (amount > 0)`
(`2026_01_08_190640_create_pos_receipt_payments_table.php:62`) and `PosCoreReceiptProjection::writePayment()`
writes the same positive canonical magnitude for both SALE and REFUND legs identically (no
`$isRefund` branch in that method at all) — direction is signaled only by the parent receipt's
`receipt_type`, which this query never joins against. **A refund's cash leg is therefore summed
as if it were incoming, not outgoing** — expected cash goes up by the refund amount instead of
down, producing a variance spread of **2× the refund amount** once a v3/v4 refund's cash leg
lands (confirmed independently: `CashDrawerService::calculateExpectedCash()`, the OTHER expected-
cash mechanism, is unconditionally called for every shift regardless of schema version
(`ReportGenerationService.php:204`, `ShiftManagementService.php:170`,
`CashDrawerController.php:222`) but sums `CashDrawerOperation` rows, and **neither
`PosCoreReceiptProjection` nor `TreasuryReceiptBridge` ever creates a `CashDrawerOperation` row
for any v3 event, sale or refund** — confirmed by exhaustive search, zero references in either
file. `calculateExpectedCash()` is therefore already silently blind to *all* v3 activity today,
independent of this design; `buildExpectedPerMethod` is the mechanism that actually reflects v3
data, and is the one this design's refunds would newly and visibly break).

**Ruling: `buildExpectedPerMethod` must be fixed as part of this launch's manifest** — it is the
one live, v3-relevant expected-cash computation, and shipping the refund-chain feature without
fixing it actively regresses cash reconciliation reporting the moment the first v3 refund lands
(today it is merely *untested* for v3 because no v3 refund has ever existed; after this launch
it would be *actively wrong* on every shift with a refund). Fix: join `pos_receipts.receipt_type`
into the query and subtract `Return`-type payment amounts instead of adding them, applied
per-payment-method exactly as sales are added. `calculateExpectedCash()`/`CashDrawerOperation`'s
broader pre-existing v3 blindness (missing *all* v3 sales, not just refunds) is a **separate,
pre-existing defect this spec does not introduce and is out of Lane C's scope to fully close** —
named explicitly here so it is not mistaken for something this design silently fixed or silently
worsened; it is unchanged by this design either way.

### 6.6 Training-original refunds — refused device-side

Confirmed: the schema structurally cannot represent "a REFUND whose original was a training
sale" — `invoice_type_code` and `training_flag` must agree
(`FiscalPayloadConstraintValidator.php:807-820`, and `INVOICE_TYPE_CODES` treats `TRAINING` as a
sibling of `REFUND`/`VOID`/`SALE`, not a modifier of them) — a payload cannot carry
`invoice_type_code = 'REFUND'` and `training_flag = true` simultaneously. Confirmed separately:
`TreasuryReceiptBridge`'s core Payment-row/GL-posting loop has **no training gate at all** — only
the supplementary rounding/tolerance entries are training-gated (`:402`); a REFUND event that
somehow referenced a training original would post real money/GL effects for a rehearsal sale,
which must never happen. **Ruling: the device refuses to author a refund against a training
original, at the point of local resolution (§4.1) — if the local `fiscal_events` lookup resolves
an original whose own `payload.training_flag = true`, the refund flow shows a typed "cannot
refund a training receipt" error and stops before any approval authoring.** This is a pure
device-side guard (no server change required, since the schema already makes the combination
unrepresentable — the guard exists purely to give the cashier a clear message instead of a
downstream `SaleReceiptAggregateInvariantError`-style crash at payload-build time).

---

## 7. Staged rollout for existing v3 terminals/receipts (new section)

Staging today is greenfield in the sense that matters most: **zero real tenants**; the existing
footprint is 5 demo tenants / 6 companies / **8 receipts total**
(`docs/handoff/HANDOVER-first-tenant-orchestrator-2026-07-31.md:27`), and nothing in that
handoff document flags any of the eight as already carrying a v3-sealed legacy correction — the
document's own framing is that "the v3 refund fiscal path is unproven" (`:74`), i.e. untested,
not "already corrupted." That said, the demo v3 terminal writer (`DemoPharmacySeeder.php:767-769`)
sets `current_sequence = 0`, which is exactly the topology in §1.2's first row — if any manual
staging testing has exercised a return against that terminal outside of automated test
fixtures, it may already be in the permanently-red state §1.3 describes. This must be checked,
not assumed.

**Required rollout sequence, none of it optional:**

1. **Server-first, capability-gated.** Ship the schema/validator/projector/report support
   (§2.4's version bump, §6's money-layer fixes, §1.3's verifier repair) **disabled** behind a
   capability flag the device cannot yet trigger (no device build authors v4 REFUND/VOID
   payloads until this step is live and verified in staging).
2. **Preflight and inventory existing legacy-correction artifacts** — a new command (§9
   manifest) that scans, per tenant, for: v3/v4 terminals with `fiscal_event_id IS NULL`
   fiscalized, non-training receipts (§1.3's exact orphan signature), any legacy void markers on
   v3 terminals, and any `fiscal_event_projections` rows in `dead_lettered`/stuck `running`
   state. Run this against staging's eight receipts and every demo tenant before touching
   anything else. `PreflightFiscalGateCommand` (`fiscal:preflight-gate`) already exists as a
   related but different tool (counts receipts/Z-reports/terminals-with-chain-state; explicitly
   punts on device-SQLite inventory) — this is a new, narrower command, not an extension of it,
   because its job (orphan/dead-letter detection) is a different question than
   `fiscal:preflight-gate`'s launch-readiness counts.
3. **§1.3's verifier fix ships regardless of whether the preflight finds any existing orphan
   rows** — it is correct independent of current state and removes the false-positive tamper
   alarm as a standing risk for the future, not only for whatever exists today.
4. **Disabled device rollout.** Deploy the new device build (with `refund_intents`, the v4
   builder, the redesigned approval flow) to terminals with the server capability still off —
   the device build is inert for refunds (falls back to whatever the pre-migration behavior was)
   until the server advertises the capability via §4.4's `min_client_payload_version` field or
   an equivalent flag.
5. **§2.4's legacy guard ships in the same wave as step 1**, not before it and not after — it
   must not create a window where v3 terminals can neither use the new path (not yet enabled)
   nor the old path (already guarded off). Sequencing: guard + new-path-enablement are one
   atomic deploy per terminal cohort, never split.
6. **Per-terminal, not fleet-wide, enablement** — coordinated with confirmed minimum client
   version on that specific terminal (a terminal running an old POS build must not have its
   server-side guard flip before its device build updates, or it loses refund capability
   entirely until it updates).
7. **Cloned-staging tests before any production rollout**, covering **both** the
   `current_sequence = 0` (demo) and `current_sequence = 1` (standard) topologies from §1.2's
   table, run against a clone of the actual staging data, not synthetic fixtures alone.
8. **Absolute prohibitions, stated explicitly:** never rewrite, backfill, or re-derive any
   existing `fiscal_events` or `pos_receipts` row as part of this rollout; never reset or
   copy server counters into a device's `terminal_state` chain head. On this last point: today's
   `pullTerminalState` (`syncService.ts:1257-1338`) **does** overwrite the local
   `terminal_state.hash_sequence`/`last_hash` columns unconditionally from
   `pos_terminals.current_sequence`/`last_hash` on every pull (`TerminalResource.php:119,121`;
   `terminalStateRepository.ts` upsert, no preserve-guard on these two columns) — but these are
   confirmed **separate** columns from `fiscal_event_sequence`/`fiscal_event_last_hash`/
   `fiscal_event_genesis_seed`, which is what `FiscalEventEngine.append()`'s `readChainHead()`
   actually reads, and which **are** preserve-guarded (`CASE WHEN terminal_state.fiscal_event_genesis_seed
   = '' THEN excluded... ELSE terminal_state...`, only ever seeded once on a genuinely fresh
   row). So today's sync mechanism, as built, does not let a v3 terminal's legacy-counter
   activity corrupt the device's authoritative operational chain head — this rollout must not
   introduce any new code path that would change that, and the preflight (step 2) should confirm
   no `fiscal_event_genesis_seed`/`fiscal_event_sequence` values were ever populated from a
   server-side counter rather than genuine local chain progress.

---

## 8. Constraints honored (kept, corrections applied)

- **`hydrateFromReceipt.ts:49-86` never reads `receipt.total`** — confirmed unchanged; the v4
  refund builder sources its aggregates from the frozen `refund_intents` line snapshot (§3.2/
  §2.2), never from a settlement response (there is no settlement response in this design).
- **Quantity capping** — preserved via §4.5's server-side lock + per-`original_line_index`
  accounting (not the legacy negative-quantity calculation, which cannot apply to canonical
  positive-magnitude rows, §2.3).
- **The avoir carries a rounding line — now unconditionally, not conditionally** (§5.4
  corrects Revision 1's deferral). Current `buildReceiptData.ts:713-714` (drift-corrected from
  Revision 1's `:712-713`) hardcodes no rounding; this PR changes that.

---

## 9. Frozen code-phase write manifest (exact, post-Lane-B)

**Precondition, restated:** this manifest cannot be dispatched until Lane B merges to local dev.
No entry below is conditional, alternative, "e.g.", or already-complete. Every file is named.

**`apps/api` — payload contract (Lane-B-adjacent, version bump):**
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php` — accept
  `event_version ∈ {1,2,3,4}` for `SALE_RECEIPT`.
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` —
  version-4 exact key set (`original_line_references`, `refund_destination`,
  `settlement_allocation`) and their invariants (§2.4).
- Canonical DTO/reader: `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php` and
  `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/SaleReceiptCanonicalView.php` (or the
  correct canonical-view class per Lane B's post-merge location) — extend for the new fields.
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — version-4 cases.
- New golden fixture: `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-10-refund-v4/payload.json`.
  (No VOID vector — VOID is launch-prohibited, §4.3.)
- `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — extend for v4 keys.

**`apps/api` — projection/business logic:**
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` —
  disposition-aware stock restore (replacing unconditional restock), `original_line_index`
  resolution, §4.5's per-original lock + quantity-cap check + `RefundQuantityExceededException`,
  §6.6's training-original defense-in-depth check, company/tenant-scoping fix on
  `resolveOriginalReceiptId()` (currently global, §2.3).
- New file: `apps/api/app/Modules/Fiscal/Domain/Exceptions/RefundQuantityExceededException.php`.
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` — call
  `PaymentRefundService::refundReceiptPayments()` for `refund_destination = 'original_payment'`
  legs (§6.3), keeping the existing `pos_cash_rounding_refund` entry (§5.3) unchanged.
- `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php` — new entry point
  callable from the v4 projection path (alongside its existing legacy caller), accepting the
  device-signed `settlement_allocation[]`.
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` — fix
  `buildExpectedPerMethod()`'s missing `receipt_type` filter (§6.5).
- New endpoint: `apps/api/app/Modules/POS/routes.php` — `GET /pos/receipts/lookup-for-refund`;
  `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php` — new action; new
  file `apps/api/app/Modules/POS/Presentation/Resources/ReceiptRefundLookupResource.php`; new
  file `apps/api/app/Modules/POS/Application/Services/CrossTerminalReceiptLookupService.php`
  (§4.4 — deliberately separate from `ReceiptLookupService`).
- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php` and
  `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` — add the
  `fiscal_schema_version >= 3` guard (§2.4/§4.3), one shared guard helper.
- New Console command: `apps/api/app/Console/Commands/InventoryV3LegacyCorrectionsCommand.php`
  (§7 step 2 preflight/inventory).
- `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` — repair
  `verifyLegacyArm()` to branch on the owning terminal's `fiscal_schema_version` (§1.3).
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` — identical repair in
  `verifyReceiptChain()`'s legacy-row loop (§1.3).
- New operator-facing dead-letter surface: `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
  (list + detail, read-only) and its route; a corresponding `apps/web` admin page is **out of
  this manifest** — API surface only for launch, consumed manually via the existing
  `fiscal:retry-projections` operator workflow until a UI is separately scoped.
- Tests: `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php` (§1.4, PG-mode);
  `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3VoidGuardTest.php` (v2-non-regression, §1.4
  item 6); `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundQuantityCapTest.php`;
  `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTrainingRefundRefusedTest.php`;
  `apps/api/tests/Feature/Treasury/PaymentRefundServiceV4EntryPointTest.php`;
  `apps/api/tests/Feature/Accounting/ReportGenerationServiceExpectedCashRefundTest.php`;
  `apps/api/tests/Feature/POS/CrossTerminalReceiptLookupTest.php`;
  `apps/api/tests/Feature/POS/ReceiptHashServiceVerifyLegacyArmV3Test.php`.
- Projection-check precision: every new bcmath comparison in
  `PosCoreReceiptProjection.php`/`TreasuryReceiptBridge.php` added by this manifest carries a
  `// precision-ok: scale-4` (quantity) or currency-scale marker per rule 19, and every new
  projection test calls `app(CompanyContext::class)->clear()` before `apply()` (rule 20/Codex
  Important finding), passing entity currency explicitly to any scale resolution.

**`apps/pos`:**
- New payload builder: `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts` — composes
  through `buildSaleReceiptV3Payload` (§2.1), never forks it.
- `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts` — author version 4 for `SALE_RECEIPT`
  when `invoice_type_code ∈ {REFUND, VOID}` (VOID authoring stays unreachable per §4.3, but the
  version resolution itself is uniform); version 3 unchanged for SALE/TRAINING.
- `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts` — add
  `resolveOriginalFiscalEventLocally()` (original + its `line_items[]`/`payments[]` for
  index-based reference resolution, §2.4) and a training-flag read.
- New SQLite migration + repository: `apps/pos/src/lib/db/migrations.ts` (new `refund_intents`
  table, §3.2) and `apps/pos/src/lib/db/repositories/refundIntentRepository.ts`.
- New file: `apps/pos/src/lib/offline/refundReceiptService.ts` — the write-gate transaction
  (§3.3).
- `apps/pos/src/lib/refundFlow/refundApproval.ts` — replaced by
  `authorRefundReturnApprovalV2()` binding device-stable targets (§4.1); force-sync call
  removed.
- `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts` — no field changes (already
  correct, §2.4); only its caller changes.
- `apps/pos/src/lib/refundFlow/refundSettlementService.ts` — retained for the legacy-fallback
  cases only (v2 originals, store-voucher destination §6.2, VOID §4.3); its online-only
  `prepareRefundSettlement()` path is no longer the default for same-device v3/v4 refunds.
- New file: `apps/pos/src/lib/refundFlow/crossTerminalRefundLookup.ts` — calls §4.4's new
  endpoint.
- `apps/pos/src/stores/refundCheckoutStore.ts` — rewritten around `refund_intents` state
  transitions (§3.4) replacing the current Zustand-only `approveAndSubmit()`.
- `apps/pos/src/lib/refundFlow/refundZAccounting.ts` and
  `apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts` — both retired (deleted),
  replaced by `refundIntentRepository.ts` reads.
- `apps/pos/src/lib/offline/zReportService.ts`, `apps/pos/src/lib/offline/endOfDayPreview.ts` —
  rewired to read `refund_intents` (§5.4).
- `apps/pos/src/lib/buildReceiptData.ts` — unconditional AVOIR rounding line (§5.4/§8).
- `apps/pos/src/components/pos/RefundDestinationPicker.tsx` and
  `apps/pos/src/components/pos/RefundCheckoutFlow.tsx` — wire `allowedDestinations` and the new
  proration display from §4.4's lookup response (currently unwired, confirmed).
- `apps/pos/src/lib/i18n/` — new translation keys for: the §2.4/§4.3 typed 409/422 legacy-guard
  message, the §6.2 store-voucher-destination fallback message, the §6.6 training-refusal
  message, the §4.4 old-client-version upgrade prompt. Exact namespace file per existing i18n
  convention (`.claude/context/i18n.md`), added by the code phase under the existing
  `refundFlow` namespace.
- Tests: `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.test.ts` (mirrors
  `SaleReceiptV3Payload.test.ts`'s invariant/bind coverage, including a dedicated cash-only-gate
  case, §5.1); `apps/pos/src/lib/db/repositories/__tests__/refundIntentRepository.test.ts`;
  `apps/pos/src/lib/offline/__tests__/refundReceiptService.test.ts`; `apps/pos/src/stores/__tests__/refundCheckoutStore.test.ts`
  (rewritten for the new state machine); `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts`
  — **extended** (never forked) once Lane B merges, adding refund-rounding line items (§5.4).

**Explicitly NOT touched:** `apps/pos/src/lib/payment/cashRounding.ts` (read-only dependency,
§5.1 — reused, not modified); `apps/api/app/Modules/Voucher/Application/Services/VoucherIssuanceService.php`
(unchanged — store-voucher refunds stay on the legacy path, §6.2); the VOID authoring surface
(does not exist and is not created, §4.3).

---

## 10. Note on citation corrections

Every specific correction (stale line ranges, wrong SQLSTATEs, wrong initial values, wrong
"only builder"/"no GL exists"/"force-sync unchanged"/"non-retryable exists today" claims, and
the internal `§3.3a`/`§3.3b`/`§3.3d` references that did not correspond to any heading) is fixed
in place in the relevant section above rather than repeated in a separate ledger — Revision 1's
own separate ledger was flagged as adding indirection without adding correctness. Every citation
in this revision was re-read in this worktree at write time.
