# Gate r2 — parapharmacy remediation spec v2, treasury/GL lens

Date: 2026-09-05. Reviewer: Claude Opus (treasury-reviewer agent), adversarial design review.
Source snapshot: `f75aa5023` (working HEAD; source tree identical to `b9a5565aa` — only docs differ).
Subject: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md` v2 (uncommitted).
Scope: W1 treasury custody · W2 tender routing · W3 B2B atomicity · W7 expected cash · W0 guards.
Method: read-only. Every source line below was opened and read at this HEAD. No tests were run, no code edited, no commits.
This is a gate verdict on a design document. It is not implementation authorization and not launch approval.

---

## 0. Answers to the gate questions

| Question | Answer |
|---|---|
| F-A closed? | **Structurally addressed, not closed.** v2 does all five things r1 asked (names the device pick, requires one binding, evaluates the sealed precedent, shrinks the enum, names the H-3 reversal) — but it inherited r1's own factual error about vouchers and promoted it to a normative exclusion (**BLOCKER-1**), left the transport "recommended" with no decision row (**MAJOR-2**), and added a fourth classification surface (**MAJOR-6**). |
| F-C closed? | **Yes.** Spec line 163: "**No lock order is prescribed by this spec.**" It records today's order factually and requires a census + PG leg. Residual: the acceptance is scoped to invoice-vs-invoice only (**MAJOR-3**). |
| F-D closed? | **Yes, and honestly.** W7 reuses `ShiftExpectedCashService`; the repository comparison is separated and gated on RD4 in three places (line 289-291, §5 row, acceptance line 295). |
| F-E closed? | **Yes.** `RepositoryAdjustmentController` + `routes.php:98–100` are in W1's seams (line 121, 129) and acceptance (line 131); L1 narrows to recall/destroy/read and preserves the FormRequest counterevidence (line 216); RD2 is an open row. |
| W3 still prescribes a lock order? | **No.** Line 163 explicitly disclaims it. |
| Does W2's classification break cheques / vouchers / account legs / mixed tenders / change netting / cash rounding? | **Cheques: no** — `maturity_instrument` maps cleanly onto `has_maturity` + `instrument_kind`. **Change netting and cash rounding: no** — explicitly preserved (line 145) and the cutover is real. **Vouchers and loyalty: YES, badly** — see BLOCKER-1. |
| Is W7's repository comparison honestly gated on RD4? | **Yes.** Verified against `PostShiftCashVarianceAdjustment.php:50–56`; the spec forbids enabling that listener as a shortcut. |
| Does v2 silently decide RD3 or RD4? | **RD3: yes, partially** (MAJOR-1). **RD4: no.** |
| False claims about code at HEAD? | **One material** (BLOCKER-1) plus one citation slip (MINOR-1) and one non-discriminating acceptance cell (MAJOR-5). |
| Any package that moves the failure instead of removing it? | **Two.** W1 moves an unscoped-read failure into a blocked POS checkout (BLOCKER-2). W2 moves a mutable routing mistake into immutable sealed fiscal bytes with no repair path (MAJOR-2). |

---

## 1. Findings

### BLOCKER-1 — W2 asserts vouchers and customer-account legs bypass the tender resolver. They do not. CONFIRMED.

**Spec:** line 139 — "Vouchers and customer-account legs stay on their dedicated paths outside this resolver (receipt-bridge routing census must preserve those branches)".

**Source, read at HEAD:**
- `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:287–288` — *"Voucher legs ARE payment legs (`vouchers_redeemed` is derived from `payments[]`), so this sum is the full tendered amount."*
- `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/PaymentDTO.php:13` — *"`method_code` is UN/ECE 4461 mapped (e.g. \"CASH\", \"CARD\", \"VOUCHER\")."*
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:480` — `foreach ($view->payments as $payment) { $this->projectPaymentLineFromCanonical(...) }`. **No voucher / account / instrument filter exists in that loop.** Every leg reaches payment-method resolution (`:1220–1250`) and then `resolveRepositoryForTender`.
- `TreasuryReceiptBridge.php:969–972` — *"A voucher leg IS a payment leg but is NOT a cash tender: no drawer ever hands change back out of a voucher."* The bridge reasons about voucher legs precisely because they arrive on `payments[]`.
- `TreasuryReceiptBridge.php:105–109` says voucher **redemption** (the voucher-ledger write) is out of scope for the bridge — that is the redemption side effect, not the tender leg. The spec conflates the two.

