# Plan — P0/P1 fix lanes before production (2026-08-05)

**Author:** campaign orchestrator session (successor). **Status:** DRAFT — awaiting owner approval before any fix work is dispatched.
**Trigger:** the full-E2E money campaign (W-3..W-8, W-X) closed 2026-08-05 finding 5 P0s + a systemic P1 + ~a dozen P1s, all as tickets + green tripwires, **zero product-code fixes written**. A parallel production/staging-hardening session was started before the campaign finished, so its handover cannot reflect these findings.

**Reads:** launch-program memory `project_first_tenant_launch_program.md` (roll-up), tickets `docs/superpowers/tickets/2026-08-0{3,4,5}-w*.md`, SDD ledger `.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/progress.md`, per-wave verdicts `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` (gitignored, local).

---

## 0. Coordination facts (read first — two sessions are live on this machine)

- **Two Claude sessions share the main checkout `/Users/houssamr/Projects/syneriva/apps/erp` and both commit directly to `dev`.** Verified: campaign work (`bb3e6190e`) is a clean ancestor of current `dev` HEAD (`43e14a041`); the prod session stacked fiscal/tenancy hardening on top (fiscal-event tenancy scoping, chain-verifier per-tenant iteration, channel-webhook central directory, preflight-gate fail-closed). **Nothing is lost, but shared-`dev` writing violates rule 21 and will collide.**
- **All campaign work is on LOCAL `dev`, unpushed** (56 commits ahead of `origin/dev`). The prod session (same tree) already SEES the tickets; the team / staging / any off-machine session does not.
- **Local `dev` carries 1 migration** (`…channel_webhook_directory_table`, from the prod session, not the campaign) — relevant to push-to-staging safety, prod session owns it.
- **The prod session is doing staging-hardening / tenancy, NOT the campaign P0 product fixes.** Those P0 fixes are currently **unowned**. Overlap zone: fiscal-chain area (Lane 1 below touches fiscal-event sealing, adjacent to their chain-verifier work).

**Coordination actions (before fix lanes start):**
1. **Isolate.** Every fix lane below runs in a **dedicated `git worktree` off `dev`** (`../erp.fix-<lane>`), never the shared main checkout. Merge to local `dev` in verified batches.
2. **De-conflict the fiscal lane.** Lane 1 must be sequenced with the prod session's fiscal work — either they take it, or we hand them the exact diff boundary. Owner/both-sessions decision.
3. **Findings visibility for others:** promote the campaign's test suite + tickets to `origin/dev` so the tripwires guard the fixes and the team can see them. This is a `dev` push = **staging auto-deploy** (owner gate) and would also push the prod session's migration — coordinate the push, don't do it piecemeal.

---

## 1. Triage — every P0/P1 against tenant #1 (ONE company, 4 branches = 1 warehouse + 3 shops, mostly cash)

Owner ruling recorded 2026-08-05: **fix for a correct ERP regardless of tenant #1**, but **sequence by what tenant #1 exercises.** Single-company ⇒ the cross-company defect is a correctness fix, not a tenant-#1 blocker. Multi-branch + mostly-cash ⇒ per-branch cash visibility and POS/cash paths move to the front.

