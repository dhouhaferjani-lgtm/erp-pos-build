# Treasury Phase ② — Payment-Instrument Portfolio + Échéancier — Design Spec

> **Date:** 2026-07-10 · **Rev 2 (2026-07-11):** reconciled against the dual adversarial review — [reviews/2026-07-11-treasury-phase2-adversarial-review.md](reviews/2026-07-11-treasury-phase2-adversarial-review.md) (treasury-reviewer T1–T22 + fiscal-pos-reviewer F1–F10; 5 BLOCKER / 12 HIGH, all accepted; resolutions in §20) · **Status:** awaiting owner review
> **Mandate:** owner 2026-07-10 (`docs/handoff/HANDOFF-treasury-phase2-spec-kickoff-2026-07-10.md`) — full spec/plan cycle based on industry standards; implementation dispatch gated by owner.
> **Inputs:** [2026-07-07 industry-gap audit](../audits/2026-07-07-treasury-industry-gap-audit/README.md) (G4–G10 + I1–I10) · Phase-① spine spec [2026-07-07-treasury-spine-design.md](2026-07-07-treasury-spine-design.md) (shipped, origin/dev `8643c2573`) · code survey (file:line verified 2026-07-10) · industry research pass (PCG/PCE postings, Odoo/Dolibarr/Sage/Tally state machines, NF203/FEC, bordereau conventions — citations in §3)
> **Program context:** Phase ② of: ① spine ✅ → **② instrument portfolio/échéancier** → ③ cash-visibility read layer → ④ expense depth → ⑤ bank statement import.

---

## 1. Goal

Make chèques and traites (effets) first-class treasury citizens: every instrument has an accounting-visible lifecycle (GL at each stage, per PCG/PCE), a custody trail, a maturity answer ("what clears when"), and one portfolio regardless of capture point (web or POS). Close gaps G4–G10 (audit §6, P1 block) plus the bounce-depth part of I7.

**The Phase-② invariant, extending the spine invariant:**

> An instrument's status is always consistent with the GL account that holds its value, and cash (repository balance) changes only when the bank actually credits or debits — at clearing and at dishonor-after-clearing — always through the Phase-① port with a journal entry.

## 2. Scope

**In:**
- Instrument GL integration for the **inbound** (receivable) lifecycle: receipt → remise → clearing / impayé, per-country account maps (G5, I1).
- Deferred-tender cash-recognition fix: payments by maturity instruments no longer move repository balance at receipt (they currently do — an accounting error mirroring the old unpaid-expense bug).
- POS-collected checks/traites enter the portfolio via the projection bridge (G6, I3).
- Backend-enforced instrument creation on `has_maturity` payments (G7, I8).
- Remise en banque: batch bordereau document + GL (G9, I6).
- Instrument custody/event history, append-only (G10, I5).
- Impayé depth: 413/411/416 routing, allocation reopening, optional fees, re-presentation (I7).
- Échéancier: maturity register filters, `/treasury/maturing-instruments`, aging buckets, instrument maturities feeding `UpcomingPaymentsService` (G8), Treasury Overview upcoming-maturities panel, scheduled pre-maturity alert (G4, I2).
- `treasury:reconcile` portfolio↔GL coherence check (alert-only).
- FE: register filters, detail-page lifecycle actions, remittance list/detail/create + printable bordereau view, Overview panel, single-call PaymentForm.
- **Outbound** (effets à payer) instruments: registration + échéancier visibility ONLY (no GL, no lifecycle actions) — see §14 decision D-4.

**Out (explicit):**
- Escompte GL (5114/6516-6616 flow) — schema models `remittance_type=discount`, UI/GL deferred (audit defer list; D-5).
- Endossement; LCR/CFONB file generation; traite/chèque printing.
- Expense paid-by-instrument (G20 — Phase ④); bank statement import & auto-match (Phase ⑤); cash-visibility dashboards beyond the one Overview panel (Phase ③); inter-repository **cash** transfers (G13, Phase ③).
- POS UI capture of check/traite details (drawer bank, maturity date) — canonical SALE_RECEIPT payload change, own fiscal-surface track; Phase ② takes what the payload already carries and completes details in back office (§9).
- Multi-currency instruments (hard guard: instrument currency must equal company/repository currency, as the spine does).
- Card acquirer settlement, `has_deducted_fees` wiring (Phase ⑤ per spine spec).

## 3. Industry model (research synthesis — the standard we implement)

Full research report retained in session; load-bearing facts:

**Account maps (verified against PCG doctrine and the Tunisian PCE course procomptable.com):**

| Purpose (new `SystemAccountPurpose`-style key) | France (PCG) | Tunisia (PCE) |
|---|---|---|
| `checks_to_collect` — chèques à encaisser (portfolio AND in-collection) | **5112** | **5312** |
| `effects_receivable` — clients, effets à recevoir | **413** | **413** |
| `effects_in_collection` — effets à l'encaissement | **5113** | **5313** |
| `effects_discounted` — effets à l'escompte (reserved, D-5) | 5114 | 5314 |
| `instrument_bank_fees` — frais/services bancaires | **627** | **6275** |
| `vat_recoverable_on_fees` | **44566** | **43666** |
| `discount_interest` — agios (reserved, D-5) | 6616 | 6516 |
| `doubtful_receivables` — clients douteux | 416 | 416 |
| Bank | 512 (repo `gl_account_id`) | 532 (repo `gl_account_id`) |
| Effets à payer (outbound; registration-only this phase) | 403/405 | 403/405 |

**The check/effet asymmetry (drives the GL map in §7):** a check debits its portfolio account **at receipt** (`Dr 5112 / Cr 411`) and stays there through remise until the bank credits (`Dr 512 / Cr 5112`) — PCG has no standard separate "remitted" account for checks; remise is a status, not a posting. An effet sits in **413** from acceptance, moves to **5113** at remise, and clears to 512. Impayé routing is a documented decision rule: back to **413** if the effet will be renewed/re-presented, **411** if simply recoverable, **416** if doubtful.

**Reference state machines:** Odoo models portfolio state as "which journal/account currently holds the check" (account-as-state) + an operations log; OCA `account_check_deposit` batches checks into a deposit posting one JE for the slip total; Dolibarr's bordereau has 4 states (draft → validated → deposited → cashed) with per-line rejection; Sage 100 MdP runs separate configured circuits for remise à l'encaissement vs à l'escompte and re-associates impayés to their échéances. Tally/ERPNext PDC practice adds maturity registers + pre-maturity reports.

**TN market reality (2025–26):** law 2024-41 (in force Feb 2025) capped and de-weaponized checks; clearing volumes show traites +155-160% while checks collapse. The **traite portfolio + échéancier is now the core TN retail credit workflow**. ("LCN" is the Moroccan term — not used as an internal name.)

**Échéancier conventions:** unified in/out due-date schedule; buckets non-échu / 0-30 / 31-60 / 61-90 / +90; pre-maturity alert window is convention not standard (7–10 days typical) → configurable, default 7.