**Failure scenario.** The 3-value enum is `cash | maturity_instrument | settlement` (line 139), and classification is to be validated against existing flags. Two seeded, `is_active => true` methods fall into `settlement` **by elimination**:
- `MEAL_VOUCHER` — `database/seeders/PaymentMethodSeeder.php:337–351` (`is_physical` true, `has_maturity` false, no `instrument_kind`, `requires_third_party` true, `has_deducted_fees` true, no `is_cash_tender`).
- `LOYALTY` — `PaymentMethodSeeder.php:193–208` (`is_physical` false, `has_maturity` false, `is_push` true, `is_restricted` true, no `is_cash_tender`). This is the Tunisia list (`TRAITE` at `:146`, D17/Konnect comment at `:177`), i.e. the target country.

Line 145 then requires: *"electronic settlement must reach an active mapped bank/virtual clearing repository with compatible GL/currency, never a drawer. Clearing means awaiting settlement, not cleared bank cash."* A loyalty-points redemption extinguishes a liability; it is not money awaiting settlement. Routing it to a virtual clearing repository creates a clearing balance that will never settle — the *"No fictitious bank balance"* the same paragraph forbids. A meal voucher is physical paper remitted for collection with deducted fees: its correct destination is a to-collect portfolio account (the shape `HandlesMaturityTenderLeg::portfolioAccountId()` already implements for cheques/effets at `Concerns/HandlesMaturityTenderLeg.php:39–48`), not a bank clearing account. Under RD3's recommended reversal (line 147, *"new settlement use stays unavailable without a binding"*), an unmapped `LOYALTY` or `MEAL_VOUCHER` leg becomes **unavailable on day one** for a seeded, active method — a hard regression manufactured by a fix.

**Minimum correction.**
1. Delete the false exclusion at line 139. Replace with the verified statement: every `payments[]` leg, including vouchers and loyalty, reaches `TenderRepositoryResolver` today via `TreasuryReceiptBridge.php:480`.
2. Either add a fourth class for **liability-extinguishing / non-settling tenders** (voucher, loyalty, store credit, on-account), whose destination is a liability/portfolio account and never a bank clearing repository — or state explicitly that such legs are excluded from the resolver *after* the census introduces the filter that does not exist today, and name that filter as W2 work.
3. Add `MEAL_VOUCHER` and `LOYALTY` rows to the W2 acceptance matrix (line 153) with their expected destination class.
4. Confirm from source, not assumption, whether an on-account POS sale leg exists on `payments[]` (`TreasuryAccountPaymentBridge` handles the separate `ACCOUNT_PAYMENT` fiscal event; a *charge-to-account* sale leg is a different question and is UNVERIFIED in this round).

**Blocks spec.** The requirement text is wrong about the code it constrains.

---

### BLOCKER-2 — W1 location-scopes the exact endpoint the POS device pulls its tender destinations from. CONFIRMED.

**Spec:** line 123 — *"For repository list/detail/balance/transactions/movements and manual adjustments, apply `LocationScopeResolver` and `LocationScopeBoundary`."* Seams at line 129, acceptance at line 131. No device consumer is named anywhere in W1, and §5 (line 315) carries no W1 → device/W2 dependency edge.

**Source, read at HEAD:**
- `apps/pos/src/lib/sync/syncService.ts:1256` — `apiGet<PaymentRepository[]>('/payment-repositories')`.
- `apps/pos/src/api/paymentApi.ts:8–9` — same endpoint; `apps/pos/src/lib/sync/__tests__/syncService.test.ts:699` pins it as canonical.
- `apps/pos/src/stores/paymentStore.ts:950–962` — the response is cached into device SQLite for offline use.
- `apps/pos/src/stores/paymentStore.ts:1185–1192` (card) — `paymentRepositories.find(r => (r.type === 'virtual' || r.type === 'bank_account') && r.is_active)`; **empty ⇒ `errors.noCardRepository` thrown, checkout aborted.**
- `apps/pos/src/stores/paymentStore.ts:1000–1007` and `:1556–1563` (cash) — `find(r => r.type === 'cash_register' && r.is_active)`; empty ⇒ `errors.noCashRegister`, checkout aborted.
- `apps/pos/src/stores/refundCheckoutStore.ts:1733, 1743` — same for refunds.
- The scoping pattern the spec points at, `CashPositionController.php:88–93`, adds `location_id IS NULL` rows **only** when `LocationScopeBoundary::isUnrestricted()` is true.

