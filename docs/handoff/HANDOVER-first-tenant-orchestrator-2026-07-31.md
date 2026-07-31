# HANDOVER — First-Tenant Launch Orchestrator

**Date:** 2026-07-31. **For:** a fresh orchestrator session that owns the whole launch program.
**Mission:** stabilize the first delivery — everything between today and onboarding tenant #1 lands
in the right order, gated and verified. The design-refresh and agentic-ERP tracks come AFTER launch
and are explicitly out of scope for this program.

**Your role: ORCHESTRATOR ONLY.** You dispatch implementer sessions/agents (explicit cheaper model
per dispatch; reviews = Opus), adjudicate reviews, own merges into local `dev`, and own batched
fast-forward promotion to `origin/dev`. You do not implement. Push to `origin/dev` auto-deploys
staging including `tenants:migrate`.

---

## 1. Ground truth (verified 2026-07-31 — trust this over any open session's claims)

- **local `dev` == `origin/dev` @ `e7fd6d0e3`. Everything is promoted. NOTHING launch-related is in flight.**
  The 124-commit batch (`32da5bd9d..8b6afb516`, 2026-07-30) carried: cash-rounding server Phase 1 +
  device Phase 2 (both INERT behind the v3 gate), live-counting completion (A1–A6 + typed error codes
  + barcode-casing fix), treasury burn-down Tasks 1–8. Lane D (FR tester guide + smoke CSV) followed
  as `e7fd6d0e3`.
- **Staging verified, not assumed:** all 3 tenant migrations landed (~270s post-push); all 5 staging
  tenants have their TN `country_payment_settings` row (`rounding=false denom=0.0500 postol=false` — inert);
  §0.4 mixed-case CASH pre-flight came back EMPTY on all 5 tenant DBs (migration rewrite was a no-op).
  Staging DB access recipe: memory `reference_erp_staging_db_access` (port 5434 on 157.180.71.252,
  creds via Dokploy MCP `postgres-one` id `1CRiRxlMFCMynVj1CMhMs`; NOT on AX42, no local SSH key).
- **Context:** GREENFIELD. Zero real tenants; staging = 5 demo tenants / 6 companies / 8 receipts.
  Owner rulings: rollback v3→v2 is UNIMPORTANT; provision tenant #1 at `fiscal_schema_version = 3`
  with rounding enabled from provisioning (no staged cutover, no mixed fleet) — this retires checklist
  §0.2/§0.3 and the staged-cutover risk class, but see §3 finding ① before treating v3-from-day-1 as safe.
- **Worktree/branch cleanup done 2026-07-30:** 36 → 11 worktrees, 19 GB reclaimed. Survivors are parked
  non-launch tracks (accounting-gl, board, dashboard-demo, demo-pharmacy, ocr-docs, prepaid-drawdown,
  rafiq-skin, scan-to-doc, pg-triage, pos-clean-workbench). None active. Any session claiming live
  launch work is STALE.
- **erp-mobile is still un-pushed** (owner pushes personally): `codex/mobile-counting-hardening`,
  clean ff onto main, ships 24 commits (9 counting/scanning already on local main + 9 placements + 6
  hardening). No CI/EAS in that repo — push triggers nothing.

## 2. The gate you inherit: Codex adversarial review = REJECT (2026-07-30)

The previous orchestrator drafted a 4-lane dispatch plan
(`docs/handoff/DISPATCH-PLAN-pre-onboarding-2026-07-30.md` — kept as the review target, do NOT
dispatch from it) and put it through Codex adversarial review BEFORE dispatch (standing rule).
Verdict REJECT. Findings, each independently re-verified against code afterwards:

**Refuted / corrected (do not re-raise):**
- "A1" `tolerance_writeoff` `whereNotNull` at `PaymentToleranceQueryService.php:189` is NOT a bug —
  the next line is `->where('tolerance_writeoff', '>', 0)`. No defective consumer exists anywhere
  (Codex swept api + POS + Rust). A1 is DELETED.
- "A3" cash-predicate split is NOT a live defect — `UPPER(code)='CASH'` is case-insensitive by
  construction and the write API enforces `is_cash_tender ⇒ code='CASH'`
  (`PaymentMethodController.php:332,341`). Demote to hygiene.
