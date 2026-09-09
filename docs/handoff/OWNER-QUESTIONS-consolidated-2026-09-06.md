# Consolidated open owner questions — 2026-09-06

Compiled by the orchestrator from every ledger (first-tenant launch program, sessions B/H/J/L/N, ES remediation, request-hygiene, parapharmacy remediation). Answer inline; the new parallel-work session reads this file. Rows marked **[benchmark first]** are ERP-behaviour questions: per the 2026-09-05 standing rule the new session attaches an Odoo/ERPNext/Dolibarr benchmark before you rule; you may still pre-state a lean.

## A. Decisions that change what gets built

| # | Question | Where it came from | Rec. / note | Ruling |
|---|---|---|---|---|
| A1 | **Precision widening before go-live?** `journal_lines.debit/credit` and `payment_repositories.balance` are `decimal(15,2)`; TND money is scale 3. Own lane, non-additive push, host backup first. | ticket 2026-09-06 (W-CASH-1 gate r3) | Yes, before tenant #1 go-live | |
| A2 | **K-1 dispatch authorization**: shared money-tail P0s on POS account charge (VAT-on-VAT, missing stock movement, ungated PurchaseHub). Spec gated r3 ACCEPT-WITH-CONDITIONS, dispatch-ready since 2026-08-30. | Otospex readiness audit | Dispatch after W-LOT-B consumption slice lands (same projection file), or now and accept a merge | |
| A3 | **Today's-Sales ruling** (option 4 recommended in the DPA ledger). | document-per-action remediation | **[benchmark first]** | |
| A4 | **Orphan-branch authorization**: dpa-v8 supplier return, r2f2 invoice-cancel UX, r2f4 correcting documents. | DPA remediation | **[benchmark first]** | |
| A5 | **Accountant role scope on POS data**: `pos.view_receipts` only, plus `pos.view_reports`, or more. | ES remediation OP-09 | **[benchmark first]** | |
| A6 | **Party model OQ10 + D-1..D-4 veto** (backfill "business" arm a/b, default a). Deferred 2026-08-29. | Session H | Keep deferred until Phase 2 dispatch, or rule now | |
| A7 | **Enforcement P2 quiet-window ack** + preliminary notice to the team. | enforcement layer | Ops decision | |
| A8 | **Documents-numbering scope**: ruled per-company 2026-08-29; confirm it stands for Expense/Income allocators too. | Session I/J | Confirm | |

## B. External parties

| # | Question | Owner of the answer | Ruling / status |
|---|---|---|---|
| B1 | **Expert-comptable Q1**: credit-note stamp, GL versus lettrage (blocks CN GL certification). Q2/Q3 answered 2026-08-06. | expert-comptable | |
| B2 | **Tunisia accountant review** of the client's item tax classifications, receipt/invoice content per establishment (000/001… suffix on documents?), rounding, configured books. | client's accountant | |
| B3 | **E-4 legal position** on support impersonation (blocks impersonation usage, merged since 2026-08-08). | TN legal | |
| B4 | **Sister-company fact**: confirmed one legal entity with establishments. Does each establishment need its own document numbering series or fiscal identifiers on receipts? | client's accountant | |

## C. Owner gates and runbooks (paper, no code)

| # | Item | Status |
|---|---|---|
| C1 | E-1..E-9 gate-sheet initials (E-10 pre-decided: production after staging campaign) | blank cells |
| C2 | E-7 refund evidence sign-off (payout-cash-bound analysis) | owed |
| C3 | E-9 staging runbook execution + daily check (dead-lettered projections + refund_policy_alerts) | owed |
| C4 | O-30 orphaned-shift runbook | owed |
| C5 | O-31 parity pinned blob (enum-check parity) | owed |
| C6 | O-35 ON_PUSH preconditions | owed |
| C7 | O-36 on-device smoke for H a1 buyer (blocks a1 promotion) | owed |
| C8 | O-37 Session H Phase 2 dispatch (after O-36) | owed |

## D. Environment facts only you can check (Dokploy / staging)

| # | Check | Why |
|---|---|---|
| D1 | `TENANCY_DB_PER_TENANT` set in the staging API environment? | if unset, boot tenant migrations are a no-op (manifest U-2) |
| D2 | Staging = compose stack or separate Dokploy apps; API app id | manifest U-1/U-3 |
| D3 | `SYNC_PERMISSIONS_ON_BOOT` set in Dokploy? | manifest U-6 |
| D4 | Staging Dokploy up? (was down at the 2026-08-26 checklist) | promotion |
| D5 | Staging tester login for the second-company import retest; `units_not_seeded` 422; F-BUG-1 retest | Session J relay list |
| D6 | Redis per-container probes + `permission:cache-reset` (T9 precondition) | request-hygiene |
| D7 | HTTPS origins confirmed for `crypto.randomUUID` | request-hygiene |

## E. Team

| # | Item |
|---|---|
| E1 | Dhouha's DEV-QA registry is not in the repo — ask for it |
| E2 | erp-mobile: 4 physical/manual items owed + production census re-run |
| E3 | Tomorrow's on-site plan: real-device POS campaign (Tauri/Windows/printer/offline, two terminals) — who, which device build |