**Failure scenario.** Central/clearing repositories are locationless by design (the spec itself says so at line 123: *"Locationless central repositories are not exposed merely because location is null"*). A branch cashier is exactly the location-restricted user this package exists for. After W1, that cashier's `/payment-repositories` pull returns no `virtual`/`bank_account` row, the device caches the truncated set, and **card checkout throws at `paymentStore.ts:1188` — offline and online.** If the terminal's own drawer location is not in the user's set, cash and refund checkout fail the same way. Second-order: the SQLite cache is per-device, not per-user, so a wide-scope supervisor's cache silently persists for a narrow-scope cashier and back again.

**Minimum correction.**
1. W1 must name `/payment-repositories` as a **device tender-config sync surface**, not only an operator read.
2. Decide and state, in the spec, whether the device pull is scoped, exempted via terminal identity, or moved to a distinct terminal-scoped tender-config endpoint. A user-scoped read cannot be the source of a device's money destinations.
3. Add an acceptance row: *a location-restricted cashier on a second branch completes cash, card and refund checkout after W1, online and from cold SQLite cache.*
4. Add a W1 → W2 ordering edge in §5: W1's scoping and W2's binding retirement touch the same list from opposite sides.

**Blocks spec.** The acceptance set lives in the spec and is where the gap is.

---

### MAJOR-1 — RD3 is decided in W2's normative body while §2.3 declares it OPEN. CONFIRMED.

**Spec:** §2.3 line 53 — *"All ten rows remain **OPEN**"*; RD3 row at line 65. But W2 line 145 states as a requirement: *"electronic settlement must reach an active mapped bank/virtual clearing repository … **never a drawer**"* and *"new settlement use **stays unavailable without a binding**"*. That second clause **is** the RD3 outcome. Line 147 then correctly says *"Do not silently change that test or defaults before the owner rules."*

**Failure scenario.** An implementer briefed off §4/W2 builds the reversal; the owner later rules to retain the fallback; the lane is rewritten or, worse, ships and day-one electronic tender goes dark (`TenderRepositoryResolver.php:119–130` documents exactly why a hard refusal was judged unshippable: *"a freshly registered tenant owns CASH-01 and SAFE-01 and NOTHING else … so every card sale on day one would dead-letter"*).

**Minimum correction.** Move both clauses of line 145 into the RD3 proposal block, or prefix them "under RD3-reverse:". State the RD3-retain behaviour of the same sentence.

**Blocks spec** (one-paragraph fix).

---

### MAJOR-2 — W2's sealed-`repository_id` transport is "recommended" with no decision row, and has no repair path when the seal is unresolvable. CONFIRMED.

**Spec:** line 143 — *"Recommended W2 transport is resolved `repository_id` … on a **new compatible SALE_RECEIPT version**, validated against tenant, company, terminal/branch authority, class, currency and compatible GL purpose."* §5 line 316 lists W2's gate as a plan-level "fiscal version cutover review", not an owner decision. The parallel lot-transport question got a full owner decision row (D7, line 63).

**Source:**
- `TreasuryReceiptBridge.php:1297–1299` states the existing design intent in the opposite direction: *"Resolve the existing Payment **BEFORE** current routing configuration: **mappings are mutable operator policy, while a projected payment's repository is immutable history**."* Sealing the mapping at authoring time inverts that.
- `TreasuryAccountPaymentBridge.php:406–419` — the cited precedent **fails closed**: `payment_repository_required` if null, `payment_repository_not_found` if the row is missing/inactive/out of company. Applied to an offline-sealed SALE_RECEIPT, a repository deactivated or renamed between authoring and sync yields a permanently blocked treasury projection.
- Spec line 149 forbids the only obvious repair: *"Reclassifying erroneous historical payments requires compensating movements, not backfill changes to sealed tenders or existing effects."* For a leg that was **never projected**, there is no movement to compensate.
- CLAUDE rule 8: events are immutable forever.

