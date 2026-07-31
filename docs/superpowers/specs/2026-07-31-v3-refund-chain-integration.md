# v3 Refund/Void Chain Integration — Design Spec

**Lane:** C (first-tenant launch program, `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md`
§Lane C). **Phase:** SPEC ONLY — zero code changes in this commit.
**Author verification method:** every citation below was re-read in this worktree
(`apps/erp.refund-chain`, branch `feat/v3-refund-chain`, based on `origin/dev @ 711f3d79f`)
on 2026-07-31. Several line numbers in the dispatch brief and the round-2 Codex review had
drifted since those documents were written; corrected numbers are used throughout and
drift is called out explicitly where it matters.

**Review gate this spec must pass:** fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex
adversarial pass. Per the dispatch plan, this spec cannot dodge: offline policy, the
atomicity state machine, VOID, cross-terminal/mixed-v2 originals, mixed-tender destination
rounding, or the Z-summary decision. Every decision below is an explicit ruling, not a
deferral.

---

## 0. Executive summary

Today, on a `fiscal_schema_version = 3` terminal, the **only** refund path
(`refundCheckoutStore.ts` → `POST /pos/receipts/{id}/return` → `ReceiptReturnService` →
`ReceiptFinalizationService`) seals the return receipt against a **legacy, independent
sequence/hash counter** (`pos_terminals.current_sequence` / `last_hash`) that nothing in
the v3 device-authored path (`FiscalEventEngine.append()` → `fiscal_events` →
`PosCoreReceiptProjection`) ever touches or synchronizes. This is not a hypothetical edge
case — it is structurally guaranteed to happen on every v3 terminal that ever processes a
return, because a return requires a pre-existing original sale, and every v3 sale is
authored into the `fiscal_events` chain, never into the legacy counter. The result is (a) a
standing compliance gap for every refund that succeeds, and (b) an eventual hard database
failure (Postgres unique-constraint violation, uncaught, 500, full rollback) for some refund
in the terminal's lifetime. Full derivation in §1.

The fix is **not** to patch the legacy path to coexist with v3 — it is to make refunds (and,
with an explicit compensating control, voids) **first-class members of the same
`fiscal_events` append-only chain that sales already use**, via the SALE_RECEIPT event's own
`invoice_type_code = 'REFUND' | 'VOID'` discriminator. Critically, the **server side of this
design already exists and is already tested** (`FiscalPayloadConstraintValidator`,
`PosCoreReceiptProjection`, `TreasuryReceiptBridge`, golden fixture `F-09-refund-eur`) — the
gap is entirely on the **device side**: no code path today ever calls
`FiscalEventEngine.append('SALE_RECEIPT', { invoice_type_code: 'REFUND', ... })`. §2 gives the
integration design; §3 rules on the four (five, see §3e) mandatory decision areas; §4 folds in
E1 refund rounding; §5 confirms the constraints are honored; §6 freezes the code-phase write
manifest.

---

## 1. Today's failure mode, precisely

### 1.1 Two independent, uncoordinated chain-sequence counters

**Chain A — the authoritative v3 device chain.** Every device-authored fiscal event (sale,
session open/close, cash movements, etc.) on a terminal advances a single per-terminal,
per-`chain_context` counter that lives entirely in `fiscal_events`:
`FiscalEventEngine.ts:606-611` — `sequenceNumber = head.fiscal_event_sequence + 1`, chained via
`previousHash = head.fiscal_event_last_hash` (or the genesis seed for the first event). This
state is read from `terminal_state` (device-local), never from any server table, and is
**never** written back into `pos_terminals.last_hash` / `pos_terminals.current_sequence` —
grep confirms `terminal.current_sequence`/`terminal.last_hash` are touched only by
`ReceiptCreationService.php:496,499,643` (the retired legacy new-sale path — 410 Gone per
`ReceiptFinalizationService.php:40-58`) and `ReceiptFinalizationService.php:94-95,109-111`
(return/void seal). Nothing in `PosCoreReceiptProjection.php`, which projects device
`fiscal_events` rows into `pos_receipts`, ever touches `Terminal`'s chain columns — it writes
`chain_sequence = $event->sequence_number` and `fiscal_hash = $event->current_hash` directly
from the fiscal event (`PosCoreReceiptProjection.php:325-328`), matching the class docblock:
*"The legacy chain columns on `pos_receipts` (`fiscal_hash`, `previous_hash`,
`chain_sequence`) are no longer advanced independently — they mirror the authoritative
`fiscal_events` row's values… The chain truth lives in `fiscal_events`."*
(`PosCoreReceiptProjection.php:84-90`).

**Chain B — the legacy counter the return path still uses.** `ReceiptReturnService`'s draft
builder reads (and, later, `ReceiptFinalizationService` advances)
`terminal.current_sequence`/`terminal.last_hash` directly:
`ReceiptReturnService.php:730-736` (`$sequence = $terminal->current_sequence`, format
`RET-{code}-{year}-{seq}`) and `ReceiptFinalizationService.php:94-95` (`$receipt->previous_hash
= $terminal->last_hash; $receipt->chain_sequence = $terminal->current_sequence;`), then
`:109-111` advances both after hashing. `current_sequence` defaults to `0` on terminal creation
(`2026_01_08_190429_create_pos_terminals_table.php:40`) and, on a pure-v3 terminal (no legacy
sale was ever created through the dead `ReceiptCreationService` path), is **only ever touched
by returns/voids** — it tracks "how many legacy-path corrections has this terminal ever had,"
not "how many receipts."

**These two counters share one physical uniqueness constraint.** `pos_receipts` has
`UNIQUE(terminal_id, receipt_year, chain_sequence)` — `pos_receipts_terminal_sequence`
(`2026_01_08_190637_create_pos_receipts_table.php:93,97`) — with **no carve-out for
`fiscal_event_id IS NULL` vs `NOT NULL`**. Chain A's values are the fiscal event's
`sequence_number`, a single counter shared by **every** event type on the terminal (session
open, cash-in/out, X/Z reports, sales — not a sales-only counter), so v3 sale receipts occupy
**sparse, typically-not-contiguous-from-1** integers in that space. Chain B's values are
`0, 1, 2, …` per return/void, starting from `0`. Both write into the same
`(terminal_id, receipt_year, chain_sequence)` key space.

