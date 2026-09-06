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