**Failure scenario.** Branch deactivates `BANK-CLEARING-01` on Monday; a terminal that was offline since Sunday syncs Tuesday with sealed `repository_id` pointing at it. The treasury leg dead-letters with no authored way to rebind — the receipt's revenue is booked, its money is not, and the spec's own rules forbid every repair.

**Minimum correction.**
1. Add owner decision **D8** parallel to D7: *seal the destination in a new SALE_RECEIPT version* vs *keep the destination server-authoritative with an authored, versioned binding snapshot the bridge reads*. State the tradeoff: sealed bytes cannot be corrected; a server-side binding can drift from authoring.
2. Regardless of D8, add an explicit disposition for **"sealed binding unresolvable at projection"**: an operator rebind recorded as its own authored record (tenant/company scoped, permissioned, append-only), consumed by the bridge, and an explicit statement that a blocked treasury leg **never blocks POS-core revenue/stock projection**. W6 iteration 2 already carries that separation of concerns (line 250); W2 must carry the same.

**Blocks spec.**

---

### MAJOR-3 — W3's PostgreSQL concurrency acceptance is scoped to invoices, but the collision surface is every company GL writer. CONFIRMED.

**Spec:** line 173 — *"real PostgreSQL concurrent invoices (including advance-clearing paths) in one company cannot collide/fork the journal chain, deadlock through an inverted order, or leave a seal without GL"*.

**Source:**
- `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:591–609` — `createInvoiceGLEntries` opens its own `DB::transaction`, reads `JournalEntry::getLastChainHash()` and `getNextChainSequence()`.
- `apps/api/app/Modules/Accounting/Domain/JournalEntry.php:140–146` — `getNextChainSequence` is `max('chain_sequence') + 1`, **unlocked**.
- Grep of `AccountingService.php` for `advisory|lockForUpdate|takeTenantNumberingLock`: the **only** advisory lock in the file is `:1306`, on the correcting-entry path. `createInvoiceGLEntries` takes **none**.
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5722–5731` — the other allocator takes tenant-numbering then company-chain advisory locks, and its docblock at `:5710–5716` records a **reproduced** `SQLSTATE 40P01` from an AB-BA inversion.
- `database/migrations/tenant/2026_07_08_100400_…:36–38` — `CREATE UNIQUE INDEX uniq_je_company_chain_sequence ON journal_entries (company_id, chain_sequence) WHERE chain_sequence IS NOT NULL`. **Not restricted by source_type.**
- `app/Modules/Accounting/Listeners/InvoicePostedListener.php:18–33` — synchronous (not `ShouldQueue`), dispatched from `DocumentPostingService.php:725` via `DB::afterCommit`. Any throw leaves a sealed, numbered invoice with no GL and a 500.
- `app/Providers/EventServiceProvider.php:95–98` — `InvoicePosted` has exactly two consumers: `InvoicePostedListener`, `WriteDocumentVehicleContextForWorkOrderInvoice`. The spec's "preserve other registered consumers" is accurate.

**Failure scenario.** Because `createInvoiceGLEntries` takes no company-chain key, its unlocked `max+1` collides with **any** concurrent company GL write that does hold that key — a `TreasuryReceiptBridge` POS money leg, a `RepositoryAdjustmentService` post, a supplier-invoice post — not merely a second invoice. In a live parapharmacy that is the *high-frequency* case: POS runs all day, B2B invoices are occasional. An acceptance suite of concurrent invoices will pass while the real collision remains. Separately, once GL moves inside the document transaction (line 161), the document post holds the company chain key for the whole seal, serializing POS money projections behind B2B posting — a new contention mode the acceptance does not measure.

**Minimum correction.** Extend the W3 acceptance (line 173) to require concurrent PostgreSQL runs of a B2B invoice post against (a) a `TreasuryReceiptBridge` GL write, (b) a `RepositoryAdjustmentService` post, (c) a procurement/supplier-invoice post — all in one company. Require the writer/lock census (line 163) to record lock **hold time** inside the document transaction, not only acquisition order.

**Blocks plan.**

---

### MAJOR-4 — `payment_repositories.code` is validated tenant-wide against a company-scoped DB unique; second-company provisioning through the API is impossible. CONFIRMED.

**Source:**
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:80` — `Rule::unique('payment_repositories', 'code')->where('tenant_id', $tenantId)` on create.
- `PaymentRepositoryController.php:170–172` — same rule on update.
- `database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:31–35` — the DB constraint was deliberately migrated from `(tenant_id, code)` to **`(company_id, code)`**.

