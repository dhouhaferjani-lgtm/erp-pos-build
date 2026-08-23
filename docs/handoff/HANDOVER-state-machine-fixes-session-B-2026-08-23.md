# HANDOVER — Session B: state-machine & data-structure fixes (2026-08-23)

> **You are Session B.** Session A (the first-client session, 0578e8d8 continuation) is LIVE in
> parallel on this same machine and repo, finishing its merge wave, then running the Playwright
> first-tenant campaign and the owner-authorized promotion. This handover defines your mandate,
> your queue, and the collision rules. Where this document conflicts with LEDGER.md, LEDGER wins.

## 0. Owner intent (verbatim framing, 2026-08-23)

The audits' purpose was to check **whether the data structures are sound** — status lifecycles
held in bare string columns/enums with no adjacency map, no DB constraint, and no single write
path are a long-term liability the owner wants caught and corrected. Ruling:
**fix whatever can be fixed quickly NOW; plan the rest to be worked through as the first
customer lands.** Not bound to the VPS; everything runs here. Time pressure is real:
launch-path correctness outranks structural elegance, but structural lessons that must be
learned before tenant #1 get learned now even if tenant #1 won't hit them.

## 1. Input documents (all committed on local dev, read in this order)

1. `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` — dispositions of the VPS session's
   21 findings; the FOLD-NOW queue F1–F6; the program charter sketch; the errata.
2. `docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md` — the parent sweep: findings
   #22–#30, the corrected census (85 enums / 90 columns / **76 without CHECKs** / 4 adjacency
   maps / 1 bypassable PHPStan guard), charter amendments, merge-sequencer collisions.
3. `docs/handoff/AUDIT-state-machine-sweep-sub-reports-2026-08-23.md` — the six sub-sweep
   reports VERBATIM (Treasury / Inventory / Fiscal+device / POS-till / Import+opening /
   tenancy+doc-adjacent). This is where the file:line evidence lives.