## Owner answers (2026-09-06 evening) and orchestrator notes

| # | Owner answer | Orchestrator note / consequence |
|---|---|---|
| A1 | Precision is a **country preset**, never hard-coded (EU 2, Tunisia 3). Confirm implementation. | **Confirmed this is already the model**: `CurrencyScaleResolver` reads `countries.currency_decimal_places` (seeded per country; `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:31-40`). The widening lane changes only the **storage capacity** of GL and repository columns from `decimal(15,2)` to `decimal(15,3)`, which is a floor that fits both 2- and 3-decimal countries; display and rounding precision stay the country setting. Nothing new is hard-coded. Widening lane: yes, before go-live. |
| A2 | K-1: dispatch now. | New session dispatches K-1 (Codex Desktop, own worktree); merge conflict risk with W-LOT-B consumption slice accepted. |
| A3 | Today's-Sales: benchmark, then implement. Keep documents-sales vs POS-sales separation. | New session: benchmark note → owner lean → implement. |
| A4 | Orphan-branch authorization: benchmark, then decide. | Explained: three DPA leftovers where a document branch (supplier return without PO, invoice cancel from the UI, correcting documents) has no ruled authorization path. New session benchmarks all three. |
| A5 | Accountant role: start with view receipts + view reports; benchmark; configurable, never hard-coded; expand later per customer. | New session: benchmark → seed `pos.view_receipts` + `pos.view_reports` on accountant; permissions stay editable in the role surface. |
| A6 | Needs explanation. | **A6 = party (customer/supplier) model, Session H.** OQ10 asks how to classify pre-2026-03-09 partners as "business" during a backfill: (a) acknowledge the old flag verbatim, or (b) trust the category only for rows created after 2026-03-09. D-1..D-4 are four orchestrator decisions you may veto: a persisted `credit_account_enabled` boolean, region-tagged locales (`fr-TN`), sequencing of the device hash-seed change, withholding fields organization-only. No production tenant predates March, so OQ10 is moot for tenant #1. **Recommendation: keep deferred; accept D-1..D-4 as they stand.** |
| A7 | Needs explanation; why a quiet window? | **A7 = enforcement package P2 ("CI runs the existing guards").** Its merge changes the CI contract (`ci.yml` lanes), so a quiet window meant "no other lane merges CI changes during that merge" to avoid a three-way conflict; the ack was you acknowledging the scope notice. **Per the memory index P1/P2/P3 landed and were promoted (`c6d6308ae`), so this item is stale and closed.** |
| A8 | Numbering per company; benchmark before final. | Benchmark in the new session (Odoo/ERPNext/Dolibarr sequence scoping); confirm Expense/Income allocators. See B4 for establishments. |
| B1 | Credit-note stamp: benchmark; stamp is non-recoverable; in Tunisia it should be an **option** to add it on the CN, and if added it is not recoverable. | Benchmark + expert-comptable Q1 stays open; implementation = tenant/country setting `credit_note_applies_stamp` default per country, stamp line non-recoverable when present. |
| B2 | System is configurable; everything in seeded settings the accountant can adjust at start; some settings lock after first use. | Already the model (country seeders). New session lists which settings lock after first use and where the accountant edits them. |
| B3 | Impersonation: **allow**, properly logged; needed for support. | Ruled. Unblocks the merged impersonation feature; audit log already required by its spec. |
| B4 | One legal entity with establishments; benchmark; expectation: each establishment has its **own sequential numbering**, possibly with a prefix. | Benchmark (Tunisian facture/receipt numbering per établissement; Odoo/ERPNext sequence per journal/branch). Likely: numbering series per location with a location prefix, sequential per series. Feeds A8 and the W-CASH/W-LOT briefs' second-location fixtures. |
| D1–D7 | DB per tenant is how we go live and what staging runs; details confirmed tomorrow by Khalil (Dokploy access). | Team checklist `docs/handoff/TEAM-CHECKLIST-staging-facts-and-hardening-2026-09-07.md`. |

## Owner follow-up (2026-09-06, later)

| # | Ruling | Consequence |
|---|---|---|
| A1 | Proceed. **Widen money storage to 4 decimals maximum** (`decimal(N,4)`), because some currencies and accounting practices need 4; precision used remains the country preset. | Precision lane widens `journal_lines.debit/credit`, `payment_repositories.balance`, `last_reconciled_balance` to `decimal(15,4)` (not 3); the precision contract doc is updated so the **money storage floor becomes scale 4** while display/rounding stay country-resolved; the census + round-trip tests use `1.0005`. W-CASH-1 P0 prerequisite must say (15,4) — carried into its next fix round. Any other money column still at (15,2) or (15,3) is listed by the census; only the four named columns are widened in this lane, the rest become a follow-up ticket. |
| A6 | **No backfill.** Greenfield; fix the demo-account seeders only. OQ10 closed; D-1..D-4 stand. | Session H Phase 2 brief: remove the backfill arm; add a seeder fix for demo tenants. |
| A7 | Resolved (already promoted). | Closed. |