**Failure scenario.** Company B of the same tenant cannot be given `CASH-01` or `SAFE-01` through the API: a 422 the DB would happily accept. W1's acceptance (line 131) and W2's (line 153) both require "second-company provisioning"; §1's second-of-everything clause (line 32) requires *"second-company creation through the real provisioning path"*. D1 (line 57) is open on whether branches are separate legal companies — if the owner rules "separate entities", this bites immediately. Convention 09 class: a company-blind uniqueness decision on a catalogue entity.

**Minimum correction.** Name this in W1 as an in-scope correction (align the validation rule to `company_id`, plus a second-company + re-run test), or record it explicitly as an out-of-scope known defect so the acceptance row is not written as if it passes.

**Blocks plan.**

---

### MAJOR-5 — A W2 acceptance cell already passes at HEAD; the discriminating cells are missing. CONFIRMED.

**Spec:** line 153 — *"electronic→settlement allowed, **electronic→drawer denied even when a bank exists**"*.

**Source:** `TenderRepositoryResolver.php:198–207` — for a non-cash tender the fallback already sorts *"Every non-drawer ahead of every drawer. A drawer is reachable only when the company has no settlement repository at all."* That acceptance cell is **green today**.

The cells that actually fail at HEAD, and which the matrix omits:
1. **Non-cash method explicitly MAPPED to a drawer.** `TenderRepositoryResolver.php:157–171`: the `DRAWER_TYPES` restriction on the mapped branch is applied **only** `if ($cashTender)` (`:163–165`). A CARD method mapped to `CASH-01` is honoured and returns the drawer. This is the B3 benchmark row's own citation (`:157`) and it is not in the matrix.
2. **The RD3 no-settlement-repository day-one case** — currently the drawer, per `:198–207`.

**Minimum correction.** Rewrite the W2 matrix so each cell fails at HEAD. Also apply W0/G7's `_pins_limitation_` marker to `tests/Feature/Tenant/CleanRegistrationDownstreamAssumptionsTest.php:133–151` **now** (it is a pinned limitation regardless of how RD3 rules), not conditionally after the ruling. Note W0/G3 (line 109) already says *"do not turn the old fallback into an acceptance requirement"* — the matrix has not caught up with its own guard.

**Blocks plan.**

---

### MAJOR-6 — W2 adds a fourth tender-classification surface, unregistered in the glossary, kept in sync with three existing ones. CONFIRMED.

**Spec:** line 139 — *"proposed `cash`, `maturity_instrument`, `settlement`. **Validate it against existing flags and forbid conflicting activation.**"*

**Source — the concept already has three writers:**
- `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php:26–29` and casts `:95–99` — `is_cash_tender`, `has_maturity`, `instrument_kind` (`InstrumentKind::{Cheque,Effet,Other}`).
- `app/Modules/Treasury/Application/Projections/Concerns/HandlesMaturityTenderLeg.php:33–37` — `handles()` derives "maturity instrument" as `has_maturity && instrument_kind ∈ {Cheque, Effet}`. That **is** the proposed `maturity_instrument` cell, already computed.
- `TreasuryReceiptBridge.php:969` — *"Cash-ness comes from `payment_methods.is_cash_tender` (Task 1) and nothing else."* That **is** the proposed `cash` cell.

"Validate against existing flags and forbid conflicting activation" is the definition of two writers kept in sync — the drift class this same spec condemns elsewhere (§3.2 line 93: *"Extend these rather than create a second source"*). Convention 11 requires the noun in `docs/glossary.md`; v2 registered **Lot evidence** and **Session reconciliation** (glossary diff verified) but **not** tender classification or tender binding.