### 1.2 The two concrete, observable symptoms

**(a) Standing compliance blind spot (happens on every successful legacy return on a v3
terminal, starting with the first one).** `pos_terminals.last_hash` is `NULL` on a fresh v3
terminal (never written by the v3 sale path), so the first legacy return's `previous_hash` is
`NULL` — it starts its **own, isolated one-link chain**, cryptographically disconnected from
the terminal's real `fiscal_events` chain. `pos:verify-chains`
(`VerifyPosChainCommand.php`) explicitly **excludes** fiscal-event-backed rows from its legacy
verification (`->whereNull('fiscal_event_id')` at `VerifyPosChainCommand.php:180,217`,
documented rationale at `:171-178`: *"projection rows… have their authoritative integrity
verified by `fiscal:verify-event-chain`… their `fiscal_hash` is the canonical-bytes SHA-256
from the fiscal event, not the legacy pipe-string SHA-256 this command recomputes."* On a v3
terminal the **only** rows left in that legacy-verifier's scope are exactly the orphan
return/void receipts — so `pos:verify-chains` reports `✓ valid` for a trivially-short,
self-consistent, but **fiscally meaningless** side-chain that has zero cryptographic linkage
to any sale. The real verifier, `fiscal:verify-event-chain`
(`VerifyEventChainCommand.php`), never sees these refunds at all, because they were never
written to `fiscal_events`. An auditor pulling the "real" v3 chain report would see the
terminal's sales but **no record that any refund ever happened against them** — the NF525
audit trail for the refund exists only as a legacy-format row nobody's canonical verifier
checks.

**(b) Deterministic hard failure (happens once the two counters' values re-intersect, which is
a structural certainty over the terminal's lifetime, not a rare edge case).** When
`ReceiptFinalizationService::finalize()` sets `chain_sequence = terminal->current_sequence`
and calls `$receipt->save()` (`ReceiptFinalizationService.php:95,107`) at a value that some v3
`fiscal_events`-backed sale (or an earlier legacy return) already occupies for that
`(terminal_id, receipt_year)`, Postgres raises `SQLSTATE 23505` on
`pos_receipts_terminal_sequence`. `ReceiptReturnService`'s **only** `QueryException` catch
(`ReceiptReturnService.php:148-166`) is narrowly scoped: `isRefundRequestIdUniqueViolation()`
(`:519-530`) matches only when the error message contains the literal string
`'refund_request_id'` — the chain-sequence violation's message names the different index
(`pos_receipts_terminal_sequence`) and does **not** match, so the exception re-throws
uncaught out of `processReturn()`. The whole `DB::transaction()` (stock restore, voucher
issuance, payment refund, cash-drawer op — the entire pipeline described in
`ReceiptReturnService.php:51-70`) rolls back atomically. The operator sees a bare 500; nothing
is quarantined (`Quarantined` is a `fiscal_events` chain-tamper concept — this path never
touches `fiscal_events`) and nothing double-books (the DB constraint blocks the write) — it is
simply an unhandled, unrecoverable failure of the entire refund attempt, with no domain-level
error message and no guidance to retry a different way.

**Why no existing test caught this:** every fixture in `ReceiptReturnRefactorTest.php`
explicitly pins a v2 terminal — `fiscal_schema_version => 2` at `:494` (drift note: the brief
cited `:490`; the fixture block spans `:490-496`, the pin itself is at `:494`). On a v2
terminal, `terminal.current_sequence`/`last_hash` **are** the terminal's only counters (every
v2 sale advances them too, via the still-live legacy creation path for v2), so there is no
second, uncoordinated chain to collide with. No test in the repository creates a v3 terminal,
authors a `fiscal_events` sale through it, and then calls `ReceiptReturnService::processReturn`
or `POST /pos/receipts/{id}/return` against it — confirmed by search: zero test files combine
a `fiscal_schema_version = 3` terminal with `ReceiptReturnService`/`ReceiptController::void`.

### 1.3 Shape of the missing integration test (never edit `ReceiptReturnRefactorTest.php` in
place — it correctly pins v2 forever; it gets a v3 **twin**)

New file `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php` (or, if the code-phase
prefers, a dedicated `V3RefundChainIntegrationTest.php` under
`apps/api/tests/Feature/Fiscal/`), asserting the full chain survives a same-terminal
sale → refund → sale → Z sequence, entirely through the **new** device-authored path (§2):

1. Create a `fiscal_schema_version = 3` terminal, open a session (`SESSION_OPEN` fiscal
   event).
2. Author a `SALE_RECEIPT` fiscal event (`invoice_type_code = 'SALE'`) exactly as
   `receiptService.ts` does today (`event.append()` → `PosCoreReceiptProjection::apply()`),
   producing sale receipt S1 at `fiscal_events.sequence_number = N`.
3. Author a `SALE_RECEIPT` fiscal event with `invoice_type_code = 'REFUND'` and
   `original_receipt_reference` pointing at S1's fiscal event (the new device-side refund
   authoring path from §2), landing at `sequence_number = N+k`.
4. Assert: `PosCoreReceiptProjection` projects it to `ReceiptType::Return`
   (`resolveReceiptType`, `PosCoreReceiptProjection.php:537-544`); `TreasuryReceiptBridge`
   posts the GL reversal and drawer `Out` movement (already proven in isolation by
   `PosRefundReceiptBridgeTest.php:135-165` — this test proves it end-to-end from device
   authoring, not from a hand-built `FiscalEvent` row); `pos_receipts.fiscal_event_id` is set
   (no orphan-chain path is reachable).
5. Author a **second** `SALE_RECEIPT` sale (`sequence_number = N+k+1`) — assert its
   `previous_hash` equals the refund event's `current_hash` (proves the chain is unbroken and
   the refund is IN it, not beside it).
6. Close the session (`SESSION_CLOSE`/`Z_REPORT`) — assert `fiscal:verify-event-chain
   --terminal=<id>` exits 0 and the refund appears in its output (proves the refund is now
   visible to the **real** verifier, closing the §1.2(a) blind spot).