| Defect | Sev | Tenant-#1 relevance | Lane |
|---|---|---|---|
| W-5c D1 deposit seals fiscal receipt before validating inputs (undeletable orphan in hash chain) | P0 fiscal | Bites if partner deposits used; **fiscal-chain integrity regardless** (seals bad events) | **L1** |
| W-6 D1a invoice GL auto-posts with NO double-entry guard | P1 must-fix | Any invoicing; immutable-ledger safety | **L1** |
| W-7 F-6 payment to a CANCELLED invoice → `paid`; cancel never reverses GL | P0 | Any invoice cancel + payment; composes with W-6 D2 | **L2** |
| W-6 D2 aged-AR blind to never-paid invoices (`balance_due` trigger cache) | P0 | Any AR; **not** tenant-#1 critical-path if truly cash-only, but full-ERP correctness | **L2** |
| W-6 D4 aging buckets sign-inverted (nothing ages out of Current); AP identical | P0 | Any aged AR/AP | **L2** |
| W-7 F-3 `/reports/cash-movements` silently drops `location_ids[]` (neither layer scopes) | P1 | **HIGH for tenant #1** — 4 branches, mostly cash → per-branch cash report is daily-use | **L3** |
| MLC multi-location money scoping (web half; POS half §Z) | P1 | **HIGH for tenant #1** — 4-branch money visibility | **L3** |
| Currency-blind emission: W-6 D6 (TND tiles labelled EUR), W-7 F-2/F-7 (qty through currency formatter; currency- not language-driven), W-8 F-3 (trial balance scale-mixed) | P1 systemic | Visible even single-currency TND (wrong symbol/scale on dashboards) | **L4** |
| W-8 F-1 cross-COMPANY JE list/read/POST leak (wrong-currency settle) | P0 (multi-company) | **NOT triggered by single-company tenant #1**; full-ERP correctness fix | **L5** |
| W-8 F-2 2nd company can't create first invoice; F-5 POST /companies commit-then-500 | P1 | Not tenant-#1 (single company) | **L5** |
| W-X `taxation.tax_configurations.manage` never seeded → tax-config DEAD for all roles incl admin | P1 | **Relevant** — tenant #1 admin must set VAT/stamp in-app | **L5→elevate** |
| W-5a ungated withholding routes + empty list + zero-rate undeletable certs | P1 | If withholding used | **L5** |
| W-4 allocator truncation / is_bonus_line dropped / RFQ no tax_rate | P1 | Purchasing; warehouse branch does receiving | **L5** |
| W-3 negative-net `discount_amount`; W-5b negative repository balance (owner ruling owed); W-5a DELETE 500 leak; W-6 D5 perm ruling; W-5c missing lang keys | P1/P2 | Mixed | **L5 / rulings** |

---

## 2. Fix lanes (TDD — each defect already has a green tripwire that must flip red→green on the fix)

Every lane: worktree off `dev`; write/confirm the failing assertion (the campaign tripwire, flipped to expected behaviour); implement minimal fix; scoped tests green; **adversarial review by the matching specialist reviewer before merge** (per standing rule); merge to local `dev`.

### L1 — Fiscal integrity (COORDINATE with prod session; highest priority)
- **W-5c D1:** add `ScopedExists::tenantAndCompany` on `payment_method_code` + `repository_id` in `RecordDepositRequest`; **resolve both references BEFORE `appendDepositReceipt(...)`** in `RecordCustomerDepositService::record()` so a bad input 422s before any fiscal event is sealed. Decide separately: forward-correct or annotate the receipts already orphaned locally/on staging.
- **W-6 D1a:** run `DoubleEntryValidator` (or an equivalent Σdr==Σcr assertion) on `AccountingService::createInvoiceGLEntries()` before it posts + hash-chains; fail closed on imbalance. (D1b stranded 19.000 = campaign test money → forward correcting entry or evidence-pack annotation; not a code fix.)
- **Reviewer:** `fiscal-pos-reviewer`. **Tripwires:** W-5c `income-deposits.spec.ts` D1 block; W-6 `finance-*.spec.ts` D1a.

### L2 — AR / document-status integrity
- **W-7 F-6:** terminal-status rejection in the shared per-allocation guard (`PaymentController.php:466-481` + `previewManualAllocation` `:650-658`) so a cancelled/voided invoice cannot be allocated to; and reverse the GL on invoice cancel (`DocumentPostingService::cancel` currently makes no GL call).
- **W-6 D2:** widen the `balance_due` trigger to cover never-allocated documents **or** make aged-AR read `outstanding_amount` (a computed value) instead of the cached column. Migration-bearing → coordinate with prod session's migration on `dev`.
- **W-6 D4:** fix the sign in `AgedReceivablesService::determineBucket()` (`diffInDays(..., false)` sign) and the byte-identical `AgedPayablesService`.
- **Reviewer:** `treasury-reviewer` (F-6) + a GL/finance pass (D2/D4). **Tripwires:** W-7 F-6; W-6 D2/D4.