## Rows added by the parallel hardening session (2026-09-07, autonomous window)

| # | Question | Where it came from | Rec. / note | Ruling |
|---|---|---|---|---|
| A9 | **PR #210 (F-W2-14, cashier supplier-invoice perms): may the `operator` role lose supplier-invoice create/match/post?** The PR gates posting behind a dedicated `supplier-invoices.manage` permission; `operator` holds `documents.update` but is not granted the new permission, so it silently loses an API-only capability (the web UI never exposed supplier invoices to operators: purchases routes are gated on `purchases.view`, alias limited to admin/purchases/manager). Gate r1 `docs/superpowers/reviews/2026-09-05-dhouha-pr-210-gate-r1.md` = CHANGES + owner ruling; also F-W2-14 is only ⅓ closed (PO revert + POST /payments cashier holes remain) and deploy needs `tenants:seed RolesAndPermissionsSeeder` + `permission:cache-reset`. Worktree `.worktrees/pr-210` kept. | request-hygiene session 2026-09-05 | Recommend: accept the secure default (operator loses it; nobody could reach it in the UI), grant `supplier-invoices.manage` to `manager`/`purchases`; then fix round for the remaining ⅔ of F-W2-14 | |
| A10 | **Precision lane assumption A: Eloquent casts stay `decimal:3` after storage widens to `decimal(15,4)`** (writers format at the country preset, max 3 today; 153 test assertions compare 3-decimal literals). A "casts follow storage" lane opens when a 4-decimal country preset is seeded. Brief: `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` §1. | precision-4 lane | **Benchmark note** `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md`: Odoo/ERPNext/Dolibarr all store GL amounts wider than operational precision; none narrows on read; NC 01 §62 forbids rounding in recording, allows it only for presentation; no 4-dp country preset exists (0/40); 4 dp in Tunisia sourced only for FX rates and intermediates (already scale 6 / scale+4), VAT prorata is 2 dp; 192 strict test assertions would break under casts→4. RECOMMENDED: (A) casts stay `decimal:3` **conditional on** the P0-a census shipping the `fourth_decimal_present` detector; revisit (all 19 casts at once) when a 4-dp preset, a 4-dp SCALE_MAP entry or a non-zero census appears. Owner 2026-09-07 chat: accepted A provisionally pending this benchmark. | |
| A11 | **Parapharmacy with Sales OFF: POS charge-on-account produces no B2B facture draft.** `DocumentAccountChargeFactureBridge` already required the `Sales` module; once Sales is a default-off extra for parapharmacy (lane sales-extra, Q6/D2), a POS account charge still posts GL via `TreasuryAccountChargeBridge` (the ONE GL surface per K-1 ruling C1, 2026-08-31) but no printable facture draft is created for that tenant. Is that acceptable for tenant #1 (cash-only launch, B2B off), or must the facture draft be produced independently of the Sales module? | sales-extra lane handback §7 | Recommend: acceptable at launch (facture is non-GL printable; activating the Sales extra restores it); revisit when a parapharmacy tenant needs B2B invoices | |

## T-2/T-3 spec rulings (owner, 2026-09-09 chat) — `docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md`

| Q | Ruling | Consequence for the spec fix round |
|---|---|---|
| D1 reconciliation / expected quantities gated on new `inventory.transfers.reconcile`, not `inventory.view` | **ACCEPTED** | Seed to manager (+ admin via all); grantable per role/user |
| D2 discrepancy reason required only when damaged > 0; shortness computed server-side, reasoned by the supervisor at close | **ACCEPTED**; owner note: later allow the receiver to add their own explanation after the quantities are received (post-receipt note, does not reveal expected) | Add "receiver note" as a ticketed follow-up on the receipt line (nullable text, written by the receiver after submit, never gating) |
| OQ-1 remainder physically returned to source | **INCLUDE `disposition: write_off \| return_to_source` on close**. Owner on GL: "probably no GL entry; research + benchmark industry standards first" | Benchmark note owed (Odoo/ERPNext/Dolibarr: return-to-source = stock movement back, no P&L; shrinkage/damage = expense account) → spec §6 cites it |
| OQ-2 replenishment request after a write-off close | **Leave settled; notify processors (T-4); requester re-raises** | As drafted |
| OQ-3 `partially_received` visible to a blind receiver | **ACCEPT the coarse signal** | As drafted |
| NEW requirement (owner): pattern detection | **Every receipt/close must be a STORED event carrying receiver identity + per-line sent/received/damaged/reason/blind flag** so analytics can detect e.g. an employee who abnormally often mis-receives | Spec §4 must move per-line discrepancy facts INTO `StockTransferReceivedV1` (or a companion `StockTransferReceiptLineDiscrepancyV1`), not defer them to `StockMovementRecordedV2`; add an analytics read model / query in scope note (T-4 or later) |
| PR #210 (gate r3): accountant denied PO revert by default (grantable), alongside operator | **OPEN — owner line requested** | |