7. Negative companion: attempt `POST /pos/receipts/{id}/return` (the **legacy** endpoint)
   against this same v3 terminal and assert it is now rejected (see §2.4's gate) rather than
   reproducing §1.2(b)'s 500.

This is the "green integration evidence" gate-sheet row E-7 in the dispatch plan requires
non-waivably.

---

## 2. The integration design

### 2.1 Who authors the refund fiscal event

**Ruling: device-authored, using the existing `SALE_RECEIPT` event type with
`invoice_type_code = 'REFUND'` — never a new/separate fiscal event type, and never
server-authored.** This is not a new design choice invented for this spec — it is the design
the codebase already committed to and already built the entire server half of:

- `SaleReceiptPayloadInput.invoice_type_code: 'SALE' | 'REFUND' | 'VOID' | 'TRAINING'`
  (`FiscalEventEngine.ts:408`) and `original_receipt_reference:
  OriginalReceiptReferenceInput | null` (`:413`, shape at `:367-376`:
  `fiscal_event_id`, `original_business_date`, `original_receipt_uuid`, `refund_reason`) have
  existed on the device-side payload contract since v5 §6.A — the runtime validator already
  enforces "required iff REFUND/VOID" (`FiscalEventEngine.ts:2621-2648`).
- `FiscalPayloadConstraintValidator.php:1784-1823` (server mirror of the same rule) and
  `:2724-2733` document explicitly: *"Refunds are modeled via `invoice_type_code='REFUND'` +
  `original_receipt_reference`, NOT negative amounts"* — money fields on a REFUND SALE_RECEIPT
  payload are **non-negative magnitudes**, same regex as a sale (`moneyRegex()`,
  `:2735-2742`), with direction carried entirely by `invoice_type_code`.
- `PosCoreReceiptProjection::resolveReceiptType()` (`:537-544`) already maps
  `invoice_type_code IN ('REFUND','VOID')` + a resolved original → `ReceiptType::Return`;
  `assertOriginalReceiptResolvableForRefundOrVoid()` (`:564-603`) already fail-closes when the
  original hasn't projected locally yet (retryable — see §3.3d); `resolveOriginalReceiptId()`
  (`:617-637`) already resolves the link via `pos_receipts.fiscal_event_id`.
- `TreasuryReceiptBridge` already branches on the code: refund/void leg → drawer `Out` +
  GL reversal; normal sale leg → drawer `In` + sale GL — proven end-to-end by
  `PosRefundReceiptBridgeTest.php:41-58,135-165` (drawer decrement, `pos_receipt_refund`
  journal entry, idempotent replay) and `PosBridgeInstrumentRefundTest.php`.
- A golden fixture already exists for this exact shape:
  `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/F-09-refund-eur/payload.json`.

The comment at `PosRefundReceiptBridgeTest.php:46-47` states it plainly: *"There is NO
separate REFUND fiscal event type (REFUND_RECEIPT / SALE_VOID are RESERVED_UNREACHABLE and
never produced)."* `SALE_VOID` / `REFUND_RECEIPT` remain in `FISCAL_EVENT_TYPES`
(`FiscalEventPayloadRegistry.ts:65,67` — drift note: brief cited `:65,84`; `84` is actually
the start of the *separate* `IMPLEMENTED_EVENT_TYPES` array, not a second reserved-type
citation) but are **absent** from `IMPLEMENTED_EVENT_TYPES` (`:84-107`), so
`FiscalEventPayloadRegistry.eventVersionFor()` throws `FiscalEventTypeNotImplementedError`
for either — confirming they are a dead axis, not a design this spec should revive.

**The gap is exclusively device-side authoring.** `SaleReceiptPayload.ts:159,163` — the
**only** function that builds a `SALE_RECEIPT` payload today — hardcodes
`invoice_type_code: input.isTraining ? 'TRAINING' : 'SALE'` and
`original_receipt_reference: null`, unconditionally. `refundCheckoutStore.ts:331-408`
(the entire refund UI flow) never calls `FiscalEventEngine.append()` for a SALE_RECEIPT at
all — it authors the `OPERATOR_APPROVAL_GRANTED`/`OVERRIDE_VOID_OR_RETURN` approval evidence
(step 1a/1b, unchanged by this design — see below) and then POSTs directly to the legacy
`/return` endpoint via `refundSettlementService.ts:475-494`. No test, no store, no payload
builder anywhere in `apps/pos/src` constructs a REFUND-typed SALE_RECEIPT payload outside the
TS engine's own unit tests of its parser/validator.

### 2.2 The new device-side authoring flow (replaces step 2 of today's flow; step 1
unchanged)

Today's `approveAndSubmit()` (`refundCheckoutStore.ts:286-409`) has two phases: (1) author +
sync the manager-PIN approval evidence (`OPERATOR_APPROVAL_GRANTED` +
`OVERRIDE_VOID_OR_RETURN`, `:331-372`), then (2) POST the settlement (`:374-408`). Phase 1 is
**kept as-is** — it already produces exactly the fiscal-event evidence the new payload needs:
`SaleReceiptApprovalReferenceInput` (`FiscalEventEngine.ts:444-452`,
`approval_scope: 'void_or_return_override'`) is structurally identical to
`RefundApprovalEvidence` (`refundSettlementService.ts:82-90`) already produced by
`authorRefundReturnApproval()`. Phase 2 is **replaced**:

1. **New payload builder** `buildRefundReceiptPayload()`, sibling to
   `buildSaleReceiptPayload()` in a new file
   `apps/pos/src/lib/fiscal/payloads/RefundReceiptPayload.ts` (mirrors
   `SaleReceiptPayload.ts`'s structure/invariant-checking exactly, but sets
   `invoice_type_code: 'REFUND'`, threads a non-null `original_receipt_reference`, and folds
   in the approval evidence from phase 1 into `approval_references`).