**Minimum correction.** State that the classification is a **derived value object** over the existing flags (no new stored column) — in which case W2's real work is the `settlement`/liability distinction from BLOCKER-1, not a new enum. If it must be stored, name which existing flag becomes non-authoritative, the backfill, and the single writer. Register the noun(s) in `docs/glossary.md` alongside the two already added.

**Blocks spec.**

---

### MINOR-1 — Citation slip on the "unrestricted membership" claim. CONFIRMED.
Spec line 123 cites `LocationScopeResolver.php:31,53` for *"explicit unrestricted membership"*. `:31` is the `?string $bypassPermission` parameter and `:53–54` is the bypass-permission branch. Null-membership-means-all-locations is at `:57–60` (`$membershipAllowed === null` → `allCompanyLocationIds`). Cite `:57–60` for that sentence; keep `:31,53` for the bypass. Blocks nothing; fix in place.

### MINOR-2 — `transactions()` omits the company predicate on the payments query. CONFIRMED.
`PaymentRepositoryController.php:350–353` scopes the repository by tenant+company, then `:356–358` selects payments by `tenant_id` + `repository_id` only. Harmless today (repository ids are unique), but W1 is editing this method — keep the `company_id` predicate when it does. Blocks plan (trivial).

### MINOR-3 — W3's synchronous posting converts a data defect into an availability outage. CONFIRMED-by-construction.
Today a missing `SystemAccountPurpose` leaves a sealed invoice and a 500 (`InvoicePostedListener` runs after commit). After line 161, the same misconfiguration makes the invoice **unpostable**. That is the correct trade for B5, but the spec should state it and require an actionable, translated refusal naming the missing purpose — the pattern `RepositoryAdjustmentController.php:71–79` already uses. Blocks plan.

### MINOR-4 — `is_cash_tender` is mutable state the netting result depends on; the W2 cutover does not cover the deploy-time flip. CONFIRMED.
`TreasuryReceiptBridge.php:961–963`: the netting result is *"a deterministic pure function of the sealed payload + the `payment_methods.is_cash_tender` flag (**mutable operator state — never flip it while a terminal has unsettled projections**; see the deploy checklist)"*, and `:964–967` explains a non-deterministic netting becomes a permanently-failing queue job via `IdempotencyConflictException`. W2's classification work is exactly such a flip. Line 149 covers legacy *unprojected* receipts but not the in-flight flip at deploy. Add "no unsettled projections on any terminal" as a W2 cutover precondition, citing that deploy checklist. Blocks plan.

### MINOR-5 — W7's dependency on W2 has a named failure mode the spec does not cite. CONFIRMED.
`ShiftExpectedCashService.php:250` declares `@throws UnknownTenderClassificationException when a tendered code has no payment method row`. W2's reclassification therefore has a direct blast radius into W7's derivation and into close. Spec line 283 links them in prose; §5 line 322 lists the prerequisite as "W2 classification" without naming this exception path or requiring a W7 acceptance row for a reclassified historical tender. Blocks plan (one acceptance row).

---

## 2. Rejected false positives — and what to preserve

I tried to break these and could not. They are correct and should survive the fix round unchanged.