**NF203/FEC:** every lifecycle transition must be append-only and produce (or reverse via contre-passation) journal entries — never edit a posted entry; FEC journal codes are free but must be internally consistent and declared; practice is a dedicated journal for portfolio operations.

## 4. Approaches considered

| | Approach | Verdict |
|---|---|---|
| **A** | **Account-as-state GL-transit model (chosen).** Instrument value lives in country-mapped GL transit accounts (5112/413/5113…); `repository_movements` written ONLY at real cash effect (clearing in; dishonor-after-clearing out) via the Phase-① port; custody is a separate append-only event log; all transitions post GL synchronously in-transaction via one lifecycle service. | **Chosen** — it is the PCG/PCE-canonical model, converges with Odoo/Sage, keeps "Total Cash" true (uncleared paper is not cash), and satisfies the spine invariant naturally (the only movements are real bank credits/debits, each with a JE). |
| B | Portfolio-as-repository: a virtual repository holds instrument value; every stage is a port `transfer()`. | Rejected — conflates paper with cash (Total Cash would count uncleared checks), maps to no PCG account (5112/413 are not caisse accounts), makes `treasury:reconcile` assert nonsense, and floods the movements ledger with non-cash events. |
| C | Minimal overlay: keep today's movement-at-receipt, bolt GL listeners onto the four transition events. | Rejected — books stay wrong (cash recognized before clearing — the same class of error as the unpaid-expense bug the spine fixed), clearing would double-count or need a compensating movement, and async listeners violate the spine's atomicity lesson (GL must post in the same transaction as the state change; `postEntryNow`, never `afterCommit`). |

**Consequence of A (flagged loudly):** Phase ② **changes Phase-① behavior for `has_maturity` tenders** — B2B `PaymentController` and the POS bridges stop recording a movement for deferred tenders and swap the JE debit line from the repository cash account to the portfolio account. §8/§9 specify the cutover and idempotency handling. `transfer()` gains **no** consumer in Phase ② (clearing is a single-leg `record()`; custody moves carry no money) — the handoff's "likely first consumer" guess does not hold; noted so nobody force-fits it.

## 5. Data model

### 5.1 `payment_instruments` — new columns (all nullable/defaulted; migration re-runnable with `hasColumn` guards)

| Column | Type | Notes |
|---|---|---|
| `direction` | enum `inbound`/`outbound`, default `inbound` (PHP enum, rule 9) | outbound = registration-only this phase (D-4) |
| `kind` | enum `cheque`/`effet`/`other` (PHP enum) | drives the GL map (§7). Snapshot at creation from the payment method (§5.4); required |
| `origin` | enum `web`/`pos`, default `web` | POS-bridge-created instruments (§9) |
| `bank_id` | **plain uuid, indexed, nullable — NO FK constraint** (the `banks` table does not exist on dev; `feat/bank-reference-verification` is unmerged — T15). FK added by a follow-up migration once that track lands | **bank-directory interlock**: reserved here per that track's carve-out; FE uses `BankPicker` once it merges, free-text `bank_name/bank_branch` stays the fallback (§15) |
| `idempotency_key` | string, **partial unique index** `WHERE idempotency_key IS NOT NULL` | POS-bridge creation idempotency: `fiscal_event:{event_id}:instrument:{canonical_index}` (§9). Web-created instruments leave it null |
| `remittance_id` | uuid FK `instrument_remittances`, nullable | current slip (history lives in `instrument_remittance_lines` + events) |
| `needs_details` | bool default false | POS-created instruments missing reference/maturity/bank; cleared by back-office completion (§9) |
| `dishonor_routing` | enum `re_present`/`receivable`/`doubtful`, nullable | recorded at bounce (§12) |