2. **New local lookup** `resolveOriginalFiscalEventLocally()` in
   `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts`: `SELECT id, business_date,
   current_hash FROM fiscal_events WHERE source_event_class = 'offline_receipts' AND
   source_event_id = ?` — this schema already exists (`FiscalEventEngine.ts` writes
   `source_event_class: 'offline_receipts', source_event_id: receiptId` for every sale,
   `receiptService.ts:502-504`; the local mirror table carries the same columns,
   `fiscalEventRepository.ts:6-29`). This resolves `original_receipt_reference.fiscal_event_id`
   / `.original_business_date` **without any network call** when the original sale was
   authored on **this** device (see §3.3a for the cross-terminal case, which cannot use this
   lookup).
3. **New engine call**: `engine.append(tx, { event_type: 'SALE_RECEIPT', payload:
   refundCanonicalPayload, reference_event_id: approvalEventId, source_event_class:
   'offline_receipts_refund', source_event_id: refundId, ... })` — reuses the exact
   single-writer transaction pattern `receiptService.ts:479-505` already uses for sales
   (`withWriteTransaction('fiscal', …)`), so the refund gets the same offline-durable,
   crash-safe local commit a sale gets today.
4. **Sync**: the refund event rides the **existing** fiscal-event outbox/sync pipeline
   (`getPendingFiscalEventsForSync`, `fiscalEventRepository.ts:76-88`) — no bespoke
   `/return` HTTP call. On the server, ingestion → `FiscalPayloadConstraintValidator` →
   `PosCoreReceiptProjection` → `TreasuryReceiptBridge` are **already built and tested** (§2.1)
   and require no server-side authoring changes for the happy path.
5. **Local Z accounting**: `recordRefundSettlementForZ()`/`refundZAccounting.ts` is retired in
   its current "record-at-settle from the HTTP response" form (there is no HTTP response to
   mirror from anymore) and replaced by reading the refund's own local `fiscal_events` row —
   the device already has everything it needs (amount, destination, cash impact) the moment
   `engine.append()` returns, with no server round-trip required. See §3.3b for exactly how
   this changes the atomicity story.

### 2.3 What happens to `ReceiptReturnService` / `ReceiptFinalizationService` / the `/return`
and `/void` HTTP endpoints

They are **not deleted** — `ReceiptFinalizationService.php:44-56`'s own docblock already
documents `ReceiptReturnService::createReturn` as a "knowingly-retained carve-out" alongside
void, legacy receipt sync, and `ExchangeService`, none of which are in this spec's scope to
remove. They remain the only path for **v2 terminals** (where they are correct and tested,
§1.2's failure mode is v3-only) and, per §3.3a's ruling, **may** remain a fallback surface for
specific v3 cross-terminal cases. What changes:

### 2.4 Mandatory near-term hardening (independent of the full device-authored build, should
land first)

Regardless of how quickly the full flow in §2.2 ships, §1.2(b)'s crash must be closed
immediately: add a `terminal.fiscal_schema_version >= 3` guard to
`ReceiptReturnService::processReturn` and `ReceiptVoidService::voidReceipt` (mirroring the
existing pattern already used in `ShiftController.php:60,124`, `SyncController.php:53`,
`ZReportSyncController.php:75` — `(int) ($terminal->fiscal_schema_version ?? 2) >= 3`),
returning a typed 409/422 the POS UI maps to "this terminal requires the v3 refund flow" (or,
until §2.2 ships, "settle this refund at a v2 terminal"). This alone converts §1.2(b)'s
uncaught 500 into a controlled, documented rejection and closes §1.2(a)'s silent
compliance gap (no more orphan chains can be created), at the cost of refunds being
**unavailable** on v3 terminals until §2.2 ships. This is listed as its own line in §6's
manifest because it can and should land before the full flow, as the minimum viable
E-7 prerequisite.

---

## 3. The four mandatory decision areas

### 3a. Offline / unsynced refunds