4. `docs/handoff/AUDIT-state-machines-vps-2026-08-23.md` — the original VPS audit (read
   through the triage's corrections; 8 of its findings carry 13 material errors).
5. `docs/handoff/RETRO-partner-party-drift-2026-08-23.md` §5 — the guardrail idioms this repo
   uses (ratchets, PHPStan rules with fixture tests, `docs/conventions/08-DETECTOR-LIVENESS.md`).

**The three good in-repo precedents to copy** (do not invent new shapes):
- Workshop: `StatusMachine` + `WorkOrderTransitionService` + typed exception + transitions
  table + the PHPStan rule `WorkOrderStatusWriteOnlyViaTransitionService` — **but see parent
  sweep #27: the rule as written misses `update()`/`fill()` writes and `status` is fillable.
  Fix the guard shape BEFORE propagating it.**
- Treasury instruments: the full four-element pattern incl. the `instrument_events` append-only
  register with DB immutability triggers.
- `CustomerAccountStatusService` (Partner): lock + adjacency map + versioned audit columns +
  fiscal event — the lightest complete implementation.

## 2. Operating rules (identical to Session A; non-negotiable)

- **Model posture:** you orchestrate; Opus implements; specialist reviewer agents gate
  (`fiscal-pos-reviewer`, `treasury-reviewer`, `inventory-costing-reviewer`,
  `tenancy-authz-reviewer`, `stock-gl-interaction-reviewer`, `imports-reviewer`). Every lane:
  brief → red-first implementation in a **dedicated worktree off dev** → adversarial gate →
  fix rounds → merge only on ACCEPT (or gate-sanctioned parent verification for doc-only rounds).
- **Never** run the full PHPUnit suite. Tests BY PATH. PG runs: `DB_PORT=5433` + throwaway DBs.
  Worktree gotchas: copy `vendor/` (real copy) + `.env` from the main checkout, verify class
  resolution via `ReflectionClass::getFileName()` → worktree. No `git stash`, ever.
- **S-17 CI-blind window is armed**: GitHub Actions quota is out, Dokploy is down. Every merge
  is recorded CI-UNVERIFIED with local evidence. **Never push origin/dev** — promotion belongs
  to Session A exclusively, under the owner's conditional go.
- **Post-merge verification protocol (updated 2026-08-23 after a drift incident):** after EVERY
  merge to local dev run (a) the lane's tests by path on merged dev, (b)
  `php apps/api/tools/feature-lane-manifest-check.php` (must be EXIT=0 — raise ceilings
  deliberately with attribution notes, never silently), (c) lint ratchets if web/pos touched.
- **Record-keeping:** append a row to the S-17 merge register in
  `docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md` §E at every merge; update
  LEDGER.md rows you discharge or create. MIGRATION-BEARING lanes must note it in the register
  (origin/dev pushes auto-run `tenants:migrate` when Dokploy returns).

## 3. Collision matrix — surfaces you MUST NOT touch (Session A owns them)

| Session A lane (in flight or queued) | Files/surfaces off-limits to you |
|---|---|
| X/Z refund-VAT (fix round) | `apps/pos/src/lib/offline/zReportService.ts`, `endOfDayPreview.ts`, `apps/pos/src/api/reportApi.ts`, `apps/pos/src/lib/reports/vatDisclosure.ts`, `ReportGenerationService.php`, `z-report.blade.php`, `apps/web/.../ZReportDetailPage*`, `apps/pos+web` `lib/i18n.ts` pos-namespace spreads |
| P0 TN-matricule regex (round 3) | `CountryTaxNumberRules.php`, `FiscalPayloadConstraintValidator` tax-number arms, `Create/UpdatePartnerRequest`, `TaxIdValidationService`, `FiscalEventEngine.ts` tax patterns, `FiscalPayloadConstraintValidatorTest` |
| Opening-batch hardening (in flight) | `AccountingOpeningService`, `ArApOpeningService`, `InventoryOpeningService`, `OpeningBalanceBatchService`, `OpeningBalanceBatchController`, any `journal_entries` opening-balance index — **the tenancy sub-sweep's `lockBatch()` hash-chain finding is ROUTED TO THIS LANE, not to you** |
| Membership offboarding (under gate) | `UserController` activate/deactivate/destroy, `PinVerifier`, `PosAuthController`, `AuthorizedManagersController`, `user_company_memberships` schema |
| B-13 manager-gate proper fix (queued in A) | `hasManagerAccess` role composition, X-report gating surfaces |
| B-4 party/contact program (A owns) | `partners`/`contacts` model changes, the a1 buyer-block fix, importer retirement |
| B-19 supplier-invoice input VAT (queued in A) | `CreateSupplierInvoiceService` tax snapshotting, `EloquentVatDataRepository` type whitelist — **but your SupplierInvoice `match()` guard item (Q-11) touches the same controller: coordinate via register check before dispatching Q-11, or hand A the finding** |
| Fiscal C-2/C-6 landed surfaces | `cartTotals.ts`, the three Z consumer blocks, F-4 tripwire semantics |

**Shared-resource rules:** (1) Before every merge: `git -C <main checkout> log --oneline -3` to
see if A moved dev; always merge from the fresh tip; never rebase/reset dev; the manifest will
conflict if both sessions raised it — resolve from ground truth (actual class counts, union
notes) and re-run the checker. (2) This laptop runs BOTH sessions: keep **≤3 concurrent
implementation agents** and stagger PG-heavy suites; kill hung vitest worker pools
(`ps aux | grep 'node (vitest'`). (3) `.worktrees/` names: prefix yours `sb-` to avoid clashes.

## 4. Your queue — Wave 1 (quick fixes, dispatch now, all gated)

Ordered by launch relevance × cheapness. Sizes from the audits; every item's full evidence is in
the sub-reports file (§ named). Each is an independent worktree lane.

| # | Lane | Source | Size | Gate lens |
|---|---|---|---|---|
| Q-1 | **Import job re-execution guard (F7/#23)**: status write BEFORE dispatch (or afterCommit), worker claims via conditional `UPDATE ... WHERE status IN ('pending','validated')`, `getValidRows()` gains `is_imported = false`. Pre-check: confirm dormant branches `fix/dpa-v2-opening-balance-import` / `fix/dpa-v6-import-deprecation` don't touch `ImportController::execute()`. | parent sweep #23 + Import sub-report | S | imports-reviewer |
| Q-2 | **Counting finalize lock + backstop (Inventory HIGH)**: `lockForUpdate()` re-read inside `finalize()`'s txn + re-assert PendingReview; partial unique on `stock_movements` for `reference_type='inventory_counting'`; ALSO the last-item submission race (lock in `submitCount`, tolerant completion transition, typed 422 instead of raw 500). | Inventory sub-report H-1/H-2 | M | inventory-costing-reviewer |
| Q-3 | **Loyalty redemption double-spend**: enrollment load inside txn with `lockForUpdate`, conditional decrement (`WHERE current_balance >= ?`, assert 1 row), `EnrollmentStatus::Active` guard, redemption idempotency key + partial unique mirroring `loyalty_txn_earn_source_unique`. | POS-till sub-report HIGH | S | treasury-reviewer |
| Q-4 | **Coupon cap enforcement**: `unique(coupon_id, receipt_id)` on coupon_usages (+ same shape on promotion_usages), txn + lock in `recordUsage`, `use_count >= max_uses` check in `validateAndCalculate`. | POS-till sub-report HIGH | S | treasury-reviewer |
| Q-5 | **Voucher void consolidation (#22/#24)**: extract `VoucherVoidService` (single write path: GL reversal entry — copy the auto-fraud void's `createVoucherLedgerEntry` call — + lock + status precondition + idempotency key); controller + `VoucherLookupService` both call it; `vouchers_status_check` CHECK mirroring `voucher_ledger_event_check`. Note the owner's document-per-action principle: the missing GL entry is the core defect. | parent sweep #22/#24 + POS-till sub-report | M | treasury-reviewer |
| Q-6 | **Voided-receipt trigger fall-through**: rewrite `prevent_receipt_modification()`'s UPDATE arm to whitelist-with-`ELSE RAISE` (template: the `fiscal_events` trigger `2026_05_14_100002:206-235`); voided = frozen (strict no-op only). MIGRATION-BEARING; per-tenant census of rows in the four unguarded states first (S-16 pattern) — expected ~0 on fresh tenants. | Fiscal sub-report HIGH #1 | S | fiscal-pos-reviewer |
| Q-7 | **Terminal-claim hardening (#25 + both sub-reports' HIGH)**: partial unique `pos_terminals (company_id, hardware_identifier) WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL`; `claim()` transactional conditional update (409 on zero rows / 23505); same guard in `requestTerminal()`; deterministic `findByDevice` ordering; explicit `release()` endpoint behind `pos.manage_terminals` + audit event; `pos_terminals.type` CHECK. **B-3 is MERGED (`457457911`) — base your lane on current dev; do not touch the pos_enabled refusals it added.** | Fiscal + POS-till sub-reports | S-M | fiscal-pos-reviewer + tenancy-authz-reviewer |
| Q-8 | **Held-order recall**: txn + conditional `UPDATE ... WHERE status='held'` (1 row) or lock; scope lookup by `terminal_id`; status CHECK on `pos_held_orders`; soft-delete in `discardOrder`. | POS-till sub-report MEDIUM | S | fiscal-pos-reviewer |
| Q-9 | **F1 kitchen/order gating + terminal-state guard**: `module:Menu` on `routes_kitchen.php` + the F&B order-workflow routes (check Dhouha's PR chain first for Menu-vs-Tables — triage §5 F1 note); `updateLineStatus` order-level `isActive()` guard; `checkAndTransitionOrderToReady` refuses terminal states; red-first repro of the Cancelled→Ready chain (triage §2). NO adjacency map here (program scope). | triage F1 | XS-S | tenancy-authz-reviewer + fiscal-pos-reviewer |
| Q-10 | **Fiscal-period quick fixes**: per-company country threshold in `FiscalPeriodAutoLockService` (TN hardcode currently applies to every country — seeded-settings rule); a permissioned `Closed → Open` reopen path guarded on no-closed/filed-successor (copy `VatPeriodManagementService::reopenPeriod`); per-period audit rows for the three bulk updates. The full PeriodStatus machine = program. | tenancy sub-report HIGH #2 | S-M | treasury-reviewer |
| Q-11 | **SupplierInvoice `match()` draft-guard** (+ expense-number advisory lock from the Treasury sub-report MEDIUM — same lane, both are Procurement/Expense one-liners with tests). ⚠ COORDINATE: Session A's queued B-19 lane touches `CreateSupplierInvoiceService`; check the register before dispatching — if B-19 is in flight, hand this to A. | tenancy + Treasury sub-reports | S | treasury-reviewer |
| Q-12 | **Treasury orphan census command (F5)**: read-only artisan command over DS-1's nine bare-uuid columns, `ReconcileTreasuryCommand` mould. Output feeds the future FK lane's go/no-go. No migration. | triage F5 | S | treasury-reviewer |

## 5. Your queue — Wave 2 (the owner's actual question: **Slice D, DB CHECK hardening**)

This is the deliverable that answers "are the data structures sound": the **76-column CHECK
register** from the parent sweep, money/fiscal-first. Entry conditions (all three, from the
triage §4, unchanged):
1. Per-tenant distinct-value census BEFORE the migration (S-16 pattern — a CHECK aborts the
   whole per-tenant `tenants:migrate` on the first non-conforming row). Arm a LEDGER
   staging-owes row for the fleet census at deploy.
2. `NOT VALID` + separate `VALIDATE CONSTRAINT` so additive DDL cannot fail closed mid-fleet.
3. **The `pg_constraint` parity test** — every column backed by a Domain enum has a CHECK
   covering exactly that enum's cases (and the audit-table `from_status`/`to_status` columns).
   95 of 110 CHECK-bearing migrations are pgsql-guarded and SQLite tests see none of them, so
   the parity test is the only thing that keeps the constraints honest. This test is the
   highest-value single artifact of the whole program — build it FIRST, red against the current
   76, then burn down money/fiscal columns in batches with the baseline shrink-only.

Sequencing: after Wave 1's migration-bearing lanes (Q-6, Q-7) so their CHECKs fold in.
Dead-state cleanup rides along per batch (delete or wire: `AssignmentStatus::Overdue`,
`VoucherStatus/CouponStatus::Expired` + expiry jobs, `JournalEntryStatus::Reversed` + the three
dead reversal columns, `SignatureStatus` unreachable cases, `TenantStatus::Pending` default +
missing reactivate path, `bank_reconciliations` orphaned schema + `payments.is_reconciled`).

## 6. The program (plan only this session; execute post-first-customer)

Charter per triage §4 + parent-sweep amendments: workstreams A (POS Orders) / B (Documents —
**merge with the C-8 trigger-widening lane**; build on P1's `DraftNotEditableException`, never a
parallel exception type) / C (StockLevel single write path) + vouchers/import nouns; **no
Treasury or Inventory workstream** (verified clean at app layer); **fix the PHPStan guard shape
first (#27)** — parameterised rule covering `update()`/`fill()`, `status` out of `$fillable`,
backfill Scheduling. Spec-first, owner-signed adjacency maps (§2 graph ratification), transition
tables in the Workshop column vocabulary. Your deliverable THIS session: the written spec
skeleton + the ratification question list for the owner — nothing more.

## 7. Reporting cadence

Keep your own session log; at each merge update the shared register + LEDGER. When your Wave 1
is landed and Slice D's parity test exists, hand the owner: (a) the merged-lane list with gate
records, (b) the CHECK burn-down count (76 → n), (c) the program spec skeleton + owner
questions. Session A handles promotion; your merges ride it.