- The Phase-1 checklist §6 ticket-3 suspect list is wrong (`PosAnalyticsService`, `Nf525DataProvider`
  don't reference the column).

**Confirmed / enlarged:**
- **A2 real + bigger:** `SalesReportService::paymentMethodBreakdown` (~:160-185) sums TENDERED cash
  (no `change_due` subtraction) → overstates by change given back once v3 traffic starts. PLUS:
  change must be subtracted once per receipt, not per payment row (join-level subtraction duplicates
  across tender legs), and `COUNT(*)` at `:185` counts payment rows, not transactions.
- **B1 bigger:** cap tables match today (`CashRoundingCaps.php:40` = `SaleReceiptV3Payload.ts:54` =
  `cashRounding.ts:43`) but `readPhpNamedConst` (`FiscalPayloadKeyDrift.test.ts:104`) only parses
  `public const` with lowercase string keys — `CAPS` is `private` with int keys. Needs a new parser,
  not one `it()`.
- **B4 confirmed:** `apps/pos/eslint.config.js` third `no-restricted-syntax` block (~:315/:332 region)
  omits `...cartMutatorSelectors` → FU-2 cart-mutator guard INERT app-wide (flat config REPLACES).
  One-line fix; also turns the pre-existing red `cartMutatorGuard.eslint.test.ts` green.
- B2 (32-column INSERT lockstep has zero executing coverage with non-null values) and B3
  (`appendZSessionCloseAndZReport` / signed Z `tolerance_summary` never asserted) stand as written in
  `docs/handoff/cash-rounding-device-phase2-followups-2026-07-29.md`.

**Two CRITICALS the plan missed entirely (these define the program):**

① **The v3 refund fiscal path is unproven.** `ReceiptFinalizationService:97-101` routes v3 to
`V3ReceiptHashComputer` (does not throw), but `ReceiptReturnService` has ZERO v3 references, the
device authors NO fiscal event for refunds (`refundCheckoutStore.ts:357,379` posts a plain `/return`),
the server seals by advancing `pos_terminals.last_hash/current_sequence` server-side while a v3 device
holds its own chain, and the principal return-chain test pins `fiscal_schema_version => 2`
(`ReceiptReturnRefactorTest.php:490`). Nothing proves device-sale → online return → next device-sale →
Z composes on a v3 terminal. Tenant #1 hits this in week one. **E1 (refund rounding delta, symmetric
±D/2, round-UP short-changes the customer) is a symptom of this; the integration gap is the disease.
Design refund-chain integration FIRST, rounding on top.**

② **A pre-existing first-tenant gate list exists and is open:**
- `docs/security/secret-rotation-2026-05-12.md` — 12 pending vs 5 revoked; every row must be
  `revoked` before the gate closes. OWNER-ONLY.
- `docs/qa/2026-05-13-first-tenant-handoff.md:59,88` — P0 real-device smoke (refund, Z, restore,
  ≥10 min offline with 5+ receipts, printer sleep/reprint/disconnect) + conditional TN
  accountant/legal sign-off (owner).
- `scripts/preflight-runbooks.sh` currently FAILS — unresolved placeholders in
  `docs/pos-operations/{install,backup,support,walkthrough-rehearsal}.md`.

Full review: read it before re-planning. It also demanded: explicit per-lane WRITE MANIFESTS before
any parallel dispatch (the v1 fencing table was self-contradictory), splitting Lane D
(provisioning code+test vs ops runbook), and a launch-contract provisioning test
(`TenantProvisioningServiceTest.php:72` asserts none of: country payment settings, valid cash tender,
terminal v3, enabled rounding, device policy pull; note `TenantProvisioningService.php:161` provisions
the main location with POS disabled — establish whether intentional).

## 3. The program (corrected shape — write v2 plan, re-run Codex on it, THEN dispatch)