### L3 — Multi-branch cash visibility (tenant-#1 daily-use)
- **W-7 F-3:** implement `location_ids[]` scoping end-to-end for cash-movements — `GetCashMovementsRequest` accept it, `CashMovementsReportService` filter on it, `useCashMovementsReport` send it via `useViewScope`, key with `locationScopedKey`. Model on the aged-* hooks that already scope.
- **MLC:** the web-half multi-location money-scoping cases (POS-data half stays §Z). Confirm per-branch report scoping across the 4 branches.
- **Reviewer:** `tenancy-authz-reviewer` (scoping) + frontend-conventions-reviewer. **Tripwires:** W-7 F-3; MLC cases.

### L4 — Currency-blind emission (systemic, one contract fix)
- Root: formatters default to EUR/scale-2/float-cast ignoring entity currency. Fix `formatCurrency`/`formatNumber` (web) to derive scale+symbol from the currency, and `FormatsReportNumbers::decimalString` / `CashRegisterReportService` (api) to stop float-casting + hardcoding scale 2. Note the PHPStan guards are structurally blind here (`number_format` not in the bcmath allowlist; `stdClass` rows) — consider extending them.
- **Reviewer:** frontend-conventions-reviewer + a precision-contract pass. **Tripwires:** W-6 D6, W-7 F-2/F-7, W-8 F-3.

### L5 — ERP-correctness, NOT tenant-#1-blocking (schedule after L1–L3)
- W-8 F-1 cross-company JE scope (add `company_id` filter to `JournalEntryController` index/show/post + `AccountController::index`); F-2 documents unique index → add `company_id`; F-5 POST /companies formatCompany null-guard.
- **W-X tax_configurations.manage** — add to `RolesAndPermissionsSeeder::permissionNames()`, grant admin/accountant, tenant-wide `permission:cache-reset`. **Elevate** (tenant-#1 admin needs in-app tax config). Small; could fold into L1 or L3's merge.
- W-5a withholding authz + list + zero-rate; W-4 purchasing trio; W-3 discount_amount; W-5a DELETE leak.
- **Owner rulings owed (block their fixes):** W-5b negative repository balance (cash_register/safe guard?), W-6 D5 finance-report permission model, W-3 discount_amount UI (ship the control or refuse absolute discounts).

---

## 3. Sequencing & promotion

1. **Now:** owner approves this plan + settles the L1 ownership question (prod session vs this session).
2. **L1 → L2 → L3** are the launch-critical path for tenant #1 (fiscal integrity, then document/AR integrity, then multi-branch cash). Run L1–L3 in isolated worktrees; each merges to local `dev` after its specialist review.
3. **L4** in parallel (independent surface). **L5** after L1–L3.
4. **Promotion:** once a verified batch of lanes is on local `dev`, coordinate ONE `dev` push with the prod session (their migration + the campaign suite go together) → staging auto-deploy → the staging Playwright re-run uses the campaign suite as the regression net (tripwires now green = fixes proven).
5. **Then the existing owner sequence resumes:** §A → staging campaign → POS §Z (Codex) → §Y final fiscal re-run (must tolerate the shifted Aug VAT or reseed E-7) → production.

## 4. Open decisions for the owner
- **D-1:** Who owns L1 (fiscal) — the prod session (already in that code) or this session with a handoff boundary?
- **D-2:** L2's `balance_due` fix is migration-bearing on a `dev` that already has the prod session's migration — batch the two migrations or keep separate?
- **D-3:** The 3 owner rulings in L5 (negative repo balance, finance-report perms, discount_amount UX) — decide now or defer past tenant-#1 launch?
- **D-4:** Push the campaign suite to `origin/dev` now (visibility + regression net, triggers staging deploy) or hold until L1–L3 fixes are batched with it?
