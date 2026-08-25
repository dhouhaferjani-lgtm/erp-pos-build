# OWNER SHEET — Session B2 (state-machine fixes, continuation) — 2026-08-25

> Everything below is on LOCAL dev only (S-17: CI-blind, Session A promotes). One place for every owner item
> Session B / B2 owes you; the LEDGER row is the authority, this sheet is the reading order.

## 1. Rulings needed (blocking something)
| # | Ruling | Blocks | Where |
|---|---|---|---|
| **O-32** | JE + income numbering contract: since C-27 (`0613e2ce8`) `JE-YYYY-NNNNNN` / `INC-…` are ONE sequence per tenant-year — companies interleave, each company's register shows gaps (expenses already do, since Q-11). (a) accept interleaving (shipped, reversible), or (b) schedule a per-company unique `(tenant_id, company_id, entry_number)` (migration on the hottest fiscal table; `entry_number` is externally quoted). FEC unaffected (`FecExporter` mints its own `EcritureNum`). | nothing today; multi-company provisioning marketing | LEDGER O-32 |
| **O-31** | Arm the parity anti-growth ceiling: repository variable `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf` + tag `ci-pin/enum-check-parity-r1` at that commit, AFTER the promotion that carries `19f9e61bb` and BEFORE the S-14 ci.yml leg. Full steps + blob verification in `docs/handoff/OWNER-O31-enum-check-parity-pin-2026-08-25.md`. | matched-growth protection for every Slice D batch after batch 1 | LEDGER O-31 |
| **O-30** | Forced terminal release orphans an OPEN shift — runbook step (SQL in the row) + ruling on a first-class orphan-close surface. Non-waivable before the first forced release in production. | first forced release | LEDGER O-30 |
| Q-11 ack | Expense numbers interleave across companies (same contract as O-32; already shipped `0f775b6f7`). | — | Q-11 gate cond. 2 |

## 2. Promotion owes that ride Session B/B2 merges (Session A executes; you sign the checklist)
- **S-21** C-27 fleet census per tenant (Q-11 gate §C queries #1–#3 + `INC-`) — no repair implied; the local run was NOT a fleet census (no `tenant_*` DB on 5433).
- **S-19** one `grep -E 'status=(BLOCKED|FAILED)'` on the Q-7 token after `tenants:migrate`.
- **S-20** re-run `RolesAndPermissionsSeeder` + permission cache reset (`fiscal-periods.reopen`; `fiscal-periods.close` joins it when C-24 lands).
- **S-14 leg** (workflow edits): wire `EnumCheckParityTest` + liveness into `treasury-spine-pgsql` / `backend-architecture` — hand-place the hunk from `REPORT-C26-implementer.md` §5 with the C-37(i) corrections (sqlite-skip grep, job name, anchors); plus the `backend-pgsql` allowlist appends named in the register rows.
- **S-22** Slice D batch 1 is MIGRATION-BEARING: `tenants:migrate-rolling --force` (never bare `tenants:migrate`), 13 pre-flight census queries + the `convalidated` post-flight query in `REPORT-D2-implementer.md`; in-migration abort protects the fleet (green-field ruling 2026-08-24 applies). NOTE for §R-D rulings: the 13 enum sets are now frozen in the DB — deleting a dead case is a narrowing migration (LEDGER C-40).

## 3. Program spec §R — ratification questions STILL OUTSTANDING (none ruled as of 2026-08-25)
Source: `docs/superpowers/specs/2026-08-23-state-machine-program-spec-skeleton.md` §R. The program does not start
without these; Session B's erratum applies (§#26 census superseded by D-1: 245 tenant columns, baseline 188 → 176 after batch 1).
- **R-A graphs to sign:** R-A1 POS Order (retire `Closed`? un-bump edge? Cancelled terminal) · R-A2 OrderLine (`pending→sent`, `ready→served`, Cancelled terminal) · R-A3 Document (`Paid→Cancelled`? `Received` terminal? `revert` reach) · R-A4 Voucher (ratify Q-5 allow-list; Expired reactivation?) · R-A5 Import (`Pending` legal start?) · R-A6 Replenishment (`Fulfilled→Pending` compensating edge) · R-A7 FiscalPeriod (ratify Q-10 reopen + the C-24 manual close; `Locked` terminal-except-support) · R-A8 `pos_receipts.fiscal_status` sync-state split.
- **R-B:** R-B1 no Treasury/Inventory workstream (Slice D only) · R-B2 workstream B absorbs C-8 trigger widening · R-B3 TableStatus/F&B deferred to the Dhouha track.
- **R-C guard shape:** R-C1 one map-driven PHPStan rule · R-C2 `status` out of `$fillable` on governed aggregates (breaking sweep).
- **R-D dead-state cleanup (implement vs delete):** R-D1 AssignmentStatus::Overdue · R-D2 Voucher/Coupon Expired + expiry engines (GL breakage income = country-seeded policy) · R-D3 JournalEntryStatus::Reversed · R-D4 SignatureStatus · R-D5 TenantStatus default/reactivate/Archived · R-D6 bank_reconciliations + `payments.is_reconciled` · R-D7 DeviceLossIncident recovery · R-D8 PaymentStatus dead cases + default · R-D9 Stripe mapping fail-closed.
- **R-E docs/structure:** R-E1 Active-Record-in-Domain ratified vs deptrac layer · R-E2 DS-3 bigint won't-fix · R-E3 `composite_items.tax_rate` string→decimal as P2 · R-E4 `'TND'` fallback → country-defaults authority.
- **R-F process:** R-F1 ratchet/deptrac deltas pre-agreed per workstream · R-F2 transitions table vs column-audit per noun · R-F3 DS-1 treasury FK lane go/no-go (8/9 FKs shippable — `journals` table does not exist, C-30).

## 4. What B2 shipped (for the record)
| Lane | Merge | Gate |
|---|---|---|
| B2-1 C-27 JE/income numbering tenant scope + lock (multi-company block LIFTED) | `0613e2ce8` | stock-gl r1→r2 ACCEPT-w/-cond · treasury r1 ACCEPT-w/-cond |
| B2-2 C-26 parity parser + owner-pin reader | `19f9e61bb` | fiscal-pos r1 ACCEPT-w/-cond |
| B2-3 O-31 seed + steps | doc `OWNER-O31-…` | parent-verified |
| B2-4 Slice D batch 1 — 13 CHECKs, baseline 188→175 (MIGRATION-BEARING → S-22) | `42f7f8bad` | fiscal-pos r1 + treasury r1 ACCEPT-w/-cond → fix round |
| B2-5 C-24 manual period close | _queued_ (brief + worktree ready) | treasury |