| Lane | Scope | Depends on |
|---|---|---|
| **C** v3 refund integration | Critical ① design + integration test (sale→return→sale→Z on v3), THEN E1 refund rounding (pattern already adopted: independent Swedish rounding of the cash payout, delta → 6580/7580, own receipt line — `2026-07-27-refund-rounding-research.md`). Spec first, adversarial review, then implement. | nothing — start first, longest pole |
| **A** reporting truth | A2 only (3 defects in one query) | none |
| **B** payload guards | B1 (new PHP-const parser) + B2 + B3 + B4 | none |
| **D1** provisioning | Seeder wiring (§6 ticket 4: only `ProductionSeeder`+`TenantInitializationService` call `CountryPaymentSettingsSeeder`; beware memory `project_seeder_optional_company_container_trap`) + provision-at-v3 path + launch-contract test | none |
| **D2** ops runbook | Consolidate 7 staging checklists into one ordered runbook (treasury 3/4/5a/5b, multiloc, `RolesAndPermissionsSeeder`+`permission:cache-reset` — Spatie cache is TENANT-BLIND, Horizon restart, DemoPharmacySeeder rerun; use the Task-8 artisan commands, not raw SQL). Document only. | none |
| **E** release readiness | Runbook placeholders → `preflight-runbooks.sh` green (dispatchable); secret rotation, real-device smoke, legal sign-off (OWNER-ONLY — track, surface, don't attempt) | D2 helps |

Enable gate (unchanged): rounding stays OFF until Lane C lands or owner signs the acceptance blank at
`cash-rounding-phase2-deploy-checklist.md:226`. The promotion was NOT clearance to enable.

## 4. Open decisions to put to the owner (early, not late)

1. **Lane C scope confirmation** — full refund-chain integration + rounding as one lane (recommended), or split.
2. **Lane E owner items** — schedule secret rotation, real-device smoke, TN legal sign-off.
3. **Deptrac** — 97 vs baseline 61, pre-existing, identical on origin/dev, only bites at dev→main PR.
   Recommendation on file: re-baseline + ticket the 36. Needs a yes.
4. **B5** (mobile flagged-state) — recommendation: keep review web-owned. Needs a formal ruling.
5. **erp-mobile push** (owner-personal, 24 commits).

## 5. Standing rules (hard-learned; violations bit us this week)

- Worktree per lane off `origin/dev`; NEVER commit in the shared `dev` checkout. Merge to LOCAL dev
  first; promote as verified batched clean ff. `dev-push-guard` hook: never put `--force` (even
  `worktree remove --force`) in the same Bash call as `git push` — it pre-scans the whole command.
- Adversarial review (Codex) of spec+plan BEFORE implementation, and Opus review gates at EVERY task.
  Reviews save to files. Implementer pushback is adjudicated, not assumed wrong — the device lane's
  implementer twice correctly overturned reviewer+controller.
- Tests BY PATH ONLY (full PHPUnit crashes the laptop). Fresh api worktrees: real `composer install`,
  COPY `.env` (never symlink).
- Verify grep syntax before trusting empty results: quote `--include='*.php'`, use `grep -E` for
  alternation. Three silent false negatives came from this in the last session; two "verified" claims
  in the REJECTED plan trace to them. Read the line AFTER a match (`whereNotNull` + `>0`).
- Session reports lie by staleness: verify branch tips/merges yourself before acting. Two "please
  merge" requests this week were for merges already done.
- Memory index: `project_pos_cash_rounding_tolerance` (promotion + §0.4 + enable-block),
  `project_live_counting_completion_lane` (rulings incl. deferred `idempotency_key` lane),
  `reference_erp_staging_db_access`, `feedback_dev_local_remote_sync_discipline`.

## 5b. ADDENDUM 2026-07-31 (prior orchestrator, standing down) — READ BEFORE RE-ASKING §4

The prior session already obtained owner rulings on most of §4 and progressed step 3. Do not re-ask
what is settled here; reconcile only if the owner told you something different directly.

- **RULED — Lane C scope:** full v3 refund-chain integration + E1 rounding as ONE lane, spec-first.
- **RULED — Lane E:** all owner/team-manual items (secret rotation, P0 real-device smoke, TN legal
  sign-off, runbook values only the owner knows) are sequenced LAST; everything dispatchable
  proceeds now. D2 compiles the manual items into ONE checklist for the owner.
- **DONE — design material committed** (`bf07bbe71`): `docs/design/` + `docs/design-experiment/`
  (node_modules gitignored). Input for the post-launch design track, which the owner has ALREADY
  started as its own session — not yours to run.
- **STILL OPEN from §4:** deptrac re-baseline (recommendation on file, no ruling) and B5 (no ruling).
- **v2 DISPATCH PLAN EXISTS:** `docs/handoff/DISPATCH-PLAN-v2-first-tenant-2026-07-31.md` — five
  lanes with disjoint write manifests. **Codex round-2 adversarial review was launched on it; the
  verdict record will be committed as `docs/superpowers/reviews/2026-07-31-codex-v2-dispatch-review.md`
  when it lands.** Do not dispatch before reading that verdict. If it is present, proceed per its
  verdict (APPROVE → dispatch; fixes → fold, then dispatch; REJECT → round 3).
- **Single-orchestrator rule:** the prior session stands down after committing that verdict. From
  then on, YOU are the only orchestrator. Any other session claiming launch-program ownership is stale.

## 6. First moves (suggested)

1. Read the Codex review + this file's citations; spot-check any you rely on.
2. Put §4's decisions to the owner in one message.
3. Write the v2 dispatch plan (lanes above + write manifests) → Codex adversarial review → dispatch
   A/B/D1/D2 in parallel worktrees; C starts with its spec immediately.
4. Track E as the launch gate checklist it is; surface owner items relentlessly.