**Finding that changes the premise of the round-2 Codex review's framing:** the review
(`2026-07-31-codex-v2-dispatch-review.md` Critical #3.1) correctly identifies that
`refundSettlementService.ts:4,9` is hard-coded online-only and rejects unsynced originals
(`resolveServerReceiptId()`, `refundSettlementService.ts:264-288`, returns `null` → typed
`NOT_SYNCED` when the local `receipt_qr_index` has no entry for the original). But that
constraint is an artifact of the **current, server-settlement-first** design (§0), not an
inherent limitation of v3. Under §2.2's device-authored design, a refund for an original sale
**authored on this same device** needs **no server round-trip at all** to build a valid,
signable payload: `original_receipt_reference.fiscal_event_id` /
`.original_business_date` / `.original_receipt_uuid` are all resolvable from the device's own
local `fiscal_events` mirror (§2.2 step 2), exactly as `receiptService.ts` already resolves
everything it needs for a sale without touching the network.

**Ruling (two-tier policy, replacing "online-only" with "same-device-resolvable"):**

- **Same-device original (found via `resolveOriginalFiscalEventLocally()`):** the refund
  **works fully offline** — the device signs and locally commits the SALE_RECEIPT+REFUND
  event exactly as it does for a sale, and it syncs whenever connectivity returns, through the
  ordinary fiscal-event outbox. This is not new risk surface: it is the **same** offline
  guarantee the codebase already gives every sale.
- **Original not found locally (never sold on this device, or sold on this device but its
  local `fiscal_events` row was pruned/reset — should not happen under normal operation but is
  not assumed impossible):** the refund is refused at the device with a typed error
  ("original receipt not found on this terminal — settle at the terminal of sale, or connect
  to look up a cross-terminal original"). Whether cross-terminal originals get an **online**
  lookup path at all is ruled separately in §3.3d, because it is the same unresolvability
  problem the server-side projector already documents (`PosCoreReceiptProjection.php:605-615`)
  for the reverse direction (server projecting a REFUND whose original hasn't synced yet).

This is a strictly **more capable** launch policy than today's (which is fully online-only for
every refund, synced or not), while remaining conservative: it never claims to resolve an
original the device cannot prove it authored.

### 3b. Atomicity & failure ordering

**Today's pattern (for contrast):** settle-then-mirror. The server commits the refund
authoritatively first (`/return`'s `DB::transaction()`); the device's local Z mirror is a
best-effort, allowed-to-fail follow-up (`refundZAccounting.ts:9-23`: *"Record-at-settle means
a device crash between the server settle and this local write undercounts the device Z… the
refund itself is NEVER un-settled"* — the recorder never throws, `recordRefundSettlementForZ`
returns `false` on failure and the caller only surfaces a warning).

**Under §2.2, the ordering inverts: author-then-sync**, same as every v3 sale today. This
spec defines the state machine explicitly rather than leaving it implicit:

| State | Trigger | Recovery |
|---|---|---|
| **S0 — Approval authored, not yet synced** | `authorRefundReturnApproval()` succeeds locally (unchanged step, `refundCheckoutStore.ts:335-355`) | Unchanged from today: cached in store state, retried at "sync only" on next attempt (`:359-372`); never re-authored. |
| **S1 — Approval sync fails (network)** | `syncRefundApprovalEvents()` throws | Unchanged from today (`:360-369`): UI returns to `approval` step, cashier retries; approval evidence is retained. |
| **S2 — Refund SALE_RECEIPT signed + committed locally, sync pending** | `engine.append()` returns inside `withWriteTransaction('fiscal', …)` (§2.2 step 3) | **New, and the key win of this design over §0's pattern:** the refund is *already* fiscally final and irreversible the instant this transaction commits — there is no "settlement rejected after the event was authored" failure mode anymore, because there is no server round-trip in the critical path. The event syncs whenever connectivity allows, via the same retry/outbox machinery every sale already relies on (`getPendingFiscalEventsForSync`, `recoverStrandedSyncingFiscalEvents`). |
| **S3 — Local commit itself fails (disk/crash) between the write-gate transaction's steps** | Same failure surface a sale already has (single-writer `withWriteTransaction` is all-or-nothing per `receiptService.ts:472-483`'s comment) | Unchanged infrastructure: the transaction is atomic — either the refund event + local receipt + voucher/Z bookkeeping all commit, or none do. No new failure mode introduced; this spec adds no new local-write surface beyond what `buildRefundReceiptPayload` needs, all inside the existing gate. |
| **S4 — Event signed+committed, but the SERVER later refuses to project it (§3e's quantity-cap dead-letter, or an unresolvable original per §3.3d)** | `PosCoreReceiptProjection::apply()` throws a non-retryable exception after exhausting Horizon's 5 tries | **New state this design must own, because it did not exist before** (the legacy path validated synchronously, before commit — §3e explains why that is no longer possible). The fiscal event remains chained, hashed, and verified (`integrity_status = verified`) — it is real, immutable evidence that a refund was signed — but produces **no** `pos_receipts` row and **no** GL/drawer effect. `ProjectionStatus::DeadLettered` (already-existing enum value, `ProjectionStatus.php`) surfaces it for manual fiscal-officer review. This is a *deliberate* asymmetry: the device's local Z ledger reads its own signed event (§2.2 step 5) and will show the refund as settled even though the server-side business effects never landed — this divergence must be surfacer-visible (see §6, new `pos_refund_dead_letter_report` or equivalent operator-facing signal — code-phase decision, not specified further here since it's outside this spec's write manifest). |
| **S5 — Two devices race a double-refund on the same original (both offline, both resolve the same local — or cross-synced — original before either syncs)** | Concurrent authoring on different terminals | **Not preventable device-side** (each device only knows its own state at author time) — this is the same class of race the codebase already accepts for offline sales (`FiscalEventEngine`'s idempotency is on `(tenant_id, terminal_id, source_event_class, source_event_id)`, which cannot detect a *different* terminal's refund of the *same original*). The **backstop is server-side and post-hoc**, same principle as the existing daily-cap/quantity enforcement: `PosCoreReceiptProjection` should apply the same quantity-cap check from §3e per-original, so that whichever refund event arrives **second** dead-letters rather than silently over-crediting. This is a real residual risk that must be named as a launch-time accepted risk if the code phase cannot close it before E-7, not silently assumed away. |

### 3c. VOID

**Ruling: VOID is integrated into the same design as REFUND for launch, using the identical
`invoice_type_code = 'VOID'` mechanism — it is not ruled out of scope, and it is not left as
today's legacy-only mutation, because the current VOID implementation is a real,
already-shipped fiscal-integrity gap that this spec's failure-mode analysis makes hard to
justify leaving open once refunds are fixed.**

Evidence for why VOID cannot be left alone: `ReceiptVoidService::voidReceipt()`
(`ReceiptVoidService.php:53-131`) directly `UPDATE`s the **original** receipt row in place —
`$receipt->update(['is_voided' => true, 'fiscal_status' => FiscalStatus::Voided, …])`
(`:79-88`). For a v3 device-authored sale, that original row's `fiscal_event_id` is set and its
`canonical_bytes`/`fiscal_event_id` columns are permanently write-protected
(`PosCoreReceiptProjection.php:88-90`: *"NOT in the `prevent_receipt_modification` trigger's
whitelist — they MUST be set on INSERT and MUST NEVER be UPDATEd later"*) — but `is_voided`
and `fiscal_status` **are** apparently whitelisted (the code comment at
`ReceiptVoidService.php:75-78` confirms the DB trigger permits exactly the
`fiscalized -> voided` transition). The consequence: **the authoritative `fiscal_events` row
for that sale is never touched** — no `SALE_VOID` fiscal event is authored (confirmed
unimplemented, §2.1), no corresponding entry appears in the chain. `pos_receipts.is_voided`
diverges from `fiscal_events` truth the instant a void happens on a v3 terminal, and — per the
projector's own "mirror columns" contract (§2.1) — **any future rebuild of `pos_receipts` from
`fiscal_events`** (a legitimate, documented replay operation for this idempotent projector)
would **silently un-void** the receipt, since there is nothing in `fiscal_events` recording
that it was ever voided. This is strictly worse than the refund gap in §1, because it is a
silent, permanent data-loss risk on replay, not merely an eventual crash.

**The integration:** mirror §2.2 exactly, with `invoice_type_code = 'VOID'`. `VOID`'s
semantics differ from `REFUND` only in payload shape (a void reverses the *entire* original
sale, not a possibly-partial line set — `line_items` for a VOID event mirrors the original
sale's lines exactly, per the existing `original_receipt_reference` contract which does not
distinguish REFUND/VOID structurally, `FiscalPayloadConstraintValidator.php:1784-1823`
validates both identically). `ReceiptVoidService`'s in-place mutation is retired for v3
terminals under the same §2.4 gate that closes the return path. `PosCoreReceiptProjection`
already resolves `VOID` identically to `REFUND` in `resolveReceiptType()`
(`:539`: `($invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID')`), so the server side
needs **no** new VOID-specific work beyond what REFUND already requires — this is a
significant scope-reducer for the code phase, not a second parallel feature.

**Compensating control if the code phase cannot land device-authored VOID by launch:** keep
`ReceiptVoidService`'s legacy in-place-mutation path alive **only** as an explicitly-scoped,
documented exception (not silently retained), with a standing operational control: the
in-place `is_voided`/`fiscal_status` mutation is treated as **advisory/reporting-only** on v3
terminals (surfaced with a visible caveat wherever void status is displayed for a v3 receipt:
"chain does not reflect this void"), and any future `pos_receipts` rebuild-from-`fiscal_events`
tooling must **exclude** v3 receipts with legacy-void markers from being silently
un-voided (an explicit skip-list, not a default). This is a strictly worse fallback than
shipping the integration and is named here only so the code phase (or the owner, at E-7) has
an explicit, named choice rather than an implicit one.

### 3d. Mixed-v2 / cross-terminal originals

**Ruling: prohibited for v2/legacy originals; a new, explicit online-lookup contract for
cross-terminal v3 originals — never silently bridged.**

- **v2/legacy original (no `fiscal_event_id`):** `resolveOriginalReceiptId()`
  (`PosCoreReceiptProjection.php:617-637`) resolves strictly by
  `Receipt::query()->where('fiscal_event_id', $ref->fiscalEventId)` — a legacy receipt has
  `fiscal_event_id = NULL` and can **never** satisfy this lookup, by construction. A REFUND/VOID
  event referencing a legacy original is therefore **permanently unresolvable**, and
  `assertOriginalReceiptResolvableForRefundOrVoid()` (`:564-603`) would retry it via Horizon
  5 times and then dead-letter it forever — a guaranteed, wasted retry cycle for something that
  was never going to resolve. **Ruling: this must be rejected device-side, before authoring,**
  not left to fail server-side after the event is already signed and immutable. The device's
  local lookup (§2.2 step 2) only ever finds fiscal-event-backed originals by construction
  (it queries `fiscal_events`, which v2 sales never populate), so a legacy original naturally
  falls into the "not found locally" branch of §3a's policy — **this is already the correct
  behavior with no extra code**, provided the device's error message for that branch
  distinguishes "not on this device" from "this is a v2 receipt, refund it at any v2-capable
  terminal via the legacy `/return` endpoint, which remains correct for v2" (the legacy path is
  never gated off for v2, per §2.4).
- **v3 cross-terminal original (fiscal-event-backed, but authored on a different terminal, and
  not yet locally known to this device):** this is the case `PosCoreReceiptProjection.php:605-615`
  already documents server-side (*"Returns null when the original hasn't been projected locally
  — e.g. cross-terminal refund… The canonical `original_receipt_reference` remains the
  authoritative link"*), and it is retryable (dependency-missing, not permanently unresolvable)
  **once the original has synced from its own terminal**. **Ruling:** this refund requires
  connectivity — the device cannot locally resolve `fiscal_event_id`/`business_date` for an
  original it never authored, so a **new, small, read-only lookup endpoint** is needed:
  `GET /pos/receipts/lookup?receipt_number=…` (or reuse the existing
  `GET /pos/receipts/{id}` shape `refundSettlementService.ts:401` already calls, extended to
  return the resolved `fiscal_event_id`+`business_date` alongside the line data it already
  returns) — scoped to the same tenant/company, never cross-tenant. The cashier sees: "original
  sold at another terminal — connect to look it up" if offline, and the normal refund flow
  (now hitting the network once, for lookup only, not for settlement) if online. This keeps the
  design's offline story honest: **same-device is fully offline-capable; cross-terminal
  requires one online lookup, but settlement is still device-authored and chain-integrated**,
  never routed back through the legacy `/return` endpoint.
- **What happens if the cashier submits anyway and the server can't resolve it (race — original
  still mid-sync):** per §3b's S4, the event dead-letters after Horizon's retries exhaust
  (∼21 minutes, `ApplyFiscalEventProjectionJob.php:151-157`'s `$tries=5` backoff) and then it
  **does** resolve automatically once the original catches up sync — `OriginalReceiptUnresolvableException`
  extends `ProjectionDependencyMissingException`, which the job's retry contract already
  handles as retryable, not permanent (`PosCoreReceiptProjection.php:557-562`). No new code is
  needed for this sub-case; it is already correct.

### 3e. New finding not in the original four: quantity-cap enforcement moves from synchronous
pre-commit to a new permanent-failure lifecycle

The dispatch brief's §5 constraint ("`ReceiptReturnService.php:1058-1089` caps returns by
quantity not amount") is listed as something to *honor*, but honoring it under the new design
is not mechanical, and skipping this would be exactly the kind of dodge the review process is
meant to catch. Today, `validateReturnQuantities()` (`ReceiptReturnService.php:1058-1089`) runs
**synchronously, before the return receipt is created** — an over-quantity return is rejected
with a 4xx and the operator is told immediately, before anything is committed. Grep confirms
**no equivalent check exists anywhere in `PosCoreReceiptProjection.php`** — the server-side v3
projector performs zero already-returned-quantity validation.

Under §2.2, the refund event is **signed and locally committed by the device before the
server ever sees it** — `fiscal_events` rows are immutable (no UPDATE/DELETE/TRUNCATE,
`FiscalEvent` model docblock). There is no way to reject an over-quantity refund "before
commit" server-side anymore, because by the time the server sees it, it is already permanent
chain truth. **Ruling:**

1. **Device-side pre-flight (best-effort, not authoritative):** before calling
   `engine.append()`, the device computes already-returned quantity from its own locally-known
   refund history for that `original_receipt_uuid` (same local `fiscal_events` table, filtered
   by `original_receipt_reference`) and refuses to build the payload if it would exceed the
   original line quantity. This catches the overwhelming majority of real mistakes (same-device
   double-return) with the operator told immediately, exactly like today.
2. **Server-side backstop (authoritative, and the only thing that closes §3b's S5 cross-device
   race):** `PosCoreReceiptProjection` gains the **same** already-returned-quantity check
   `ReceiptReturnService::validateReturnQuantities`/`calculateAlreadyReturnedQuantities`
   (`:1058-1089`, `:1148-1182`) already implements, applied against **all** prior REFUND events
   resolved for that original (both fiscal-event-backed and, if still permitted per §3d,
   legacy). When it fails, the projection throws a new, **non-retryable** exception (does not
   extend `ProjectionDependencyMissingException` — retrying an over-quantity refund 5 times
   changes nothing) that reaches `ApplyFiscalEventProjectionJob`'s existing `catch (Throwable)`
   (`:394`) and, after `$tries` exhausts, lands in `ProjectionStatus::DeadLettered`
   (`:449-450`) — **the same, already-built lifecycle** used for every other permanent
   projection failure. No new infrastructure is required; only a new exception type and the
   quantity check itself. The fiscal event remains valid chain evidence (nothing is
   "un-signed"); it simply never produces business effects, and is surfaced for manual
   fiscal-officer reconciliation exactly like any other dead-lettered event.

---

## 4. E1 refund rounding on top

Per the adopted pattern (`docs/superpowers/specs/2026-07-27-refund-rounding-research.md`):
independent Swedish rounding of the CASH payout leg only, no unwinding of the original sale's
adjustment, partial refunds round independently on their own computed amount, VAT stays exact,
the signed delta posts to accounts 6580/7580 with its own receipt line.

**Integration into §2's design:**

- **Reuse, don't reimplement, the sale-side rounding functions.** `roundCashTotal()` and
  `computeRoundingAdjustment()` (`apps/pos/src/lib/payment/cashRounding.ts:140-171`) are
  already pure, string-in/string-out, side-effect-free functions with no sale-specific
  assumptions baked in — they round *a total*, not *a sale*. `buildRefundReceiptPayload()`
  (§2.2 step 1) calls them on the refund's computed **cash-destination payout amount** (not the
  full refund total when the destination is mixed-tender or non-cash — see below), exactly as
  `checkoutPolicySnapshot.ts` calls them for the sale total today. `isCashOnlyTender()`
  (`cashRounding.ts:180-186`) gates whether rounding applies at all — for a refund, this means
  rounding only ever touches the **cash leg** of the refund, never `store_voucher` or
  `original_payment` legs (matching the research doc's consensus #4 verbatim: card/voucher
  refunds are never rounded).
- **`cash_rounding_adjustment` / `cash_rounding_denomination` already exist on the payload
  contract** (`FiscalEventEngine.ts:428-431`, optional, v3-only) — no schema change needed; the
  refund payload builder simply populates them the same way the sale builder would if it were
  wired for rounding (it currently is not — that wiring is Lane B's `cartMutatorSelectors`
  scope, not this spec's).
- **Destination-specific behavior, ruled explicitly (this is exactly the "mixed-tender
  destination" decision the review flagged as missing):**
  - **`cash` destination:** the full refund payout is a cash leg → rounds. `total` in the
    canonical payload is the rounded amount (matching how v3 sales already work — "at v3 this
    is the ROUNDED total," `FiscalEventEngine.ts:426`); `cash_rounding_adjustment` carries the
    signed delta.
  - **`original_payment` destination (card, etc.):** never rounds — `cash_rounding_adjustment`
    is omitted/zero, `total` is exact. This applies per-leg if `original_payment` resolves to a
    mixed original tender (e.g. the original sale was part-cash/part-card): only the
    proportional **cash** leg of the refund, if any, rounds; the card leg never does. Server-side
    proration for `original_payment` already exists (`PaymentRefundService::refundReceiptPayments`,
    referenced in `ReceiptReturnService.php:64`) — the code phase's job is to make the
    **device-side** payload construction agree with whatever proration split the operator UI
    displays before signing, since the device signs the payload, not the server.
  - **`store_voucher` destination:** never rounds (Shopify precedent cited verbatim in the
    research doc: *"Refunds to a gift card aren't rounded"*).
- **Partial refunds round independently**, per the research doc's explicit ruling — no
  allocation/cap logic against the original sale's adjustment is needed; each refund event
  computes its own exact→rounded delta from its own computed total, full stop.
- **GL posting:** the signed delta posts to 6580 (loss) / 7580 (gain) with `source_type =
  pos_refund_rounding` (keeping it distinct from `pos_receipt_refund`/tolerance types per the
  research doc's note: *"keep the source_type distinction… so they remain separable in
  reporting"*) — this is new `TreasuryReceiptBridge` work, since today's bridge branch for
  refunds (`PosRefundReceiptBridgeTest.php`) predates E1 and has no rounding-aware assertions;
  confirmed absent by inspection (no `6580`/`7580`/`pos_refund_rounding` string anywhere in
  `TreasuryReceiptBridge.php` today).
- **Receipt line:** the refund's printed AVOIR gets its own rounding line, mirroring how a
  rounded sale receipt shows one — this is new work in `buildReceiptData.ts` (§5 explains why
  today's AVOIR builder explicitly cannot show one yet).
- **Z-summary — explicit ruling on the flagged open question:** **yes, refund rounding
  adjustments enter the Z `cash_rounding_summary`**, on the same principle as the rest of this
  design (a refund is a first-class chain member, not a side-channel) — the Z-report is the
  device's own summary of its own signed events for the shift, and a refund rounding
  adjustment is exactly as real a rounding event as a sale's. **This inherits Lane B's
  `zReportService.cashRounding.test.ts` and cannot land before Lane B merges** (per the
  dispatch plan's explicit sequencing: *"B3's Z test is B's until C's code phase declares its
  own manifest"*) — the code phase must extend that test file (not fork it) once B is merged,
  adding refund-rounding line items alongside the sale-rounding ones it already covers.

---

## 5. Constraints honored

- **`hydrateFromReceipt.ts:53` never reads `receipt.total`.** Confirmed unchanged by this
  design: the cart-hydration function (`hydrateFromReceipt.ts:49-86`) builds return line items
  exclusively from `receipt.lines` (the local `offline_receipts.lines` JSON,
  parsed at `:53`) — it has no dependency on the settlement response's `total` field today, and
  §2's design does not introduce one; the refund payload's `total`/aggregates are computed by
  the new `buildRefundReceiptPayload()` from the (possibly rounding-adjusted) line set, the same
  way `buildSaleReceiptPayload()` derives them, never by reading back a receipt-level total.
- **`ReceiptReturnService.php:1058-1089` caps returns by quantity, not amount.** Preserved and
  extended — see §3e's explicit ruling: the quantity-not-amount cap logic is reused
  server-side (moved into the projector as an authoritative backstop) and reused conceptually
  device-side (as a best-effort pre-flight), never replaced by an amount-based check.
- **The avoir carries no rounding line (`buildReceiptData.ts:712-713`).** Confirmed as current
  state: the return/refund print-data builder hardcodes `cash_rounding_adjustment: null,
  has_cash_rounding: false` (`buildReceiptData.ts:712-713`) — this is accurate today because no
  refund rounding exists yet. §4 explicitly changes this (new rounding line on the AVOIR) as
  part of E1's integration — this is a **planned, cited departure** from the current constraint,
  not an oversight; the constraint holds unless/until the code phase implements §4.

---

## 6. Frozen code-phase write manifest

Exact files the implementation will touch, stated against current `origin/dev`. This is the
contract the code phase's own dispatch/manifest must match; anything outside this list needs a
new spec addendum, not a silent scope add.

**`apps/api` (server side — mostly extension, since the projection/bridge core already
exists):**

- `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php` — add the
  `fiscal_schema_version >= 3` guard (§2.4); no other change to its v2-path logic.
- `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` — same guard (§2.4),
  scoped by the §3c ruling actually chosen (full integration vs. compensating-control fallback).
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` — new §3e
  quantity-cap check + new non-retryable exception type (new file, e.g.
  `apps/api/app/Modules/Fiscal/Domain/Exceptions/RefundQuantityExceededException.php`, alongside
  the existing `OriginalReceiptUnresolvableException`).
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` — E1
  rounding-aware GL posting for the refund cash leg (§4: 6580/7580, `source_type =
  pos_refund_rounding`).
- New read-only lookup endpoint + controller method for §3d's cross-terminal case (route +
  a small resolver service; exact naming is a code-phase decision, not frozen here beyond "GET,
  read-only, tenant/company-scoped, returns `fiscal_event_id` + `business_date` for a receipt
  number").
- New tests (additive only, per §1.3 and §3e/§3d/§3c rulings):
  `apps/api/tests/Feature/POS/ReceiptReturnRefactorV3Test.php` (the §1.3 twin — never edits
  `ReceiptReturnRefactorTest.php`), a projection test for the §3e dead-letter path, a
  projection test for the §3d cross-terminal lookup/retry path, a `TreasuryReceiptBridge`
  rounding-aware refund test extending the `PosRefundReceiptBridgeTest.php` family (new file,
  same directory).

**`apps/pos` (device side — the actual gap this spec closes):**

- `apps/pos/src/lib/fiscal/payloads/RefundReceiptPayload.ts` — new file, `buildRefundReceiptPayload()`
  (§2.2 step 1), sibling to `SaleReceiptPayload.ts`.
- `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts` — add
  `resolveOriginalFiscalEventLocally()` (§2.2 step 2; read-only query against the existing
  `fiscal_events` local table, no schema migration).
- `apps/pos/src/lib/offline/refundReceiptService.ts` — new file, sibling to
  `apps/pos/src/lib/offline/receiptService.ts`, hosting the `engine.append()` call (§2.2 step 3)
  inside the existing `withWriteTransaction('fiscal', …)` gate.
- `apps/pos/src/stores/refundCheckoutStore.ts` — phase-2 of `approveAndSubmit()`
  (`:374-408`) replaced to call the new local authoring path instead of
  `submitRefundReturn()`; phase-1 (`:331-372`, approval authoring/sync) unchanged.
- `apps/pos/src/lib/refundFlow/refundSettlementService.ts` — `prepareRefundSettlement()`'s
  online-only resolution (§0/§3a) is superseded for the same-device case by the new local
  lookup; the file is not deleted (still used for the §3d cross-terminal online-lookup call and
  as the v2-terminal path).
- `apps/pos/src/lib/refundFlow/refundZAccounting.ts` — retired/replaced per §2.2 step 5 (reads
  the local fiscal event instead of an HTTP response); `hydrateFromReceipt.ts` unchanged (§5).
- `apps/pos/src/lib/payment/cashRounding.ts` — **no changes** (§4 explicitly reuses it
  unmodified; new call sites live in `RefundReceiptPayload.ts`).
- `apps/pos/src/lib/buildReceiptData.ts` — extend the AVOIR builder for the §4 rounding line
  (only if E1's refund-rounding is in scope for the same code-phase batch; otherwise deferred
  to a follow-on so §5's "no rounding line" constraint continues to hold accurately in the
  interim).
- New tests: an authoring-path unit test for `RefundReceiptPayload.ts` (mirrors
  `SaleReceiptPayload`'s existing invariant tests), a `fiscalEventRepository` test for the new
  local lookup, a `refundCheckoutStore` integration test replacing/extending the current
  approval+submit flow test to assert the new local-authoring call, and — once Lane B merges —
  an extension of `zReportService.cashRounding.test.ts` for refund-rounding line items (§4).

**Explicitly NOT touched by this manifest:** `FiscalEventPayloadRegistry.ts` (no new event
type — REFUND/VOID ride the existing SALE_RECEIPT type), `FiscalPayloadConstraintValidator.php`
(REFUND/VOID validation already complete), `CashDrawerService.php` (legacy-only concept, not
used by the v3 device-authored path — §2.2 step 5 replaces its role entirely for v3),
`ReceiptFinalizationService.php` (remains exactly as-is for the v2 fallback path it continues
to serve).
