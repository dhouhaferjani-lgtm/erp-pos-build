# Treasury / Payments Module Audit — Cutoff vs Deferred

> **Date:** 2026-06-27 · **Mode:** READ-ONLY (no code changed) · **Lens:** real multi-shop Tunisian parapharmacy usage
> **Owner's read going in:** *"it's okay but far from comprehensive."* This audit confirms that read and sorts the gaps.

---

## 0. TL;DR — verdict

**The core POS demo path works.** A cash or card sale, drawer open/close, X/Z reports, and a basic refund all function end-to-end on the **device-authoritative (`fiscal_schema_version = 3`)** path that the parapharmacy demo seeders provision. The module is *not* broken for what the demo shows.

**Where it falls short of "comprehensive"** is everything off the happy POS sale path:

1. **No automatic cash-rounding / under-tender tolerance on the live POS path.** The server tolerance config exists and is honoured for *B2B invoices*, but the **POS client is blind to it** and the **live POS projection never applies it**. Under-tender requires a per-sale manager PIN. *(This is the prompt's hypothesised headline — confirmed, and it is broader than stated. → DEFERRED, with a demo-prep mitigation.)*
2. **No money-movement "spine."** Supplier payments are the only outflow that does all three of {reduce repository balance, post GL, reduce payable}. Expenses post GL but skip the treasury balance; POS payouts and refunds move the drawer but post no GL; treasury-side refunds move neither. *(→ the single biggest real-usage gap.)*
3. **A latent data-config trap** (`account_id` vs `gl_account_id`) that makes **B2B supplier payments 422** and **B2B customer payments silently skip GL** on seeded demo data. *(Trivial fix; → DEFERRED unless the demo narrates back-office payments.)*

The **CUTOFF list is deliberately short** (Section 6): the *POS demo* path is sound, so its must-do items are mostly demo-data hygiene plus one card-UX wrinkle.

> **⚠️ Scope expansion (2026-06-27, after owner feedback): the WEB BACK-OFFICE is materially worse than the POS path, and the EXPENSE flow is broken end-to-end.** The owner's requirement — *log any expense, label it, attach documents, and have it flow into the GL* — does **not** work today: posting an expense to the GL returns **403 for every user including `admin`**, the expense detail endpoint **500s**, and there is no UI to map a category to a GL account. Full deep-dive in **Part B (Sections 9–11)**. This reframes the cutoff list (Section 6 is updated with a back-office tier) and competes with the money-movement spine for "biggest gap."

---

## 1. What exists — module map

### 1.1 Two payment write paths

| Path | Route | Live? | Records payment | Posts GL |
|---|---|---|---|---|
| **POS device-authored sale** (the demo path) | `POST /api/v1/pos/sync/fiscal-events` → `SALE_RECEIPT` | **Yes** | `TreasuryReceiptBridge` creates one `Payment` per tender line (`origin=Pos`, `payment_type=POS`) | **Yes** — `createPOSPaymentEntry` (Dr cash / Cr revenue), reads **`gl_account_id`** |
| **POS account payment** | `ACCOUNT_PAYMENT` / `DEPOSIT_RECEIPT` events | Yes | `TreasuryAccountPaymentBridge` / `TreasuryDepositBridge` | Yes (allocation) |
| **B2B `/payments` single** | `POST /payments` (`can:payments.create`) | Yes | `PaymentController::store` | **Conditional** — only if `repository.account_id` non-null; reads **`account_id`** |
| **B2B `/payments` multiple** | body with `payments[]` → `storeMultiple` | Yes (AR-only) | `PaymentController::storeMultiple` | Conditional, same `account_id` gate |
| **B2B split / deposit / on-account** | `MultiPaymentController` → `MultiPaymentService` | Yes | rows + balances only | **No GL** |
| **Legacy inline POS** | `POST /pos/receipts`, `/pos/receipts/{id}/payments` | **Retired (HTTP 410)** | `ReceiptPaymentService` (dead) | dead |

> ⚠️ **Two retired paths look live but aren't.** `ReceiptPaymentService` (which *does* apply tolerance and post the write-off GL) and `ReceiptReturnService` are reachable only via 410-retired routes for new sales. Reading them gives a false sense that POS tolerance/returns post GL — on the live v3 path they do **not** (Sections 3, 5.4). Evidence: `app/Modules/POS/routes.php:145-168`.

### 1.2 Tender taxonomy

- **`PaymentInstrumentKind`** (`app/Modules/POS/Domain/Enums/PaymentInstrumentKind.php:37-42`): `store_voucher` **wired**; `restaurant_voucher` and `gift_card` **declared-only stubs** (throw if exercised).
- **`PaymentMethod`** (`app/Modules/Treasury/Domain/PaymentMethod.php`) is **not an enum** — a per-tenant config table (`is_physical`, `has_maturity`, `requires_third_party`, `fee_type`). Tender types are whatever seeders create; `method_code` strings are UN/ECE-4461 mapped (`CASH`, `CARD`, `VOUCHER`).
- **`PaymentType`** (`Treasury/Domain/Enums/PaymentType.php:9-25`): `DocumentPayment`, `Advance`, `Refund`, `CreditApplication` (no writer), `SupplierPayment`, `POS`.
- **`PaymentInstrument`** — cheque/voucher lifecycle (deposit/clear/bounce/transfer); separate B2B flow, relevant for TN cheques.
- **Live POS UI tenders** (`apps/pos`): cash (quick path), card (only as a free-form line in the Advanced Payments modal), store-voucher, split/mixed, on-account charge. `restaurant_voucher`/`gift_card` hard-gated to Phase 2.

### 1.3 Treasury accounts, drawer, X/Z (device-authoritative v3)

- **`PaymentRepository`** = cash register / safe / bank account / virtual; carries `balance`, `account_id` *and* `gl_account_id` (see Section 7 trap).
- **Shift / drawer** scoped **per terminal** (`pos_shifts.terminal_id`, one open shift per terminal). Demo seeders set `fiscal_schema_version = 3` (`DemoPharmacySeeder.php:505`, `ParapharmacyMultiBranchSeeder.php:592`) → device mints the shift UUID and authors `SESSION_OPEN`/`OPENING_FLOAT`/`X_REPORT`/`SESSION_CLOSE`/`Z_REPORT` locally; server projects them. Legacy server services (`ShiftManagementService`, `CashDrawerService`, server `ReportGenerationService`) **409/throw for v3** and are dormant.
- **X report** — `pos_x_reports`, non-fiscal, non-resetting, repeatable; tender + VAT breakdown (no cash-position field).
- **Z report** — `pos_z_reports`, hash-chained, sequential `z_number` (never resets), tender breakdown + cash position (opening/expected/counted/variance) + `tolerance_summary`.
- **Cash movements** (`CashDrawerOperation`): `OPENING, SALE, REFUND, DEPOSIT, PAYOUT, CLOSING`. Pay-out (petty cash) supported with manager-PIN approval. **No** dedicated safe-drop or float-adjustment type.
- **Rule-20 cross-layer contracts: COMPLIANT** — SQLite TEXT boundaries route through `toSqliteUtc` (`endOfDayPreview.ts:124-133`); device-only `fiscal_shift_id`/`fiscal_session_id` are merged not replaced (`terminalStore.ts:785-798`); projections run on the `fiscal-projections` Horizon queue.

---

## 2. Findings register

Severity = impact on a **credible multi-shop parapharmacy demo**. Class = **CUTOFF** (needed for the demo) / **DEFERRED** (post-launch integration branch).

| # | Finding | Sev | Class | Evidence |
|---|---|---|---|---|
| **F1** | **POS client blind to server tolerance/cash-rounding config; under-tender needs a per-sale manager PIN** | High | **DEFERRED** | `apps/pos/.../paymentStore.ts:862-867` (quick-cash hard block), `:1113-1119` (advanced path requires `tenderTolerancePin`); `CashPaymentScreen.tsx:47` (`tenderedNum >= total`); only tolerance API client-side is reporting-only (`toleranceApi.ts`) |
| **F2** | **Live POS projection ignores tolerance entirely** — posts at literal line amounts, books no write-off, never increments shift tolerance | Med | **DEFERRED** | `TreasuryReceiptBridge.php:319-447` (no tolerance ref); the only POS tolerance writer (`ReceiptPaymentService.php:172-211,399-425`) is on the 410-retired route |
| **F3** | **No GL reversal on POS returns/refunds** — no return projection bridge exists; cash refund lowers the drawer but posts no Dr return / Cr cash | High (books) / Med (demo) | **DEFERRED** | Treasury bridges handle only `SALE_RECEIPT`/`ACCOUNT_PAYMENT`/`ACCOUNT_CHARGE`/`DEPOSIT_RECEIPT` (`Projections/*.php` `handlesEventType`); `ReceiptReturnService.php` injects no GL service |
| **F4** | **Treasury-side refunds move neither repository balance nor GL** | High (books) | **DEFERRED** | `PaymentRefundService.php:27-29` (ctor takes only scale resolver), `:109/:201/:496` (reuse `repository_id`, no balance update) |
| **F5** | **Web Expense posts GL but never decrements the treasury repository balance** | High (cash position) | **DEFERRED** | `ExpenseService.php:48-49,103-125` (stores `payment_repository_id` in metadata only); `GeneralLedgerService.php:2047-2122` (GL only) |
| **F6** | **POS petty-cash payout moves the drawer but posts no GL and no expense record** — disconnected from F5 | Med | **DEFERRED** | `CashDrawerService.php:278-289,381`; no `glService`/`Expense` linkage |
| **F7** | **`account_id` vs `gl_account_id` split → B2B supplier payments 422, B2B customer payments silently skip GL, on seeded demo data** | High | **DEFERRED** (trivial fix) | guard `PaymentController.php:343`; GL gate `:579`; reads `account_id` `:588/609/646/909/979/1130/1196`; seeder sets only `gl_account_id` (`PaymentRepositorySeeder.php:74`); backfills only `gl_account_id` (`2026_03_02_..`, `2026_03_24_..`) |
| **F8** | **v3 cash-close variance produces no fraud alert and no variance email** — listener fires only on legacy paths that 409 for v3 | High (if demoing variance→alert) | **DEFERRED** | `ZSessionLifecycleProjection.php:256-270` stamps variance but never `event(CashCountRecorded)`; `ZReportSyncController.php:72-82` 409s for v3; default `cash_variance_email_severity='none'` (`CompanyFraudSettings.php:51-57`) |
| **F9** | **`DEPOSIT` sign convention contradicts across layers** — device treats as cash-IN, legacy server as cash-OUT; online/offline sign flip on v2 | High (latent on v3) | **DEFERRED** | device `cashDrawerApi.ts:177` (`CASH_IN`) vs legacy `CashDrawerService.php:404-407` (subtract), `CashDrawerOperation.php:124/156/173` |
| **F10** | **Owner dashboard shows sales-tender mix + shift-variance, but no live cash position, no bank-vs-cash split, no repo balances** | High (owner narrative) | **DEFERRED** | `SalesReportService.php:160-194` (sales tenders, excludes returns); `CashRegisterReportService.php:20-72` (cash-only shift variance by shop/terminal); no service reads `payment_repositories.balance` |
| **F11** | **No shop-level / multi-terminal drawer consolidation** — every drawer & Z-sequence is per-terminal; no "store close" aggregation | Med | **DEFERRED** | `ZReport` scopes `forTerminal`; per-terminal hash chain |
| **F12** | **Default EOD count is declared, not blind** — preview shows expected cash before counting despite a `require_blind_cash_count` setting | Med | **DEFERRED** | `EndOfDayPreviewModal.tsx:119-127`; `buildEndOfDayPreview` returns `expected_cash`; setting `CompanyFraudSettings.php:93` not enforced in UI |
| **F13** | **Cross-terminal refund lands on the processing terminal's drawer** — refunding a terminal-A sale on terminal B skews B's variance | Med | **DEFERRED** | `ReceiptReturnService.php:238-242` (uses current `terminalId`); `CashDrawerService.php:350-368` |
| **F14** | **Card tender has no dedicated quick screen** — card is only an Advanced-Payments free-form line; `CardPaymentModal`/`processCashTenderedModal`/`processCardCheckout` are dead code | Med | **CUTOFF** (small) | `AdvancedPaymentsModal.tsx:349`; dead `paymentStore.ts:958` (`processCardCheckout`, zero callers) |
| **F15** | **No cash rounding anywhere** — totals settle to exact millime (TND scale 3); no nearest-coin rounding of the payable | Med | **DEFERRED** | `lib/denominations.ts` (quick-tender presets only, `Math.ceil`); no `round*`/`nearest` in tender path |
| **F16** | **`MultiPaymentService` split/deposit/on-account write no GL** | Med | **DEFERRED** | `MultiPaymentService.php:58-305` |
| **F17** | **Rule-19 float drift in tender math & the full-tender guards** (visible figures + gate decisions are float; canonical hashed amounts are string-safe) | Med | **DEFERRED** | `CashPaymentScreen.tsx:45-47`; `paymentStore.ts:863,1113` (`+ 0.000001` float epsilon); `AdvancedPaymentsModal.tsx:239-252` |
| **F18** | **No-arg `getScale()` in HTTP-only services** — safe today, rule-19 fragile if reused in a job | Low | **DEFERRED** | `MultiPaymentService.php:382`, `PaymentController.php:53`, `GeneralLedgerService.php:50` |
| **F19** | **Declared-only tenders throw** (`gift_card`, `restaurant_voucher`); FX & cash-discount config columns are Phase-2 stubs | Low | **DEFERRED** | `PaymentInstrumentKind.php:40-41`; `country_payment_settings` migration lines 26-32 |
| **F20** | **GL hygiene (from accounting audit, treasury-relevant):** `postEntry` never asserts debits == credits before sealing the hash chain; partner-balance cache refresh is a no-op until the afterCommit post step; no scheduled subledger↔GL reconciliation | Med | **DEFERRED** | `docs/superpowers/audits/2026-06-22-balance-conventions-audit/03-gl-sign-coherence.md` (F3/F4/F7) |

**Wired & working (no action):** supplier-invoice payment incl. cash/bank choice + payable reduction + GL (`PaymentController.php:223,547,580`; `GeneralLedgerService.php:548`); GR-IR supplier cycle on `dev` (`SupplierInvoicePostingService.php:197`); supplier-advance refund (`VendorRefundService.php:37-174`); customer-payment GL (`createPaymentReceivedJournalEntry`); POS refund destination routing (cash / original / store-voucher) incl. cross-terminal; POS SALE_RECEIPT → GL via `gl_account_id`; X/Z report separation; rule-20 contracts.

---

## 3. The headline gap, assessed (tolerance / full-tender)

**Hypothesis (from the prompt):** *the POS hard-requires full tender; server tolerance/cash-rounding config exists but the POS never reads it.*

**Confirmed — and it's broader than stated.** Three layers, only one of which applies tolerance:

1. **Config exists.** `country_payment_settings` (`migration …:14-36`) holds `payment_tolerance_enabled`, `payment_tolerance_percentage` (0.5%), `max_payment_tolerance_amount` (**TN seeded to 0.100**, FR 0.50), and write-off purposes. Resolution is Company → Country → system default (`PaymentToleranceService.php:41-66`).
2. **Server applies it for B2B invoices only.** `CloseInvoiceWithToleranceService.php:71-130` books the residual to GL 658 and writes a tolerance allocation. This path is live (`Document/.../routes.php:151`).
3. **The live POS path applies it nowhere.** `TreasuryReceiptBridge` posts payment lines at their literal amounts and references tolerance zero times. The only POS service that *did* apply it (`ReceiptPaymentService`, with a clean `toleranceChecker->check()` that auto-accepts a within-band under-tender **without** a manager PIN) sits on the **410-retired route**.
4. **The POS client is blind.** It never reads any tolerance/rounding config. The quick-cash path hard-blocks any under-tender (`paymentStore.ts:862-867`), and the advanced path admits one only with a per-sale **manager PIN** authoring signed `tender_tolerance_override` evidence (`:1113-1161`). The allowance is a hardcoded `0.000001` float epsilon, not the configured band.

**Net for TN cash rounding:** Tunisia's smallest circulating coin is far above the millime, so a scale-3 total (e.g. `12.345 TND`) is not exactly cash-payable. On the live POS path there is **no automatic within-tolerance acceptance** — a rounded-down cash payment is blocked unless a manager keys a PIN on every such sale. Over-tender computes change the drawer cannot physically dispense.

**Classification: DEFERRED.** The prompt itself lists "TN cash-rounding / tolerance" as an example deferred feature, and doing it *correctly* on v3 is non-trivial: the **device** must read the band, compute the write-off, author a tolerance fiscal event, **and** a new projection bridge must book GL 658 + increment the shift's `tolerance_writeoff` (none of which exists on the live path today). That is integration-branch work, not a demo patch. **Demo mitigation is in Section 6.**

---

## 4. Cross-layer correctness notes (rules 19/20)

- **Rule 20 — COMPLIANT** on the audited shift/drawer paths: `toSqliteUtc` on the EOD window query, device-field merge in `fetchCurrentShift`, projections on the `fiscal-projections` Horizon queue. *(One low-risk item: confirm every `onQueue('…')` in POS/Fiscal projection dispatchers is in `horizon.php` defaults — `HorizonQueueCoverageTest` should cover it.)*
- **Rule 19 — canonical amounts are string/bcmath-safe** (hashed receipt bytes, GL postings), but **tender-UI math and the full-tender guards run on JS floats** (F17). For TND scale-3 the epsilon is safe and amounts canonicalise to strings before hashing, so fiscal integrity holds; the *visible* change/remaining and the *gate decisions* still violate the precision contract.
- **Projections run with no CompanyContext** — currency is passed explicitly in the live bridges (`TreasuryReceiptBridge.php:435`, `PaymentRefundService` resolves `getScale($currency)`), which is correct. The no-arg `getScale()` callers (F18) are HTTP-only and safe today.

---

## 5. Detailed sub-flow assessments

**5.1 Cash sale** — works. Tendered captured, change computed/displayed (`CashPaymentScreen.tsx:45-46`, `CheckoutSuccessModal.tsx:140-145`). No under-tender without manager PIN (F1). No cash rounding (F15).

**5.2 Card sale** — works but buried: only via the Advanced-Payments free-form line (F14); the dedicated card modal/checkout is dead code.

**5.3 Split / mixed tender** — works. `AdvancedPaymentsModal` accumulates cash/card lines + voucher tenders, sums with Big.js strings, submits one local-first receipt. Full-tender still required unless manager-PIN override.

**5.4 Refund / return** — money routes correctly to drawer / original payment / store-voucher, cross-terminal supported, manager-PIN gated (good). **But posts no GL reversal** (F3) and the treasury-side leg moves no repository balance (F4) → books and physical drawer drift on every refund. Refund destination server-gating is an unwired seam (all three always enabled).

**5.5 Supplier / expense outflow** — supplier-invoice payment is fully wired (balance ↓ + GL + payable ↓) **when `account_id` is set**, which it isn't on demo data (F7 → 422). Web expenses post GL but skip the treasury balance (F5); POS petty-cash payout moves the drawer but posts no GL (F6). The two never meet.

**5.6 Reporting** — owner dashboard shows a sales-tender pie + a by-shop/by-terminal **cash-variance** table, but no running cash position, no bank-vs-cash split, no repository balances (F10), and no multi-terminal store-close consolidation (F11).

---

## 6. (a) CUTOFF shortlist — needed for a credible demo

There are **two cutoff tiers**. Tier 1 (POS demo) is short — the POS path works, so most "fixes" are demo-data hygiene. **Tier 2 (back-office go-live) is NOT short** and is mostly real code, because the owner's expense requirement is broken end-to-end. Effort: **S** = <½ day, **M** = 1–2 days, **L** = 3+ days.

### Tier 1 — POS demo (cash + card + drawer + X/Z + refund)

| ID | Action | Why it's cutoff | Effort |
|---|---|---|---|
| **CUT-1** | **Demo-price & data hygiene:** seed parapharmacy prices/VAT so cash totals land on tender-able amounts (avoid the under-tender PIN wall, F1/F15), and **do not script** B2B supplier payment, web expense, or variance→alert into the demo — all are broken on seeded data (F5–F8). | Prevents a visibly stuck checkout or a silently-wrong figure mid-demo. Zero code risk. | **S** (config) |
| **CUT-2** | **Surface a card quick-action** (or rehearse the Advanced-Payments card flow). Card is a counter-staple for parapharmacy; today it's a free-form line inside a split-payment modal (F14). | "Cash + card sales" is explicitly in demo scope; the current card UX reads as unfinished. | **S–M** |
| **CUT-3** | **Confirm demo terminals are `fiscal_schema_version = 3`** and that a Z-report close shows the cash-position + tender breakdown you intend to show. | The whole drawer/X-Z story depends on the v3 device path; v2 would expose the legacy `DEPOSIT` sign bug (F9) and different code. | **S** (verify) |
| **CUT-4** *(decision, not code)* | **Decide the owner-dashboard cash narrative.** If the demo claims "owner sees daily cash position," note that today it shows sales-tender mix + shift variance, not a live position or bank/cash split (F10). Either reframe the narrative or accept the gap. | Avoids over-promising in the owner pitch. | **S** (decision) |

> If the demo storyline **must** include a back-office supplier payment, then **F7 is promoted to CUTOFF** and the fix is genuinely trivial (effort **S**): either populate `account_id` alongside `gl_account_id` in `PaymentRepositorySeeder`, or make `PaymentController` fall back to `gl_account_id` when `account_id` is null. Flagged here so the call is explicit.

### Tier 2 — back-office go-live (per the owner's "log → label → attach → GL" requirement)

These are **correctness fixes, not features**, and most are small. Full detail + evidence in **Section 11**. Summary:

| ID | Fix | Addresses | Effort |
|---|---|---|---|
| **BCUT-1** | Make expense/category authz real (policies vs seeded `expenses.*`, or `can:`/`module:` middleware) — unblocks view/edit/delete/**post** | E1, E2, E5 (all Critical) | **S–M** |
| **BCUT-2** | Add `Document::attachments()` polymorphic relation → expense detail stops 500-ing, shows receipts | E3 (Critical) | **S** |
| **BCUT-3** | Expense post decrements `payment_repository.balance` (cash-on-hand matches GL) | E4 | **S–M** |
| **BCUT-4** | Category→GL `account_id` picker in UI + seed TN parapharmacy expense categories | E8 | **S–M** |
| **BCUT-5** *(if scope allows)* | Input-VAT split + `is_paid=false` AP path on expenses | E6, E7 | **M** |
| **W1** | Make the repository GL-account select required/defaulted (don't create GL-less repos) | W1/F7 | **S** |

> **Without BCUT-1..3 the owner cannot record a single expense end-to-end.** These are the highest-urgency items in the audit and should gate any "treasury is ready" claim.

---

## 7. (b) DEFERRED backlog → integration branch

Grouped by theme; all carry evidence in Section 2.

**Tolerance / cash-rounding (the prompt's named deferred feature)**
- **D1** — Wire the live POS path for within-tolerance under-tender: client reads `country_payment_settings` band; device authors the write-off + tolerance fiscal event; **new projection bridge** books GL 658 and increments shift `tolerance_writeoff` (F1, F2). *(L)*
- **D2** — TN cash-rounding of the payable to nearest circulating coin, with the rounding delta booked to the tolerance/rounding account (F15). *(M)*

**Money-movement spine (see Section 8 — the biggest gap)**
- **D3** — GL reversal on POS returns via a return projection bridge (F3). *(M)*
- **D4** — Treasury refunds decrement repository balance + post GL reversal (F4). *(M)*
- **D5** — Expenses decrement the treasury repository balance, not just GL (F5). *(S–M)*
- **D6** — Unify POS petty-cash payout with the expense ledger + GL (F6). *(M)*
- **D7** — GL for `MultiPaymentService` split/deposit/on-account (F16). *(S–M)*

**Repository GL config**
- **D8** — Collapse the `account_id` / `gl_account_id` duality (one column, or a documented resolver) and backfill `account_id`; add a guard/test so a repository can't be GL-linked on one path and null on the other (F7). *(S–M)*

**Cash control / fraud (v3)**
- **D9** — Dispatch `CashCountRecorded` (→ fraud alert + variance email) from the v3 `ZSessionLifecycleProjection`; raise default `cash_variance_email_severity` off `none` (F8). *(M)*
- **D10** — Reconcile the `DEPOSIT` sign convention across device/legacy/resource layers; add safe-drop + float-adjustment movement types (F9). *(M)*
- **D11** — Enforce blind count when `require_blind_cash_count` is set (F12). *(S)*

**Multi-shop reporting**
- **D12** — Live daily cash-position widget + bank-vs-cash split + repository balances on the owner dashboard (F10). *(M–L)*
- **D13** — Shop-level multi-terminal drawer/Z consolidation ("store close") (F11). *(L)*
- **D14** — Cross-terminal refund: attribute the cash impact to the originating shop/shift, or block cross-terminal cash refunds (F13). *(M)*

**Precision / hygiene**
- **D15** — Replace float tender math + `0.000001` epsilon guards with string/bcmath (F17); audit no-arg `getScale()` callers (F18). *(S–M)*
- **D16** — GL safety: assert debits==credits before sealing the hash chain; fix the partner-balance refresh no-op; add a scheduled subledger↔GL reconciliation (F20). *(M)*

**Phase-2 tenders**
- **D17** — Implement `gift_card` / `restaurant_voucher`; FX & cash-discount config (F19). *(L)*

---

## 8. (c) The single biggest real-usage gap

**There is no money-movement "spine" that ties drawer cash, treasury repository balances, expenses, refunds, and GL together.**

Supplier-invoice payment is the *only* outflow that does all three of {decrement repository balance, post GL, reduce payable}. Every other money movement is partial:

- **Expenses** → post GL, **skip** the treasury balance (F5).
- **POS payouts** → move the drawer, **skip** GL and the expense ledger (F6).
- **POS returns** → move the drawer, **skip** GL (F3).
- **Treasury refunds** → move **neither** balance nor GL (F4).
- **B2B split/deposit/on-account** → move balances, **skip** GL (F16).

The downstream symptom the owner actually feels: the dashboard's "cash" surfaces are a **sales-tender mix** and a **shift-count variance** — never an **actual daily cash position** by shop/terminal, and never a **bank-vs-cash split** (F10). A multi-shop parapharmacy owner's first treasury question — *"how much cash is in each shop right now, and how much is in the bank?"* — cannot be answered by the system today, because the balances those numbers would come from are only partially maintained.

This is the integration-branch centrepiece: define one path for every money movement (`balance ↓/↑ + GL entry + subledger`), then build the cash-position reporting on top of balances that are now trustworthy. The tolerance/cash-rounding headline (Section 3) is a real gap but a **narrower** one; this is the structural one.

> **Co-headline (back-office):** the expense flow (Part B, Section 11) is **broken end-to-end** — not "partial," not "wired but skips a step," but **non-functional**: you cannot post an expense to the GL through the API (403 for everyone), cannot open an expense detail (500), and cannot map a category to a GL account in the UI. For an owner whose stated requirement is "log expenses → label → attach docs → flow to GL," this is the most urgent finding in the whole audit. It is a *correctness/authz* breakage (fixable in days), not an architectural one like the spine.

---

# PART B — Web back-office (added 2026-06-27 after owner feedback)

> The owner flagged that the **web dashboards, treasury payments, repositories, and especially expenses** matter as much as the POS. This part covers them. Two focused read-only passes (expense flow end-to-end; web treasury surfaces), with the load-bearing authz/relation claims verified firsthand (Appendix).

## 9. Web treasury surfaces — capability map

All five surfaces are routed and in the sidebar (except payment-methods). "Wired" ≠ "complete."

| Surface | State | Headline gap |
|---|---|---|
| **Repositories** (cash reg / bank / safe) | **Partial** | Create can leave `gl_account_id` NULL (the B2B-GL breakage, F7); no edit/deactivate; no "missing-GL" column on the list |
| **Payments** (record / allocate / refund / reverse) | **Functionally complete** | But **no journal-entry view** to confirm GL; no customer-vs-supplier toggle; **float FIFO allocation** (rule 19) |
| **Payment methods** | **Partial** | Create + activate-toggle only (no edit/delete); **can't link a method to a repository/GL**; **not in the sidebar nav** |
| **Bank reconciliation** | **Partial** | UI works but **manual entry only — no statement import**; duplicate untranslated toasts |
| **Cash-position dashboard** | **Missing** | Owner dashboard = sales-tender pie + per-shift variance; **no live cash/bank balance widget** (F10) |

### Findings (web treasury)

| # | Finding | Sev | Class | Evidence |
|---|---|---|---|---|
| **W1** | **Repository create form makes the GL account optional → can create a cash register/bank with `gl_account_id` NULL** (the precondition that breaks B2B payment GL, F7) | High | **CUTOFF\*** | `AddRepositoryModal.tsx:216` (no `required`), `:140` (`gl_account_id: data.gl_account_id \|\| null`); GL editable later on detail `RepositoryDetailPage.tsx:104-204` w/ warning `:188-190` |
| **W2** | **No journal-entry/GL view on a payment** — back office can't confirm a payment posted to the right accounts | Med | DEFERRED | `PaymentDetailPage.tsx:389-598` (amount/method/allocations/refunds only) |
| **W3** | **Float math on money in the payment-create FIFO allocation** (amounts POSTed are float-rounded) | High | DEFERRED | `PaymentForm.tsx:295-323,178,269,432`; the standalone `PaymentAllocationForm.tsx:17,63-78` correctly uses `bc*` |
| **W4** | **No customer-vs-supplier direction in the payment create form** — single `/partners` dropdown, direction inferred server-side | Med | DEFERRED | `PaymentForm.tsx:227-236` |
| **W5** | **Repositories can't be edited/deactivated/deleted** from the UI; only the GL-account PATCH exists | Med | DEFERRED | `RepositoryDetailPage.tsx:115-117` |
| **W6** | **Payment methods: no edit/delete; can't link to repository or GL; page not in nav** | Med | DEFERRED | `PaymentMethodsPage.tsx:170-184`, `AddPaymentMethodModal.tsx` (no repo/GL field), `Sidebar.tsx:257-262` |
| **W7** | **Bank reconciliation has no statement import** — single typed `statement_balance`, one-sided matching only | Med | DEFERRED | `BankReconciliationPage.tsx:134-144` |
| **W8** | **Hardcoded English toasts (rule 11)** in reconciliation + smart-payment hooks → duplicate untranslated toast | Low | DEFERRED | `useReconciliation.ts:121,156,189,222,248`; `useSmartPayment.ts:141` |
| **W9** | **`PaymentDetailPage` formats money as `en-US` / `USD` fallback** — wrong for TN | Low | DEFERRED | `PaymentDetailPage.tsx:281-287` |

\* **W1 is CUTOFF only if the demo/customer creates repositories by hand.** Seeded demo repos already have `gl_account_id` (the null risk is `account_id`, F7). Fix is small: make the GL select required, or default it.

**What works well (no action):** payments record/allocate/refund/reverse/cancel are all wired to real endpoints (`PaymentForm.tsx:327`, `PaymentDetailPage.tsx:172-249`); repository list shows **live balances** grouped by type + grand total (`RepositoryListPage.tsx:182-247`); bank-rec match/complete/cancel works; GL account is editable on the repo detail with a missing-link warning.

## 10. Web dashboards — treasury reality

Confirms **F10**. `OwnerDashboardPage.tsx:81-104` renders sales summary/trend/by-location, a revenue-by-category donut, a **payment-method-breakdown pie** (sales *tender mix*, not balances — `PaymentMethodBreakdownPie.tsx:27`), top SKUs, low-stock, and a **per-shift cash-variance table** (`CashRegisterReconciliationTable.tsx:18-37`). There is **no** widget for live cash position per shop, bank-vs-cash split, repository balances, or expense outflows. Repository balances live only in the treasury module's `RepositoryListPage` — not on any dashboard, no time series, no per-shop position. **The owner's "how much cash is in each shop / the bank right now?" question is unanswerable from the dashboard.**

## 11. Expense flow — the owner's stated requirement (log → label → attach → GL)

**Verdict: not usable end-to-end. None of the four capabilities work properly today.**

| Capability | Verdict | Why |
|---|---|---|
| **1. Log an expense** | ⚠️ Write-only | `POST /expenses` succeeds (and enforces **no** permission — E5), but you cannot open it back (E1/E3). |
| **2. Label / categorize** | ⚠️ Broken in UI | Category model has a GL-account link, but **no UI to set it**, **no seeded categories**, and category view/update/delete **403** (E1). |
| **3. Attach documents** | ⚠️ Upload-only | Upload endpoint + FE drag-drop work, but the expense **detail 500s** (no `attachments` relation, E3) so you can't see what you attached. |
| **4. Flow into the GL** | ❌ Impossible | `POST /expenses/{id}/post` **403s for every user incl. `admin`** (E2). Even if unblocked: no treasury-balance move (E4), no input-VAT split (E6), no payable path (E7). |

### Findings (expenses)

| # | Finding | Sev | Class | Evidence |
|---|---|---|---|---|
| **E1** | **All-deny policies: `DocumentPolicy` & `ExpenseCategoryPolicy` return `false` for every method (no `before()`)** → expense view/update/delete + all category view/update/delete **403** for everyone | **Critical** | **CUTOFF** | `app/Policies/DocumentPolicy.php:13-64` (all `return false`); `ExpenseCategoryPolicy.php:13-64`; routes carry no `can:`/`module:` (`Expense/routes.php:21-28`); Spatie `Gate::before` only matches a *literal* ability name, seeded perms are dotted (`expenses.view`), so the policy decides |
| **E2** | **Posting an expense to the GL is impossible via API** — `Gate::authorize('post',$expense)` → policy has no `post` method → 403; `admin` (all perms) included | **Critical** | **CUTOFF** | `ExpenseController.php:214`; `DocumentPolicy` (no `post`); `RolesAndPermissionsSeeder.php:395` (`admin` perms are dotted, not literal `post`) |
| **E3** | **Expense detail 500s** — controller eager-loads `'attachments'` but `Document` has only `expenseMetadata()`, no `attachments()` relation | **Critical** | **CUTOFF** | `ExpenseController.php:132`; `Document.php:290` (only `expenseMetadata`); real model is `Media/Domain/Media/MediaAttachment` (polymorphic) |
| **E4** | **Posting an expense never moves the treasury repository balance and creates no `Payment` row** (GL cash account and repo balance drift) | High | **CUTOFF** (correctness) | `GeneralLedgerService.php:2047-2122` (balanced JE only); no balance mutation in `app/Modules/Expense` |
| **E5** | **Create/list enforce NO permission; the seeded `expenses.*` permissions are dead** (BE uses broken policy, FE gates on `treasury.*`) — authz model mismatch | Med | **CUTOFF** | `ExpenseController.php:34,92` (no `Gate::authorize`); `RolesAndPermissionsSeeder.php:155-159,416,495,595,622` |
| **E6** | **No VAT / timbre on expenses** — booked 100% TTC to one charge account; TN input VAT (TVA déductible, 4366) never split | Med | DEFERRED | `ExpenseMetadata.php:46-55`, `ExpenseRequest.php:46-74` (only `total`); `ExpenseService.php:40-41` (`subtotal=total`); GL `2095-2103` |
| **E7** | **Unpaid expenses still booked as immediate cash/bank out** — `is_paid` ignored by GL, no AP/payable path; `create` forces `is_paid=true` | Med | DEFERRED | `GeneralLedgerService.php:2071-2076`; `ExpenseService.php:51,103-113` |
| **E8** | **No seeded expense categories; category→GL `account_id` not settable in the UI** → all expenses fall back to one generic charge account (TN `65`) | Med | DEFERRED | no `ExpenseCategory` seeder; `ExpenseCategoryPage.tsx` (name/desc/parent/active only); fallback `GeneralLedgerService.php:2054-2068`, `TunisiaChartOfAccountsSeeder.php:210` |
| **E9** | **No backend tests for the Expense controller/service** — why E1–E3 went unnoticed | Low | DEFERRED | no `*Expense*Test*.php` under `app` |

**Precision — COMPLIANT in the expense path:** `Document.total` is `decimal:3`, `ExpenseRequest` validates with the scale-3 ceiling regex, GL uses the numeric-string `total` directly, FE uses `MoneyInput`. No float-on-money drift here.

**Data model (reference):** an expense = a `documents` row (`type=Expense`, `status` draft/posted, `total/subtotal/currency`) + an `expense_metadata` side-car (`expense_category_id`, `payment_method_id`, `payment_repository_id`, `payment_date`, `is_paid`, `receipt_number`, `vendor_name`). `expense_categories` are hierarchical and carry an optional `account_id → accounts` (the GL link). The *model* supports labeling, a paid/unpaid lifecycle, and per-category GL mapping; the *wiring* (policies, relation, UI, posting) is what's broken.

### Back-office cutoff (per the owner's stated requirement)

If "log expenses → label → attach → GL" must work for go-live (the owner says it must), these are **CUTOFF** and are *correctness fixes, not features* — most are small:

| ID | Fix | Effort |
|---|---|---|
| **BCUT-1** | Make expense authz real: give `DocumentPolicy`/`ExpenseCategoryPolicy` actual `view/update/delete/post` logic against the seeded `expenses.*` permissions (or add `can:expenses.*` / `module:` middleware on the routes and drop the all-deny policy). Fixes E1, E2, E5. | **S–M** |
| **BCUT-2** | Add the `attachments()` relation to `Document` (polymorphic → `MediaAttachment`) so the expense detail stops 500-ing and shows uploaded receipts. Fixes E3. | **S** |
| **BCUT-3** | On expense post, decrement the `payment_repository.balance` (and/or write a `Payment` row) so cash-on-hand matches GL. Fixes E4. | **S–M** |
| **BCUT-4** | Surface the category→GL `account_id` picker in the category UI + seed parapharmacy/TN expense categories with sensible GL accounts. Fixes E8 (and makes E6/E7 tractable). | **S–M** |
| **BCUT-5** *(if scope allows)* | Split input VAT (TVA déductible) on expenses and honour `is_paid=false` as an AP liability. E6, E7. | **M** |

---

## Appendix — method, caveats, conflict resolution

- **Method:** six parallel read-only exploration passes total — Part A: POS tender frontend; drawer/shift/X-Z; Treasury payment model + tolerance; supplier/expense/refund/reporting. Part B: expense flow end-to-end; web treasury surfaces. Each returned `file:line` evidence; the load-bearing claims below were verified firsthand.
- **Verified firsthand (not taken on agent trust):**
  - Demo terminals are `fiscal_schema_version = 3` → the v3 device-authoritative path is live (`DemoPharmacySeeder.php:505`, `ParapharmacyMultiBranchSeeder.php:592`).
  - No return/refund projection bridge exists (Treasury bridges `handlesEventType` cover only SALE_RECEIPT / ACCOUNT_PAYMENT / ACCOUNT_CHARGE / DEPOSIT_RECEIPT).
  - **`account_id` conflict resolved:** `PaymentController` reads `account_id` (gate `:343`, GL `:579+`); POS reads `gl_account_id`; seeder + both backfills populate only `gl_account_id` → `account_id` is NULL on demo data. The code is logically wired (Agent 4) **and** broken on demo data (Agent 2) — both true, reconciled.
  - Server tolerance auto-accept lives on the **410-retired** `ReceiptPaymentService`, not the live `TreasuryReceiptBridge`.
  - **Expense 403-for-everyone (E1/E2) confirmed:** `DocumentPolicy` returns `false` for all methods with no `before()`; there is **no `Gate::before` super-admin bypass anywhere in `app/`**, no `AuthServiceProvider`/`$policies` map; Laravel's default policy guesser resolves `App\Policies\DocumentPolicy` for the modular `Document` model; even the `admin` role (`syncPermissions(Permission::all())`) holds only dotted `expenses.*`, which Spatie's literal-name `Gate::before` does not match. `Document` has no `attachments()` relation (E3). `index`/`store` skip `Gate::authorize` (E5).
- **Caveats / not exhaustively traced:** every `onQueue()` callsite vs `horizon.php` (rule-20 guard exists); whether the operator-created `PaymentRepository` web flow sets `account_id` (only seeded data confirmed null); cross-terminal refund variance attribution was reasoned, not executed; the expense 403/500 conclusions are from code path + policy resolution, not a live HTTP request. No code was run; no migrations applied; full PHPUnit suite not run.