Existing columns reused as-is: `amount decimal(15,3)`, `currency`, `maturity_date` (indexed `(tenant_id, maturity_date)`), `received_date`, `status`, `repository_id` (= custody), `deposited_to_id`, `payment_id` (finally written — §8), bank free-text trio, timestamps per status. `payment_id` gets a proper `belongsTo` relation (survey: fillable today but relationless and never written). Currency **defaults to the company currency, never a hardcoded `'TND'` literal** (the store endpoint's `?? 'TND'` at `PaymentInstrumentController.php:121` is replaced — T17), and the §2 hard guard (instrument currency == company currency) applies at creation. All instrument endpoints gain the **`company_id` predicate** during the controller rework — today they filter `tenant_id` only, a cross-company leak (T16).

### 5.2 `instrument_events` — append-only custody/lifecycle log (G10; the NF203 audit trail)

`id` uuid PK · `tenant_id` · `company_id` · `instrument_id` FK indexed · `event_type` enum (`created`, `details_updated`, `custody_transferred`, `remitted`, `cleared`, `bounced`, `re_presented`, `cancelled`) · `from_status`/`to_status` nullable · `from_repository_id`/`to_repository_id` nullable · `remittance_id` nullable · `journal_entry_id` nullable · `movement_id` nullable (FK `repository_movements`) · `payload` jsonb + PHP DTO (rule 3; e.g. the details diff, dishonor routing, fee amounts) · `occurred_at` · `created_by` · `created_at`.

Immutability: DB trigger raising on UPDATE/DELETE/TRUNCATE (copy the `repository_movements` template `2026_07_08_100200_...`, **with `DROP ... IF EXISTS` re-runnable form** per the audit-fix lesson). Written in the same transaction as the transition it records. This supersedes the module doc's never-built `InstrumentMovement`.

### 5.3 `instrument_remittances` + `instrument_remittance_lines` (G9)

**`instrument_remittances`:** `id` · `tenant_id` · `company_id` · `number` (per-company gapless `REM-{YYYY}-{seq}`, allocated under the company advisory lock — same pattern as the spine's JE-number fix, NOT racy `max()+1`) · `remittance_type` enum `collection`/`discount` (discount reserved, D-5) · `instrument_kind` enum `cheque`/`effet` (**one kind per slip** — banks use distinct bordereaux; also keeps the slip's GL homogeneous) · `bank_repository_id` FK (must be `type=bank_account`, reusing the deposit guard) · `status` enum `draft`/`remitted`/`closed` · `remitted_at` nullable · `journal_entry_id` nullable (the remise JE — effets only, §7) · `created_by` · `notes` · timestamps.

**`instrument_remittance_lines`:** `id` · `remittance_id` FK · `instrument_id` FK · `amount` (snapshot) · `line_status` enum `pending`/`cleared`/`bounced` · `cleared_at`/`bounced_at` nullable · unique `(remittance_id, instrument_id)`. Lines are how a bounced-then-re-presented instrument appears on two slips with both bordereaux still printable. Totals are computed, not stored.

Lifecycle: `draft` (compose: add/remove Received instruments of the slip's kind) → **`remitted`** (posts the effets JE, stamps instruments `Deposited`, `deposited_to_id`, events) → **`closed`** (every line cleared or bounced; derived, set by the last line settlement). No slip deletion after `remitted`; a draft slip can be deleted.

**Not a `DocumentType`:** the unified `documents` table is for commercial partner documents (12 cases today, all partner-priced-lines shaped); a bordereau has no partner and its lines are instruments. Dedicated tables + own numbering (survey: `DocumentNumberingService` is document-coupled; we mirror its per-company sequence discipline instead).

### 5.4 `payment_methods.instrument_kind`

New nullable enum column `instrument_kind` (`cheque`/`effet`/`other`) on `payment_methods`; seeded by `PaymentMethodSeeder` (CHECK/CHEQUE→`cheque`, TRAITE/PDC/BILL_OF_EXCHANGE→`effet`); required (422) when `has_maturity=true` at method create/update. Instrument snapshots `kind` from the method at creation. (The six switches stay untouched; `has_maturity` remains the single behavioral trigger, per the audit's "wire the dead switches, don't redesign".)

### 5.5 Chart of accounts + account resolution

- Seed the §3 accounts in `TunisiaChartOfAccountsSeeder` + `FranceChartOfAccountsSeeder` (+ Generic) with the exact codes/labels from the research (TN: 5312/5313/5314 under 531, 6275, 43666; FR: 5112/5113/5114 under 511, 627, 44566). Idempotent, keyed by `code` like the 6580/7580 precedent.
- New `InstrumentAccountResolver` (Treasury Application service, constructor-injected): purpose-key → account id for the company's chart, mirroring how the adjustment flow resolves tolerance accounts via `SystemAccountPurpose`. Missing account on an **interactive** path ⇒ 422 **before** any transaction starts (the K2 lesson: pre-transaction validation, translated message). Missing account on the **projection/worker** path ⇒ **throw** (fail-closed → Horizon retry → dead-letter → operator seeds the chart), matching the bridge's existing `gl_account_id` posture at `TreasuryReceiptBridge.php:437-444` — never swallow and book nothing (F4).
- **The portfolio purpose accounts are reserved for instrument flows**: only Phase-② code paths may post to `checks_to_collect` / `effects_receivable` / `effects_in_collection` (architecture test asserts it) — this is what makes reconcile check #4's account-balance comparison sound (§11, T6).

### 5.6 Spine touch-points

- `MovementSourceType`: ensure case `Instrument = 'instrument'` exists (reserved by the spine spec §4; plan verifies the enum and adds if absent).
- `JournalCode`: new case `Effets = 'EF'`; `fromSourceType()` maps `instrument`, `instrument_remittance` → `EF`. All other instrument-adjacent JEs keep their existing codes (the receipt-side payment JE stays `source_type=payment` → its current code). FEC descriptive doc note recorded in §17. (Survey: unmapped source types silently fall through to `OD` — this is the guard against that.)
- `journal_entries` partial unique index: **none** for instrument source types — one instrument legitimately produces several JEs over its life (remise, clear, bounce, re-remise). Idempotency is carried by the status machine (interactive transitions) and by movement/instrument idempotency keys (projections). A comment in the migration mirrors the procurement index's docblock reasoning.

## 6. State machine

Existing 9-value `InstrumentStatus` enum kept verbatim (no data migration). Operative machine:

```
 created ─► Received ──► Deposited (on a remitted slip) ──► Cleared
 (portfolio;   │  ▲              │                             │
  custody=repo)│  │ re_present   ├──► Bounced ◄── dishonor ────┘
               │  │ (new slip)   │    (routing §12: re_present /
               │  └──────────────┘     receivable / doubtful)
               ├── custody transfer (Received/Bounced — no money, event-logged)
               └── cancel (guards §12.4) ─► Cancelled
```

Guard changes to `InstrumentStatus` (`apps/api/.../Enums/InstrumentStatus.php`): `canDeposit()` gains `Bounced` (re-presentation); `canBounce()` gains `Cleared` (dishonor-after-clearing — a bank claw-back days after credit is real and §7 posts it; `Cleared` therefore leaves `isTerminal()`); everything else unchanged. **Dormant cases** (`InTransit`, `Clearing`, `Expired`, `Collected`) stay declared (events-immutable discipline: enum values may exist in seeded/legacy rows) but no transition produces them; documented as reserved in the enum docblock. `canClear()`/`canBounce()` continue to accept `Clearing` harmlessly.

**Every transition runs through one new `InstrumentLifecycleService`** (Treasury Application layer; constructor-injected `GeneralLedgerService`, `TreasuryMovementServiceInterface`, `InstrumentAccountResolver`, `CurrencyScaleResolverInterface`). The service is **context-free**: every entrypoint takes explicit tenant/company/currency parameters — it is invoked both from HTTP controllers (which resolve them from `CompanyContext`) and from the projection bridge on a Horizon worker where **no CompanyContext is bound** (rule 20, F6); no code path inside it may read ambient context or call a no-arg `getScale()`. The controller becomes thin: validate → delegate.

**THE GLOBAL LOCK ORDER (T1/T13 — binding on every Phase-② flow and every flow it touches):**

> instrument row(s), id-sorted → document row(s), id-sorted → GL company advisory lock (`postEntryNow`) → repository row (the port).

This matches the existing B2B payment flow, which locks document rows (`PaymentController.php:670-674`) before posting GL (`:781/:794`) — the Rev-1 bounce ordering (JE first, document locks third) was a real AB/BA deadlock against concurrent payments and is dead. Multi-instrument operations (slip remit) lock their instruments in id-sorted order; `PaymentController` locks a linked instrument BEFORE `postEntryNow` (T13).

Each transition, in ONE DB transaction, in that order:

1. `lockForUpdate` the instrument row(s) (id-sorted); re-check the status guard under lock (two concurrent `clear` calls: second one 422s, never double-posts).
2. `lockForUpdate` any document rows the transition will touch (id-sorted — bounce with allocation reopening, §12).
3. Build + `postEntryNow()` the JE (when the transition posts GL — §7). Explicit currency always.
4. `record()` the movement when the transition moves cash (§8), `journal_entry_id` linked, idempotency key `instrument:{instrument_id}:{leg}` (legs in §7).
5. Mutate instrument (+ remittance line, document status/allocations when applicable).
6. Append the `instrument_events` row.
7. Dispatch the existing domain event (`InstrumentDeposited`/`Cleared`/`Bounced`/`Transferred` + new `InstrumentReceived`) — now **registered in `DomainEventSubscriber` → `audit_events`** (survey: zero listeners today; the compliance trail starts existing).

The four legacy transition endpoints stay URL-compatible; `deposit` becomes a thin wrapper that creates+remits a single-instrument remittance (so the old flow keeps working while the batch flow is canonical). `clear`/`bounce` gain optional fields (§7/§12). `transfer` (custody) stays movement-free, now writing an event row.

## 7. GL postings per transition (the heart of G5)

All amounts bcmath at `getScale($currency)` (rule 19); JE `source_type`/`source_id` = `instrument`/`instrument.id` except the remise JE (`instrument_remittance`/`remittance.id`); `journal_code = EF` for both.

**Inbound cheque** (`kind=cheque`; portfolio account P = `checks_to_collect` 5112/5312):

| Transition | JE | Movement (port) |
|---|---|---|
| Receipt (payment-linked, §8) | part of the payment JE: **Dr P / Cr customer receivable** (debit-line swap vs today's Dr repo-cash) | **none** (was: movement-in — removed) |
| Remise (slip `remitted`) | **none** — PCG: checks stay in P until credited; remise is status-only | none |
| Clear (per line; optional `fee_amount`, `fee_vat_amount`, `value_date`) | Dr bank `gl_account_id` (net) + Dr 627/6275 (fee) + Dr 44566/43666 (VAT) / **Cr P (nominal)** | **in**, net amount, on the slip's bank repository; leg `instrument:{id}:clear:{line_id}` |
| Bounce before clear (per line) | **Dr routing account (§12) / Cr P** (nominal); optional fee: + Dr 627 + Dr VAT / Cr bank | none for nominal; **out = `bcadd(fee, fee_vat)`** — the movement must equal the bank-account JE lines exactly or reconcile #2 freezes the repo nightly (T9); leg `instrument:{id}:bounce_fee:{line_id}` |
| Bounce after clear (bank claws back) | Dr routing account (nominal) + Dr 627 + Dr VAT / **Cr bank** (nominal+fees) | **out**, nominal+fees, leg `instrument:{id}:dishonor:{line_id}` |

**Inbound effet** (`kind=effet`; R = `effects_receivable` 413, C = `effects_in_collection` 5113/5313):

| Transition | JE | Movement |
|---|---|---|
| Receipt/acceptance | payment JE: **Dr R / Cr customer receivable** | none |
| Remise (slip `remitted`) | ONE JE per slip: **Dr C / Cr R** for the slip total (per-line provenance via the lines table) | none |
| Clear | Dr bank (net) + Dr fees + Dr VAT / **Cr C (nominal)** | **in**, net |
| Bounce before clear | **Dr routing / Cr C**; fee handling as above | fee-only out |
| Bounce after clear | Dr routing + fees / **Cr bank** | **out**, nominal+fees |

Re-presentation (Bounced → new slip): requires `dishonor_routing=re_present` (value sat in R/P — for a cheque the bounce JE routed back to P? **No**: cheque bounce routes to 411/413/416 like effets; re-presenting a cheque re-debits P — JE **Dr P / Cr routing-account** at the moment the re-presentation slip is remitted; effet re-remise posts the normal Dr C / Cr R since routing kept it in 413). The lifecycle service encodes exactly this; tests pin each JE shape.

Rounding: one boundary rounding per JE line (`bcformatStrict`); net = `bcsub(nominal, fee_gross)` at scale; **every movement amount must equal the sum of that JE's lines on the repository's own `gl_account_id` exactly** — reconcile check #2 asserts this equality and freezes on mismatch (`ReconcileTreasuryCommand.php:405-423`); §16 pins it per transition (clear = net, dishonor = nominal+fees, bounce_fee = fee+VAT) on pgsql (T9/T22).

**The debit-swap rule, stated flow-agnostically (F8):** wherever a receipt-side JE builder debits the repository cash account for a deferred tender, ONLY the cash-debit line is swapped to the §3 portfolio account — the existing credit line(s) are untouched whatever the flow (Cr 411 for B2B customer payments, Cr revenue for POS receipts, Cr deposit liability for POS deposits, Cr on-account for account payments).

## 8. Payment-flow changes (B2B web; G7 + the cash-recognition fix)

**The cutover is scoped to CUSTOMER-direction payments through `PaymentController::store()` only** (T2). `store()` serves both directions (`$isSupplierPayment` branch, `PaymentController.php:406/775-806`); the writer-by-writer contract:

| Writer | `has_maturity` behavior after Phase ② |
|---|---|
| `PaymentController::store()`, customer direction | **the cutover** (below) |
| `PaymentController::store()`, supplier direction | **Phase-① behavior verbatim** (movement + Cr cash — cash-out-at-issuance stays; test-pinned byte-identical) + instrument registered `direction=outbound` for échéancier visibility (D-4). Outbound GL (403/405) deferred |
| `PaymentController::storeMultiple` · `MultiPaymentController::createSplitPayment` / `recordDeposit` / `recordPaymentOnAccount` | **422 — deferred methods rejected on these paths this phase** (T8; today they'd silently keep cash-at-receipt and create no instrument, bypassing G7 through every side door). Explicit translated message; lifted per-path in a follow-up when each is converged |
| `PaymentRefundService::refundPayment` / `partialRefund` / `reversePayment` | **new guard (T3):** a payment with a linked non-terminal instrument (`Received`/`Deposited`/`Bounced`) is NOT refundable/reversible via the cash path — 422 routing the operator to §12 bounce or §12.4 cancel (cash never arrived; today's code would post a cash reversal + movement OUT for money never received, `PaymentRefundService.php:439-473`). Once the instrument is `Cleared`, normal cash refund applies |

**The cutover (customer-direction `store()`):**

- **Server-side instrument creation (G7):** when the method `has_maturity` and the request carries no `instrument_id`, the controller creates the `PaymentInstrument` itself (fields: `reference` required, `maturity_date` required for `effet` / optional for `cheque`, drawer/bank fields optional, custody = the payment's repository) in the same transaction — the FE two-call orchestration (`PaymentForm.tsx:608-658`) is retired; the FE sends `instrument: {...}` inline. `instrument_id` remains accepted (pre-created instruments); it must reference a `Received`, unlinked instrument owned by the same partner-or-null, and it is `lockForUpdate`d BEFORE `postEntryNow` per the §6 lock order (T13).
- **`repository_id` becomes REQUIRED for deferred tenders** (custody must exist; today it's nullable and the JE is gated on `repository->gl_account_id` at `PaymentController.php:770-774` — a repo-less deferred payment would settle the invoice with NO portfolio JE, T12). The portfolio-JE gate is decoupled from the repository's `gl_account_id` (the debit uses the §3 purpose account, not the repo account).
- **Posting swap:** the payment JE debit line uses the §7 receipt account (P or R per method `instrument_kind`) instead of the repository cash account, and **no `record()` call is made**. **The ENTIRE payment amount debits the portfolio account**: when the payment exceeds its allocations, the customer-advance JE (`createCustomerAdvanceJournalEntry`, `PaymentController.php:826-865`) swaps its debit line too — instrument amount (full payment) must equal the total portfolio debit or reconcile #4 drifts by the excess (T7).
- `payments.instrument_id` and `payment_instruments.payment_id` are both written (the survey found `payment_id` never written today). Allocation behavior is unchanged — the invoice is settled by the instrument (Cr 411 via allocation), the PCG position; bounce is what reopens it (§12).
- **Withholding/RAS:** `withholding_enabled` is **rejected (422) on deferred tenders this phase** — the certificate is minted at receipt (`PaymentController.php:630-661`) for cash not yet collected, and §12's reversal has no certificate story; proper RAS-at-maturity is an expert-comptable decision (T18, D-9).
- Payment currency defaults to the company currency — the `?? 'TND'` literals at `PaymentController.php:604/1143` are replaced (T17).
- Immediate methods: byte-identical behavior to Phase ① (test-pinned).

## 9. POS bridge (G6 — the checks-orphaned fix; rule-20 territory)

Extend the **existing** `TreasuryReceiptBridge`, plus the Deposit/AccountPayment siblings — which are **single-leg by contract** (`TreasuryDepositBridge.php:150/176-192`, `TreasuryAccountPaymentBridge.php:163-179`), not a shared tender loop (T21/F8): the maturity branch is a shared helper each bridge calls for its leg(s). One canonical pass per event, same advisory lock, same `ProjectionDependencyMissingException` discipline.

**Sale-receipt maturity legs.** Per canonical tender leg: resolve the payment method (the bridge already loads the full model tenant-scoped, `TreasuryReceiptBridge.php:391-405` — `has_maturity` + `instrument_kind` are available on the worker); if `has_maturity` →
  1. **Create the `PaymentInstrument`** idempotently: `idempotency_key = fiscal_event:{event_id}:instrument:{canonical_index}` (partial unique index; on conflict SELECT + semantic-field validation, mismatch throws — the spine F3 pattern). Fields: `origin=pos`, `kind` from the method, `reference = POS-{event_short}-{index}` + `needs_details=true` (**the cashier-typed check number is LOST to the server** — it travels in `transaction_reference`, which is not in the 6-field sealed canonical `PaymentDTO`; the canonical `instrument_serial` is voucher-semantics only, F7), `maturity_date=null` (⇒ at-sight until completed), **custody = the bridge's default-repository resolution** (`resolveDefaultRepository()` — first GL-linked repo, explicitly NOT drawer/method-aware, a documented P2-6 deferral, `TreasuryReceiptBridge.php:575-608`; custody quality improves when per-method repository mapping lands — T14), `partner_id` = receipt customer if any, amount/currency from the leg.
  2. **Swap the cash-debit line** to the §7 receipt account (credit lines untouched — §7 swap rule) and **skip the movement** for that leg (immediate legs of the same receipt keep their movements — split tenders mix).
  3. Payment row still created (origin=Pos) with `instrument_id` linked.
- **Method-config guard (F7):** a payment method with BOTH `has_maturity=true` AND a voucher instrument-kind per `requiresInstrumentForMethodCode()` is rejected at method create/update (422) — a voucher-kind maturity method would collide with the voucher GL bypass (`GeneralLedgerService.php:2258-2285`) and mint bogus instruments from voucher serials.
- **Missing portfolio account on the worker: THROW** (fail-closed → retry → dead-letter → operator seeds chart) — §5.5, F4.
- **No CompanyContext** on the Horizon worker (rule 20): currency passed explicitly everywhere (`postEntryNow(..., $receipt->currency)` is already the bridge's pattern); the lifecycle service is context-free (§6); projection tests clear `CompanyContext` before `apply()` — including the refund-cancel path.

**Replay/complete-set idempotency across the deploy boundary — the JE-debit-account probe (F5/T11; movement-existence is NOT deterministic because the bridge legitimately completes movement-missing legs, `TreasuryReceiptBridge.php:463-469`):** for an existing leg Payment, inspect its JE's debit-line account —
  - debit on the repository's `gl_account_id` ⇒ **pre-cutover shape**: accept as complete (movement expected/completable via the existing repair branch), **create NO instrument, post no new JE** (no backfill — pre-cutover legs' value was already cashed; they are excluded from reconcile #4 by the same probe);
  - debit on a portfolio account ⇒ **post-cutover shape**: instrument expected (create if missing), movement FORBIDDEN;
  - no Payment row at all ⇒ post-cutover shape (books never written — correct even for pre-deploy-authored events drained from backlog).
  Durable-artifact deterministic; the §18 watermark serves ONLY reconcile-#4 exclusion. Pre-Task-20 legacy null-key events short-circuit whole-event (`:292-301`) and never reach the maturity logic.

**REFUND/VOID receipts** (`invoice_type_code` path) — the maturity-leg contract, replacing the standard refund handling for that leg:
  1. **Resolve the instrument (F1):** original event id from the canonical `original_receipt_reference.fiscal_event_id` (the DTO `PosCoreReceiptProjection.php:513-524` already consumes); candidate set = still-`Received` instruments with `idempotency_key LIKE 'fiscal_event:{original_id}:instrument:%'`; match on (method `instrument_kind`, exact amount). Exactly one match ⇒ cancel; zero or >1 (partial refunds, ambiguous split tenders) ⇒ the alert path — never guess-cancel.
  2. **Cancel posting (F2):** contre-passation **Dr ProductRevenue / Cr P-or-R** (the POS receipt JE has NO customer-receivable leg — `createPOSPaymentEntry` books cash-vs-revenue with `partner_id: null`, `GeneralLedgerService.php:2586-2623`). This JE **is the leg's entire GL effect**: the standard `createPOSRefundReversalEntry` (which credits CASH, `:2697-2706`) and the movement-Out are **both SKIPPED** for the maturity leg — implemented literally per Rev 1, the leg would get both and double-reverse.
  3. **Instrument already remitted/cleared:** the leg posts the STANDARD cash refund reversal + movement Out (the drawer really pays out cash; revenue must reverse) and the instrument is left untouched; record `pos_refund_on_active_instrument` to `audit_events` (keyed per `(refund_event_id, leg_index)` — idempotent, no repeated rows on replay) + log alert; operator resolves the instrument via §12 bounce/manual flow.
  4. **Throw scope (F3):** "never throw" applies ONLY to the business outcomes above (active/missing/ambiguous instrument ⇒ alert). Transient exceptions (DB, GL) propagate normally — retry → dead-letter is the designed operator-visibility path (`ApplyFiscalEventProjectionJob.php:392-401, 428-458`); swallowing them would mark rows `applied` over failures. A `Cancelled` instrument on redelivery ⇒ silent idempotent no-op.

**Back-office completion:** `PATCH /payment-instruments/{id}` (new endpoint, `can:instruments.update`, new permission) — editable ONLY in `Received` status: reference, maturity_date, drawer_name, bank trio/`bank_id`, partner. Writes a `details_updated` event with the diff payload. Register list gets a `needs_details` filter chip so POS-collected paper is completed daily.

## 10. Échéancier (G4 + G8 + I2)

- **`GET /api/v1/treasury/maturing-instruments`** — pending instruments (`Received`/`Deposited`, both directions), filters: date window, direction, kind, repository, partner, `needs_details`; **forward** maturity buckets `overdue / d0-7 / d8-30 / d31-60 / d61-90 / d90+` (the forward mirror of the balance-âgée convention in §3) + per-bucket and grand totals (amounts as strings). Certainty tier surfaced per row: `portfolio` (Received) vs `remitted` (Deposited) — the research's certainty-tiering (portfolio < remis < crédité).
- **Forecast integration (G8):** `UpcomingPaymentsService` gains inbound-instrument maturities in Money-In and outbound in Money-Out (maturity within window, status pending; at-sight/null-maturity instruments bucket as "due now"). **Double-count guard:** an instrument-settled invoice is already excluded from open-invoice inflows (allocation closed `balance_due` at receipt — §8), so instruments replace, not duplicate, their invoices in the forecast. A test pins this (invoice paid by traite appears exactly once, as the instrument maturity).
- **Treasury Overview panel:** "Échéancier — upcoming maturities" card (next 30d, in/out totals + top rows, link to the register filtered view). Data from the new endpoint; FE-only composition.
- **Pre-maturity alert:** new `treasury:instrument-maturity-alerts` per-tenant scheduled command (daily 06:30, `TenantScopedCommand::forEachTenant` — continue-on-throw contract), window from `country_payment_settings.instrument_alert_days` (new nullable column, default 7): finds inbound `Received` instruments with `maturity_date <= today+window` (they should be remitted — banks need lead time) and `Deposited` ones matured `> N` days without settlement; writes ONE `audit_events` row per company per run (`treasury.instrument.maturity_alert`, payload = counts + instrument ids) + `Log::warning`. Surfacing beyond audit_events (mail/notification center) = Phase ③.

## 11. Register + reconcile

- **Register list:** add maturity-window + kind + direction + `needs_details` filters and a maturity-bucket chip row to `InstrumentListPage` (the field contract is already fixed; extend, don't rewrite). Server adds pagination + `meta` (survey: unpaginated `->get()` today).
- **`treasury:reconcile` extension — portfolio↔GL coherence (check #4, alert-only, never freezes):** per company: `Σ(pending cheque nominal) == balance(checks_to_collect)`, `Σ(pending effet nominal by stage) == balance(effects_receivable / effects_in_collection)`. The GL side is the **full balance of the purpose-mapped portfolio accounts** — sound because those accounts are reserved for instrument flows (§5.5 architecture assertion), and necessary because the receipt-side debits arrive on `payment`/`pos_receipt`-sourced JEs, which an EF-provenance-scoped sum would never see (Rev-1's scoping was self-contradictory: permanent false drift from day one — T6). Exclusions: pre-cutover-shaped legs (the §9 JE-debit probe) and pre-watermark rows (§18). Drift ⇒ `audit_events` + log alert; repositories are NOT frozen (wrong target — the freeze list stays cash-drift only). Reminder from the review (framing correction): spine check #2 is movement→JE only — there is NO "every bank JE line has a movement" direction, so check #4 is the ONLY detector for portfolio-account divergence; its drift-seeding tests are load-bearing.

## 12. Impayé depth (I7)

`POST /payment-instruments/{id}/bounce` gains: `routing` (required: `re_present`/`receivable`/`doubtful` — the PCG decision rule §3), `fee_amount` + `fee_vat_amount` (optional, ≥0, money regex), `reason` (existing). Effects, atomically, **in the §6 global lock order — instrument → document rows (id-sorted) → GL advisory → repository** (Rev-1's JE-first ordering deadlocked against concurrent payments, which lock documents before GL at `PaymentController.php:670-674` — T1):

1. Lock the instrument, then `lockForUpdate` the allocated documents (id-sorted) when routing will reopen them.
2. JE per §7. Routing-account map: `re_present` → **413** for effets (value stays effet-receivable awaiting the new slip) and **411** for cheques (P is re-debited when the re-presentation slip is remitted, §7); `receivable` → **411**; `doubtful` → **416**. The lifecycle service owns this map; §7 is the canonical posting table.
3. Movement out (post-clearing dishonor or fee leg — amounts per §7) via the port.
4. **Subledger reopening (T4/T5/T19):** when routing is `receivable` or `doubtful`, the linked payment's allocations are reversed by writing **negative-amount `PaymentAllocation` rows** on the bounce record — never DELETE (the pgsql trigger `update_document_balance_due()` recomputes `balance_due` from `SUM(payment_allocations.amount)`, `2026_01_08_214145`; append-only discipline; there is NO existing `DELETE /allocations` endpoint — Rev 1 cited a phantom). **Tolerance allocations linked to the dishonored payment are reversed too, with contre-passation of the tolerance write-off JE** (`CloseInvoiceWithToleranceService.php:108-118`) — otherwise `balance_due` reopens short of the true receivable. **Document status reverts `Paid` → `Posted`** when `balance_due` reopens (the trigger never touches status; a Paid-stuck reopened invoice would vanish from `UpcomingPaymentsService`'s `status = Posted` filter — exactly the at-risk invoices the échéancier exists for; note `DocumentFullyPaid` side-effects already fired and are not unwound). The payment is marked dishonored (nullable `payments.dishonored_at` — additive, no status-enum surgery). When routing is `re_present`, allocations stay (the invoice remains instrument-settled). Note: the sqlite test driver has no balance trigger — these assertions run on pgsql CI, or the reversal code maintains `balance_due` imperatively as `PaymentController` does.
5. Fee re-billing to the customer (Dr 411 / Cr 708-736) — **deferred** (D-6): the fee fields book our own charge only; re-billing needs an invoice and belongs with Phase ④/expert-comptable input.
6. Withholding: deferred tenders can't carry RAS this phase (§8 guard), so no certificate exists to unwind (T18/D-9).
7. Line status → `bounced`; slip auto-`closed` when last line settles; events + audit rows.

Re-presentation: `POST /instrument-remittances` accepts `Bounced` instruments only when their `dishonor_routing=re_present`; remitting posts the §7 re-presentation JE and a `re_presented` event.

### 12.4 Cancellation
`Received` + (no linked payment OR its payment already reversed/dishonored) → `cancelled` with contre-passation of the receipt JE if one was posted. Anything else must go through bounce (never delete value from transit accounts silently).

## 13. API surface (new/changed)

| Route | Gate | Notes |
|---|---|---|
| `PATCH /payment-instruments/{id}` | `can:instruments.update` (new perm) | §9 completion; Received-only |
| `POST /payment-instruments/{id}/bounce` | `can:instruments.bounce` (new perm; today it piggybacks `instruments.clear`) | §12 |
| `GET /treasury/maturing-instruments` | `can:instruments.view` | §10 |
| `POST /instrument-remittances` · `GET /instrument-remittances` · `GET /instrument-remittances/{id}` · `POST /instrument-remittances/{id}/lines` · `DELETE .../lines/{lineId}` (draft only) · `POST /instrument-remittances/{id}/remit` · `POST /instrument-remittances/{id}/lines/{lineId}/clear` · `.../bounce` | `can:instruments.remit` (create/compose/remit — new perm), `can:instruments.clear`/`bounce` (settlement) | §5.3/§7/§12; `remit` validates bank repo type; line clear/bounce delegate to the lifecycle service |
| Existing `deposit`/`clear`/`bounce`/`transfer` on instruments | unchanged URLs | `deposit` = single-instrument slip wrapper (§6) |

All routes: `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (rule 12, current 4-tuple). New permissions (`instruments.update`, `instruments.bounce`, `instruments.remit`) seeded in `RolesAndPermissionsSeeder` to admin/owner/accountant-equivalent roles → **deploy owes perm reseed + `permission:cache-reset`** (Spatie tenant-blind cache, standing landmine). UUID params `Str::isUuid()`-guarded → 404 (N5 lesson). Responses follow the `{message, data}` envelope; FE uses established unwrap patterns (rule 14).

## 14. Owner / expert-comptable decision points (flagged per handoff §4 — defaults chosen, none block implementation start)

| # | Decision | Default shipped | Who confirms |
|---|---|---|---|
| D-1 | Exact account codes per purpose (§3 table), incl. whether checks use 5112 at receipt or a 51121/51122 split | §3 table as-is, single 5112/5312 | expert-comptable |
| D-2 | FEC journal code for portfolio ops | new `EF` code, declared in FEC descriptive | expert-comptable |
| D-3 | Bounce fee booking at bounce time (manual amounts) vs waiting for statement import (Phase ⑤) | optional manual fields now | owner |
| D-4 | Outbound (effets à payer 403/405) full lifecycle | registration + échéancier only; supplier-direction `has_maturity` payments keep Phase-① cash-at-issuance verbatim (T2); GL deferred | owner (scope) |
| D-5 | Escompte (5114/5314 + 6616/6516) | schema reserved, flow deferred | owner + expert-comptable |
| D-6 | Re-billing dishonor fees to customer (708/736) | deferred | expert-comptable |
| D-7 | Early-remise policy for post-dated instruments | allowed with FE warning; no hard block (config later if asked) | owner |
| D-8 | Alert window default | 7 days, per-country configurable (`country_payment_settings` is per-COUNTRY, no company dimension — T20) | owner |
| D-9 | Withholding/RAS on deferred tenders (certificate today mints at receipt for uncollected cash; bounce has no unwind story) | **blocked (422) on deferred tenders this phase** (T18); RAS-at-maturity design later | expert-comptable |

## 15. Frontend scope

- **PaymentForm:** single-call — inline `instrument{reference, maturity_date, drawer_name, bank…}` block when the method `has_maturity` (retire the two-call orchestration); `MoneyInput`/string amounts throughout (rule 19).
- **InstrumentListPage:** filters/buckets per §11; columns + kind/direction badges; `needs_details` chip.
- **InstrumentDetailPage:** full 9-status typing (fix the 5-status drift), timeline from `instrument_events` (replaces the derived one), actions: remit (nav to slip create pre-selected), clear (fees+value date fields), bounce (routing + fees + reason), custody transfer, cancel; granular `usePermissions` gating (`instruments.*` keys — fix the coarse module-only gating; add the keys to the FE permission map, the known role-map gotcha).
- **Remittances:** list + detail (lines with per-line clear/bounce, slip totals, status) + create flow (pick bank repo, kind, add Received instruments — filterable picker) + **printable bordereau view** (§3 conventions: header depositor/bank/RIB/date/slip number, one line per instrument with drawer/drawee bank/number/amount(+échéance for effets), count + total; print CSS, PDF export deferred).
- **TreasuryOverviewPage:** échéancier panel (§10).
- Design tokens exclusively in new directories (rule 18); all text `t()` en+fr (rule 11); tenant-scoped query keys; types via `typescript:transform` after DTO work (rule 7; worktree `CACHE_STORE=array` gotcha).
- **BankPicker dependency:** instrument forms adopt `BankPicker`+`bank_id` only if `feat/bank-reference-verification` has merged by FE-wave time; otherwise ship free-text and leave a one-task interlock ticket. The `bank_id` column ships either way (§5.1).

## 16. Testing & verification

- TDD per task (rule 2). Backend by-path PHPUnit incl. **pgsql** (triggers/partial indexes are pgsql-only; the treasury-spine-pgsql CI job already exists — extend its path list).
- JE-shape tests pin every §7 posting (per kind × transition × fee/no-fee), TN and FR account resolution, scale-3 amounts (never float — PHPStan guards apply).
- Atomicity: injected GL failure ⇒ no status change, no movement, no event row (the spine acceptance pattern).
- Concurrency: two parallel `clear` on one line ⇒ one JE+movement, one 422. Lock-order test vs a concurrent B2B payment on the same bank repository (no deadlock: advisory→repo everywhere).
- Bridge: canonical-index idempotency (replay ⇒ no duplicate instruments), CompanyContext cleared **including the refund-cancel path** (F6), split tender (cash leg moves, check leg doesn't), **JE-debit-probe replay tests** (pre-cutover-shaped leg ⇒ no instrument minted, movement completable; post-cutover ⇒ instrument, movement forbidden — F5/T11), refund matrix (single match cancels with Dr Revenue / Cr P and NO cash reversal/movement; remitted/cleared ⇒ standard cash reversal + idempotent alert row; ambiguous ⇒ alert, no cancel — F1/F2/F3), **missing portfolio account ⇒ throw/dead-letter** (F4), nothing writes `pos_receipt_payments` (F9).
- Reconcile-#2 movement↔JE-line equality pins per transition: clear = net, dishonor = nominal+fees, bounce_fee = fee+VAT (pgsql — T9/T22). Refund-guard pins: deferred payment with pending instrument ⇒ 422 on refund/partial/reverse; cleared ⇒ refundable (T3). Supplier-direction deferred payment byte-identical to Phase ① (T2). Reopened invoice reverts to `Posted` and reappears in `UpcomingPaymentsService` (T5). Side-door writers 422 deferred methods (T8).
- Forecast double-count pin (§10); reconcile #4 drift seeding both directions (missing JE line / stray account entry); maturity-alert command window + continue-on-throw.
- FE vitest per component/hook; Playwright E2E A→Z: B2B traite payment → register → slip create/remit (JE visible) → clear with fee (bank movement + JE + Overview cash) → second instrument bounce with `receivable` routing (invoice balance_due reopens) → échéancier panel shows the survivor → `treasury:reconcile` green. POS leg: seeded check tender event → instrument appears `needs_details` → complete → full cycle.
- Suites never run unbounded (standing rules); preflight before each gate.

## 17. Certification traceability

| Constraint | Design element |
|---|---|
| Transitions append-only, reversals by contre-passation (NF203) | `instrument_events` + trigger; §12.4 cancel = contre-passation; no JE edits anywhere |
| Pièce justificative | every JE carries `source_type/source_id` (instrument / remittance); movements link JE + source (spine) |
| FEC journal coding declared | `EF` code + descriptive-doc note (D-2) |
| Account-state coherence auditable | reconcile check #4 (§11) |
| NF525 perimeter untouched | bridge changes are projection-side only; the canonical payload is **read-only — never mutated, hash bytes never recomputed or re-authored** (the bridge must read it, F9); nothing writes `fiscal_events` or `pos_receipt_payments` (test-pinned) |
| 10-year retention | append-only tables, no purge paths |

## 18. Migration, deploy, interlocks

- Migrations additive + re-runnable (`hasColumn`/`hasTable` guards, `DROP IF EXISTS` trigger form). No production tenants; **staging brownfield note:** movements already recorded for pre-cutover check tenders stay (append-only, books not retroactively restated); pre-cutover-shaped legs never mint instruments (the §9 JE-debit probe — F5/T11). The `phase2_cutover_at` watermark's ONLY job is reconcile-#4 exclusion of pre-existing instrument rows and pre-cutover GL noise — replay-shape detection does NOT depend on it.
- Deploy owes (standing): `tenants:migrate` per tenant, perm reseed + `permission:cache-reset`, chart-of-accounts seeder re-run (adds §3 accounts).
- **Interlocks:** bank-directory track owns `banks` + `BankPicker`; instrument surfaces are THIS track (their brief §6 carve-out honored — `bank_id` lands here). treasury-ui-gaps track touches `RepositoryDetailPage`/`ExpenseDetailPage` only — no file overlap with §15. GL roadmap: §3 account seeding logged in the realignment log if the published chart shape counts as canonical.
- Worktree off dev per rule 21; hard-stop adversarial gates per milestone (standing owner rule); implementation dispatch **gated by owner** — this spec+plan cycle ends before any code.

## 19. Open questions for the implementation plan (not design blockers)

Resolved by the adversarial review (code-verified): `MovementSourceType::Instrument` exists (`MovementSourceType.php:17`); `payments.instrument_id` exists and is written (`Payment.php:80`, `PaymentController.php:601`); the allocation-reversal mechanism is negative rows + the pgsql `balance_due` trigger (§12 step 4, T4 — the Rev-1 DELETE route was a phantom); `country_payment_settings` is per-country (`unique('country_code')`, T20); the sibling bridges are single-leg (T21).

Remaining for the plan to trace before writing code:
1. Remittance number sequence storage: company-row counter column vs a tiny sequences table (mirror whichever the spine's JE-number race fix used).
2. Document-immutability trigger (`2025_12_11_054716`) — verify it permits the `Paid` → `Posted` status revert (T5); if not, the revert needs a sanctioned path through/around it.
3. `dishonored_at` interplay with payment list/status filters on the FE (any surface treating `Completed` + `dishonored_at` as still-good money).
4. Exact shape of the shared maturity-leg helper across the three bridges (single-leg vs multi-leg call sites).

## 20. Adversarial-review reconciliation (2026-07-11)

Full review: [reviews/2026-07-11-treasury-phase2-adversarial-review.md](reviews/2026-07-11-treasury-phase2-adversarial-review.md) — treasury-reviewer (T1–T22) + fiscal-pos-reviewer (F1–F10), both verdicts APPROVE-WITH-FIXES on Rev 1; **all findings accepted** (every one code-verified by the reviewers). Resolutions:

| # | Sev | Finding (short) | Resolution |
|---|---|---|---|
| T1 | BLOCKER | Bounce lock ordering deadlocks vs B2B payments (doc-lock/advisory inversion) | §6 global lock order: instrument → documents → GL advisory → repository; §12 reordered |
| T2 | BLOCKER | Cutover direction-blind — supplier traites break books | §8 scoped to customer direction; supplier deferred = Phase-① verbatim + outbound registration; D-4 |
| T3 | BLOCKER | Refund/reverse of deferred payment pays out cash never received | §8 guard: non-terminal instrument ⇒ 422 on refund/partial/reverse; cleared ⇒ normal |
| F1 | BLOCKER | POS refund: no instrument-resolution mechanism | §9 refund step 1: original-event key prefix + (kind, amount) match; 0/>1 ⇒ alert |
| F2 | BLOCKER | POS contre-passation shape wrong (no AR leg) + double-reversal risk | §9 refund step 2: Dr Revenue / Cr P is the leg's ENTIRE GL effect; standard reversal + movement skipped |
| T4 | HIGH | Allocation-reversal path cited in Rev 1 doesn't exist | §12 step 4: negative allocation rows + pgsql trigger; never DELETE |
| T5 | HIGH | Reopened invoice stuck `Paid` — vanishes from forecast | §12 step 4: status revert Paid→Posted + forecast-reappearance pin; §19.2 trigger check |
| T6 | HIGH | Reconcile #4 provenance scope blind to receipt-side debits (permanent false drift) | §11: full balance of reserved purpose accounts + §5.5 architecture assertion + watermark |
| T7 | HIGH | Excess/advance JE still debits bank cash | §8: ENTIRE payment amount debits portfolio (advance JE swaps too) |
| T8 | HIGH | storeMultiple/split/deposit/on-account side doors bypass G7 | §8 writer table: 422 deferred methods on unconverged paths |
| T9 | HIGH | Bounce-fee movement (fee-only) ≠ JE bank lines (fee+VAT) ⇒ nightly freeze | §7: movement = bcadd(fee, VAT); §16 equality pins |
| T10/F2 | HIGH | (same as F2 — independently found by both reviewers) | see F2 |
| T11/F5 | HIGH | Replay marker non-deterministic; pre-cutover legs would mint bogus instruments watermark can't catch | §9 JE-debit-account probe; no backfill; watermark = reconcile-#4 only (§18) |
| T12 | HIGH | Repo-less deferred payment settles invoice with no portfolio JE | §8: repository required for deferred; JE gate decoupled from repo `gl_account_id` |
| F3 | HIGH | "Never throw" over-broad — silent-skip over transient failures | §9 refund step 4: business-outcome-only; transients propagate; Cancelled ⇒ no-op |
| F4 | HIGH | Missing portfolio account on worker path unspecified | §5.5/§9: throw fail-closed → dead-letter |
| T13 | MED | Instrument-lock ordering vs PaymentController unspecified | §6/§8: instrument locked before `postEntryNow`; slips id-sorted |
| T14/F8 | MED | Custody ≠ "drawer repo"; sibling bridges single-leg; credit-line language | §9 reworded (default-repo P2-6 deferral); §7 flow-agnostic swap rule |
| T15 | MED | `banks` FK would fail — table unmerged | §5.1: plain indexed uuid, FK follow-up |
| T16 | MED | Instrument endpoints tenant-scoped only (company leak) | §5.1: company_id predicate in the rework |
| T17 | MED | Hardcoded 'TND' defaults | §5.1/§8: company-currency default |
| T18 | MED | RAS certificate on deferred tenders has no bounce story | §8: withholding 422 on deferred; new D-9 |
| T19 | MED | Tolerance write-offs leave balance_due short on reopen | §12 step 4: reverse tolerance allocations + contre-passation |
| F6 | MED | Lifecycle service CompanyContext vs worker invocation | §6: context-free entrypoints; §16 cleared-context cancel test |
| F7 | MED | Check reference lost to server; voucher-kind collision | §9: loss stated; method-config 422 guard |
| T20 | LOW | `country_payment_settings` is per-country | D-8/§19 corrected |
| T21 | LOW | Sibling tender-iteration overstated | §9 phrasing fixed |
| T22 | LOW | Reconcile-#2 pins missing | §16 added |
| F9 | LOW | "Payload unread" false as written | §17 corrected (read-only, never mutated) |
| F10 | LOW | Two §19 open questions already answered in code | §19 updated |