- **W3 no longer prescribes a lock order.** Line 163 is explicit, and its description of today's sequence is accurate: document chain `lockForUpdate` at `DocumentPostingService.php:673–678`, in-transaction advance clearing at `:239`, GL keys tenant-then-company at `GeneralLedgerService.php:5722–5731`. F-C's central ask is closed.
- **W7's RD4 gate is honest, not decorative.** Verified in three independent places (line 289-291, §5 line 323, acceptance line 295), and `PostShiftCashVarianceAdjustment.php:50–56` is quoted correctly — it does ship disabled *because* Treasury books neither opening float nor mid-shift drawer operations. The spec forbids enabling it as a shortcut. Keep verbatim.
- **W7's reuse of `ShiftExpectedCashService` is correct and its caveat is more accurate than r1's.** `:265` reads `opening_cash`, `:270–272` cash takings / z-session movements / account collections, `:285–289` the sum, `:258` resolves scale from the passed currency (rule 19 clean). The class docblock at `:44–52` does warn the "one derivation, one place" claim was overstated; v2 reproduces that caveat honestly. Keep.
- **F-E is closed.** `Treasury/Presentation/routes.php:98–100` is the adjustment route (`can:treasury.adjust`), `RepositoryAdjustmentController.php:46–68` builds a company/user intent with **no** location check, and `RolesAndPermissionsSeeder.php:591` grants manager `treasury.adjust` + `treasury.transfer` + `repositories.view` (and `:632` `batches.recall`, `:631` `batches.delete`). The spec's refusal to accept the adapter as proof (line 121) is exactly right.
- **`RepositoryTransferService` source custody is unscoped, as claimed.** `:43–44` resolve both endpoints, `:118–125` scopes only tenant+company+active+transferable type. No location authorization. CONFIRMED.
- **`treasury.manage_all_locations` is not a duplicate surface.** Null membership remains the primary all-locations grant (`LocationScopeResolver.php:57–60`) and the resolver already accepts a per-endpoint bypass (`:31, :53–54`). Keep the proposal.
- **Per-leg idempotency and frozen-replay policy are correctly preserved.** `TreasuryReceiptBridge.php:1297–1310` resolves the existing Payment before current routing; `TreasuryMovementService.php:90–97` records-with-alert under `allowWhileFrozen` rather than throwing. Keep both sentences.
- **Maturity instruments are NOT broken by the classification.** `HandlesMaturityTenderLeg::handles()` (`:33–37`) and `portfolioAccountId()` (`:39–48`, `ChecksToCollect` / `EffectsReceivable`) already implement the `maturity_instrument` cell, and `TreasuryReceiptBridge.php:1275–1295` routes maturity legs separately, including the refund fallback with a durable alert. Cheques and effets are safe. Only vouchers/loyalty are not (BLOCKER-1).
- **Change netting and cash rounding are safe.** `TreasuryReceiptBridge.php:996–1000` (v1/v2 byte-identical replay), `:1270–1273` (a netted-to-zero leg writes nothing, gated on `CashRoundingCutover`), `:974–977` (maturity legs are never netted). W2 line 145's "Preserve mixed-tender change netting and rounding" is correctly scoped.
- **W4's precision about the seeding hole is correct.** `OutboxIngestor.php:1025–1027` inserts projection rows inside the event transaction and `:1042–1046` dispatches after commit; `RetryFiscalProjectionsCommand.php:296–306` selects only dead-lettered rows or pending rows with `attempts >= EXHAUSTED_ATTEMPTS`. The lost-enqueue-at-attempts=0 and orphaned-`running` gaps are real. Keep W4 as written.
- **`InvoicePosted` consumer census is accurate.** `EventServiceProvider.php:95–98`: two listeners, the second non-financial. W3's "preserve the event and other consumers" is right.
- **Glossary discipline improved.** Both new nouns are registered with proposed-vs-shipped storage explicitly distinguished (`docs/glossary.md`, rows "Lot evidence" and "Session reconciliation"). That is the convention-11 pattern done properly — and it is exactly what MAJOR-6 asks be extended to the tender nouns.

**Not verified in this round (state honestly, do not assume):** whether a charge-to-customer-account POS sale produces a `payments[]` leg; the live PostgreSQL behaviour of any concurrency scenario; the W-LOT/W6 device and counting claims (outside this lens); whether any GL writer acquires a Document row lock after GL keys (the spec correctly makes this a census obligation rather than an assertion).

---

## 3. What to fix before the next round

Six items, in order: (1) delete the voucher/account-leg exclusion at line 139 and give liability-extinguishing tenders a destination class; (2) make `/payment-repositories` a declared device-sync surface in W1 with a cashier-checkout acceptance row; (3) move line 145's "never a drawer" / "unavailable without a binding" under RD3; (4) add decision D8 for the W2 transport plus an unresolvable-sealed-binding disposition; (5) widen W3's PG concurrency acceptance beyond invoice-vs-invoice; (6) make the classification derived (or name its single writer) and register the noun in the glossary. MAJOR-4/5 and the five MINORs are one-line spec edits.

VERDICT: CHANGES-REQUIRED
