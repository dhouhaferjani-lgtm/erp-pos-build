# Production-v1 Readiness — AutoERP / tenant #1

**Date:** 2026-08-05 · **Repo:** `/Users/houssamr/Projects/syneriva/apps/erp` · **Branch:** `dev`
**Verification snapshot:** `bb3e6190e` (register) · **HEAD at authoring:** `cb3c67d67`
**Target profile:** SINGLE-COMPANY · CASH-ONLY · TND (Tunisia) · IziPOS parapharmacy · one physical POS terminal · one location

**Provenance.** Section 3 items marked `[V]` were individually re-verified against live code on 2026-08-05 (register: 41 VERIFIED-OPEN / 6 VERIFIED-CLOSED / 4 STALE-CHANGED). Items marked `[N]` are new findings first raised this session. Every claim carries a `file:line` or a commit hash.

**Tags:** 👤 = owner decision or owner execution · ⚙ = executable by an engineer · 👤⚙ = both halves exist.

---

## 1. Executive summary

**Tenant #1 cannot go to production today.** The blocking set is not primarily code — it is (a) one non-waivable fiscal gate (E-7) that has never been closed, (b) ten owner gates of which **zero** have a single evidence cell filled, (c) a defect stack of which **nothing has been fixed**, and (d) a deploy-checklist stack of which **not one checkbox is ticked**.

### The governing fact

**Zero product code landed between `6f14f8232` (2026-08-04) and the verification snapshot `bb3e6190e` (2026-08-05).** Everything in between is `test(e2e)` or `docs(ticket)`. The W-5c / W-6 / W-7 / W-8 / W-X defect tickets were all filed 2026-08-04 → 2026-08-05.

```
$ git log --format="%h %ad %s" --date=short -- apps/api/app apps/web/src apps/pos/src
6f14f8232 2026-08-04 fix(scheduler): gate-review round …
d6ed4e481 2026-08-04 fix(scheduler): convert the three cross-tenant scheduled surfaces …
```

**Therefore every campaign P0/P1 from those waves is still live in the code that would ship.** This was not assumed — each defect was re-confirmed individually against current source. Since the snapshot, exactly one product commit has landed (`cb3c67d67`, `forEachTenant` iterates ACTIVE tenants only) — a cat-(b) conversion-lane fix, not a defect fix.

### Two things that are genuinely sound

1. **Cross-TENANT isolation is empirically proven.** W-8's 24 replay probes — reads, mutations, and the `X-Company-Id` header vector, in both directions — all failed closed with a byte-identical money snapshot. `MTP-ISO-05`, the P0 security gate of the isolation wave, is a **clean PASS**. The breakage found is entirely at the **cross-COMPANY** layer, which a single-company tenant #1 does not exercise.
2. **Deptrac is green and will not block the `dev` → `main` PR.** Ratchet reads `98 violations / baseline 98 / exit 0 / PASS` on local `dev`, re-baselined at `ac165f2c2` (2026-08-03). CI job `backend-architecture` (`.github/workflows/ci.yml:143-148,173-178`) runs it unguarded on every PR to `main` and `dev`. The memory item claiming "97 vs baseline 61, bites at dev→main" is **wrong** (see §4).

### Critical path (the short version)

```
NAME the 6 second humans   ──┐
                             ├─► E-2/E-3/E-4/E-6/E-8 can START
                             │
P0 fix wave (⚙, §3)          ├─► §A push (56 commits) ─► staging campaign (D1 web + D2 §Z)
cat-(b) verifier conversions ─┘                                    │
      ▲ E-7 evidence tooling is UNSOUND until these land           ▼
                                                     §Y final fiscal re-run
                                                            │
                                        E-7 CLOSES ◄────────┘
                                                            │
                    pre-launch-audit ruling ──► dev→main ──► Part C production deploy (E-10)
                                                            │
                                              E-8 (device v67 + briefing) ─► E-2 smoke ─► first real sale
```

**Longest-lead item is not code.** Naming a Tunisia legal reviewer, a tenant operator, a Synerivia observer, a DBA/ops steward, a walkthrough Reader and a branch manager depends on other people's calendars. Five gates cannot even **start** until those names exist.

**Standing prohibition, still in force:** **NO refunds and NO voids on ANY terminal** until E-7 closes (`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md:45`, reinforced by `e3341e2ae`).

---

## 2. OWNER-DECISION / OWNER-ACTION items 👤

None of this section is executable by an agent. Six of the ten gate documents are **human-only manifest paths** whose write-ownership transferred to Phase E at `21d807392` — *"no agent lane may touch them from this merge on."* Verified: only one commit (`e3341e2ae`, the no-refunds prohibition) has touched any of the six since, so **no owner evidence has been written into any Phase-E manifest.**

The six human-only paths:
`docs/handoff/OWNER-manual-launch-gates-2026-07-31.md` · `docs/handoff/STAGING-RUNBOOK-first-tenant-2026-07-31.md` · `docs/qa/2026-05-12-first-tenant-smoke.md` · `docs/qa/2026-05-12-migration-audit-and-rollback.md` · `docs/security/secret-rotation-2026-05-12.md` · `docs/pos-operations/walkthrough-rehearsal.md`

### 2.1 Gate states — E-1 … E-10 (all ten OPEN)

| Gate | 2nd human required? | Current state (verified) | Verdict |
|---|---|---|---|
| **E-1** Secret rotation | No — Houssam sole executor | `docs/security/secret-rotation-2026-05-12.md:3` = *"Status: OPEN — pending provider-side revocation."* All 9 inventory rows `:59-67` are `pending-provider-rotation`; **zero `revoked`**. `:250` unticked, Final Sign-Off `:269` blank. No acceptance path for any row | **OPEN** |
| **E-2** P0 real-device smoke | **YES ×3** — tenant operator, Synerivia observer, Tunisia legal reviewer | `docs/qa/2026-05-12-first-tenant-smoke.md:9,:60` — *"All Status / Actual Outcome / Evidence cells below are intentionally empty"*. Still empty. Second humans **not named** | **OPEN — cannot START** |
| **E-3** Prod migration rehearsal | **YES if DBA/ops steward ≠ Houssam** (`:100-104`) | Delta *"must be enumerated by release engineering after a staging clone"*; `migrate:status` cannot be enumerated from the dev worktree. **352 migrations, 292 (~83%) contain `dropIfExists`/`dropColumn`/`rename` — irreversible without backup-restore.** No rehearsal record | **OPEN** |
| **E-4** TN accountant/legal sign-off | **YES** — a Tunisia accountant or legal reviewer; blank blocks the row | No sign-off section populated in `docs/pos-operations/walkthrough-rehearsal.md`. **Scope GREW:** `OWNER-INDEX-2026-08-03.md:8-37` adds three new expert-comptable questions | **OPEN + scope grew** |
| **E-5** Runbook preflight ZERO | No | `scripts/preflight-runbooks.sh` exists and is executable. Status cell empty. **Cheapest gate on the sheet** | **OPEN** |
| **E-6** Walkthrough rehearsal | **YES** — the Reader role | `walkthrough-rehearsal.md:45` still reads the template literal `Approved for go-live docs? YES/NO` | **OPEN** |
| **E-7** v3 correction-chain closure — **NON-WAIVABLE** | No | M1+M2+M3 implemented; §1.1 acceptance test + payout analysis done (`OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:36-39`). **REMAINING: staging test campaign (§D) + final fiscal re-run (§E).** Standing NO-REFUNDS/NO-VOIDS prohibition in force | **OPEN — the hard blocker** |
| **E-8** Target-device rollout | **YES** — the branch/location manager attending the mandatory briefing | Not started. Depends on E-7 and on the promoted artifact. **Gate text specifies v64; evidence says v67** (see 2.3) | **OPEN** |
| **E-9** Staging-runbook execution | No | `STAGING-RUNBOOK-first-tenant-2026-07-31.md` (475 lines): **zero filled "Actual Outcome" cells**. 36 `RERUN-ON-FINAL-CANDIDATE` steps must be re-run regardless of history | **OPEN** |
| **E-10** Production release/cutover | No — but the **environment decision is explicitly non-delegable** | Ruling *"tenant #1 onboards to PRODUCTION after the staging test campaign passes"* is recorded at `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:22-23` — a **Claude-authored checklist, not the human-only evidence sink**. Gate-sheet Status cell blank | **DECISION MADE, GATE OPEN** |

**Gate-sheet closing note (`:50-51`)** — *"All Status cells above are intentionally empty. They are filled only by the named human(s) … never prospectively by any agent lane."* — is still literally true.

**Dependency order (gate sheet `:55-66`):** E-1/E-4/E-5/E-6 anytime → **E-3 before cutover** → **E-7 must be IN the release candidate** → **E-9 on that candidate in staging** → **E-10** (environment decision + fresh backup on the ACTUAL target — E-3's rehearsal backup can never satisfy this) → **E-8 then E-2 on the same promoted artifact** (manager briefing BEFORE device install; smoke AFTER install, BEFORE the first real transaction).

### 2.2 Blank gate sheet + second-human naming 👤

**The single highest-leverage owner action on this document.** Six people must be named before five gates can begin:

| Role needed | Gate | Evidence |
|---|---|---|
| Tenant operator | E-2 | `OWNER-manual-launch-gates-2026-07-31.md:24-26` |
| Synerivia observer | E-2 | same |
| **Tunisia legal reviewer** | E-2, E-4 | same + `:40-46` |
| DBA / ops steward (if ≠ Houssam) | E-3 | `docs/qa/2026-05-12-migration-audit-and-rollback.md:100-104` |
| Walkthrough Reader | E-6 | `docs/pos-operations/walkthrough-rehearsal.md` |
| Branch / location manager | E-8 | `cash-rounding-phase2-deploy-checklist.md:224-263` |

Rule as written: *"an unnamed required second human blocks the row from STARTING, not just from closing."*

### 2.3 Device-version ruling — three documents disagree 👤

Source of truth is code: `apps/pos/src/lib/db/migrations.ts` — highest version is **67** (`:2163`, array closes `:2183`).

| Doc | States | Status |
|---|---|---|
| `STAGING-DEPLOY-RUNBOOK-2026-07-28.md:148` | v62 | **STALE** |
| `cash-rounding-phase2-deploy-checklist.md:241` | v64 — *"final migration this track ships"* | **STALE** (true for that track only) |
| **`OWNER-manual-launch-gates-2026-07-31.md:46` (E-8 gate row)** | **v64** | **STALE — and it is a human-only file** |
| `HANDOVER-codex-pos-desktop-campaign-2026-08-02.md:37-39` + owner checklist D2 | **v67** | **CURRENT** |

Why v67 is the floor: **v66** (`:2132`) makes the §9.3 acknowledgement failure durable — below it a terminal is silently routed to v4 device-side while the legacy `/return` path stays open server-side, **undetected**. **v67** (`:2163`) adds the M2/M3 refund-exposure ceilings on `company_fraud_settings_cache`; without them a pre-upgrade row **hard-refuses every refund**. Handover `:37-39`: *"If < 67, stop and request a build refresh — §Z results on an older schema are void."*

**OWNER ACTION:** correct the E-8 gate row from v64 → v67. No agent may edit that file.

### 2.4 Pre-launch audit ruling (Graphify + security audit + doc realignment) 👤

The plan exists only as a gate reference inside the POS roadmap — `docs/superpowers/plans/2026-04-30-pos-roadmap.md:48`, *"all of the above sit on dev awaiting the pre-launch audit (Graphify run + security review + doc realignment) before the dev→main merge"*; `:76` names it the final go-live gate; `:145` still asks *"Pre-launch audit scope and trigger … Owner/timeline for the audit?"* — an open question in the doc itself.

- **Graphify run:** never done, no artifact (`grep -ril graphify docs/` → three POS-roadmap-era files, none a plan).
- **Security audit:** partially done and **3 months stale** — `docs/security/` is a May-2026 corpus (`cross-tenant-route-inventory-2026-05-12`, `file-endpoint-scope-review-2026-05-12`, `csp-unsafe-inline-investigation-2026-05-14`, …). All of it pre-dates the entire July–August treasury / refund-chain / multi-company / settings-gating body of work. **No post-July security review exists.** Note the marketplace finding in §3 (R-02) is exactly the class of thing that corpus would have caught.
- **Doc realignment:** not done. `OWNER-INDEX-2026-08-03.md:107-110` says the same of the tester-facing docs.

**RULING NEEDED, binary:** (a) run the audit and hold `dev` → `main`, or (b) explicitly waive the roadmap coupling and let E-10 promote from `dev`. Both legs of the audit are executable once the scope is ruled; the coupling decision is not.

### 2.5 §A staging push clearance 👤

**56 commits sit on local `dev`, unpushed to `origin/dev`.** Content: `test(e2e)` specs + `docs(ticket)` records only — **zero product code, zero migrations**. Pushing to `origin/dev` **auto-deploys staging including `tenants:migrate`**, so it is a deploy event even with no migrations in the batch.

- The 56 commits **are the evidence pack** — every GREEN tripwire pinning every W-wave defect lives in those specs. Until pushed, no other session and no CI run can see or re-run them.
- `OWNER-INDEX-2026-08-03.md:3` still declares `origin/dev = cce3491b6`, ~30 commits stale on its own headline.
- Per the dev-sync rule: `git fetch origin dev`, verify the promotion is a clean fast-forward, then push. A 56-commit local-only backlog is precisely the divergence condition the `dev-push-guard` hook exists to prevent.

### 2.6 Mobile branch push 👤

`erp-mobile` push is recorded as DONE at `5a90341` (live-counting completion lane). Confirm no unpushed mobile work remains before the release candidate is cut — the mobile repo is outside this checkout's `git status`.

### 2.7 Expert-comptable questions (E-4 scope growth) 👤

Added 2026-08-03 at `OWNER-INDEX-2026-08-03.md:8-37`, **after** the gate sheet was written:

| Q | Subject | Flag |
|---|---|---|
| Q1 | Credit-note stamp vs account 411 | ***"Bloque la certification GL des avoirs"*** |
| Q2 | Partial-deductible VAT base | open |
| Q3 | DGI box mapping | open |

Q1 is a blocking question, not an informational one. E-4's original 5 subjects (VAT rates, receipt legal fields, certification scope, Z format, retention) still stand alongside these.

### 2.8 Standing prohibitions and residual owner rulings 👤

| Item | State |
|---|---|
| **NO refunds / NO voids on ANY terminal until E-7 closes** | **IN FORCE** — `OWNER-manual-launch-gates-2026-07-31.md:45`, recorded by `e3341e2ae` |
| **E-10 environment decision** | Made (production, post-staging-campaign) but **not transcribed into the human-only gate sheet**. Decision made ≠ gate closed |
| `SYNC_PERMISSIONS_ON_BOOT` policy | **Ruling needed:** set `=true` on the production env (accepting that `syncPermissions` resets custom role tweaks) **or** make the reseed a mandatory evidenced manual step in E-10. Note: the **live staging API app already sets `=true` via Dokploy env** (verified 2026-08-05 via Dokploy API) — the gap is in the in-repo compose templates and in making the production choice explicit. See R-09 |
| Device Z/X sealing strategy | **Ruling needed** — in-place patch vs schema-versioned Z payload, with a fiscal-pos-reviewer gate. The code fix (R-01) is executable; the sealing strategy is not |
| Multi-company document numbering | **Data-model ruling needed** — constraint gains `company_id`, or the number carries a company discriminator. Not tenant-#1-blocking (see R-22) |
| Already-orphaned deposit receipts (staging/local) | Small owner decision attached to R-04 |
| D1b stranded `19.000` GL imbalance | Campaign test money, immutable (915 entries chain off it). Needs a forward correcting entry **or** an evidence-pack annotation — it will otherwise corrupt the E-1/E-7 evidence pack |

### 2.9 Production environment design — deferred to Part C 👤⚙

Part C (in flight this session) designs the production environment: **Hetzner VPS + Dokploy, database-per-tenant, backups/DR, and a secrets store**. It is the **vehicle** for three owner gates and is deliberately not duplicated here:

| Gate | How Part C serves it |
|---|---|
| **E-10** environment decision | Part C is the concrete target the decision names |
| **E-1** secret rotation | The secrets store is the destination that lets rows flip `pending-provider-rotation` → `revoked` |
| **E-3** migration rehearsal | The backup/DR design supplies the staging clone + the fresh-backup-before-migration procedure E-10 step 7 demands |

Read Part C for the design; read this document for the gate states.

---

## 3. EXECUTABLE work register ⚙ — prioritized

Effort classes: **S** ≤ ½ day · **M** 1–3 days · **L** > 3 days or multi-lane.
Status: **open** · **fix-lane-in-flight** (specced and dispatched this session) · **done-this-session**.

### 3.1 P0 — blocks tenant #1

The first ten preserve the verification register's top-10 ranking (severity × likelihood × irreversibility), with the two new session findings inserted at their earned positions and marked `[N]`.

| # | Item | What / where | Why it blocks tenant #1 | Effort | Status |
|---|---|---|---|---|---|
| **R-01** `[V]` 👤⚙ | **Device Z/X sale branch treats GROSS as NET** | `apps/pos/src/lib/offline/zReportService.ts:906-909` sets `lineNet = line.line_total` (which is **TTC**) then `lineGross = lineNet + lineVat`. Correct refund branch sits at `:867-869` in the same file. Three more sites: `zReportService.ts:1015-1017`, `apps/pos/src/lib/offline/endOfDayPreview.ts:298-300`, `apps/pos/src/api/reportApi.ts:492-494`. **Plus an unnamed 5th defect:** receipt-level `net_sales` — `receiptService.ts:145-158` builds `subtotal` as `Σ line_total` (gross), stored `:547`/`:668`; `zReportService.ts:900` sums it as `netSales` beside `grossSales` at `:899` → **Z `net_sales` == gross sales**. Premise confirmed by the writer: `cartStore.ts:163-173,193,205`, `receiptService.ts:537,539` | Only precondition is `tax_rate > 0`. Every signed `Z_REPORT`/`X_REPORT` carries an overstated net and gross in `vat_breakdown` plus an overstated `net_sales` header. **The bytes are signed and hash-chained — every shift closed accrues a permanently sealed wrong record.** `total`, cash drawer and payment reconciliation are UNAFFECTED, so **the till balances and the defect is operationally invisible** until fiscal audit or VAT filing. A taxed TN pharmacy is the exact hit profile | M (code) | **open** — never worked despite `(URGENT)` prefix and a named micro-lane disposition (`2026-08-01-device-z-sale-branch-gross-as-net.md:21`). Sealing strategy is 👤 (§2.8) |
| **R-02** `[N]` ⚙ | **Marketplace admin routes have super-admin semantics under tenant auth** | `apps/api/app/Modules/Marketplace/Presentation/routes.php:47-65` — `POST/PATCH sellers`, `POST sellers/{id}/suspend` run under `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` + `can:marketplace.admin`. `RolesAndPermissionsSeeder.php:385` puts `marketplace.admin` in `permissionNames()`, and `admin` syncs `Permission::all()` → **every tenant admin holds it**. `store` accepts arbitrary `tenant_id`/`company_id` → any tenant admin can create/update/suspend **fleet-wide** marketplace sellers. `marketplace.browse` is `#[CrossTenantRoute]` and seeded to essentially every role (`:382,563,612,653,711`). Creating a seller row **arms the fleet-wide 15-min scheduler fan-out** (`MarketplaceServiceProvider.php:55-56`) — that fan-out is guarded by data presence, not by capability. `config/marketplace.php:6` defines `'enabled' => env('MARKETPLACE_ENABLED', false)` and **nothing reads it** (grep for `config('marketplace` → only `sync.*` and `anti_abuse.*`) | Cross-tenant privilege escalation reachable by tenant #1's own admin account. P1 by blast-radius grading, **pre-launch** by policy: it is a fleet-wide authz hole shipping into the first production deployment | M | **fix-lane-in-flight** — super-admin guard + wire the dead `config('marketplace.enabled')` flag around route loading *and* the scheduler + the Cart marketplace-checkout route. Canonical `module:Marketplace` gating folded into the vertical-gating audit |
| **R-03** `[V]` ⚙ | **Withholding-tax routes 100% ungated** | `apps/api/app/Modules/Taxation/routes.php:38-45` (rules: `store`, `deactivate`, `destroy`) and `:48-59` (certificates: `store`, `issue`, `void`, `submit-tej`, `destroy`) carry **zero `can:` middleware**. The correctly-gated sibling group is directly below at `:62-69` — the omission is visibly accidental | Any authenticated tenant user — **including a cashier** — can create, issue and void withholding certificates, which are authored into the **fiscal hash chain** and submitted to the TN TEJ regime, and can deactivate/delete withholding rate rules. A TN tenant is exactly who uses these. **Grade P0 on the security axis** (the ticket says P1) | S | **open** — `2026-08-03-w5a-withholding-defects.md:106`, no FIXED/CLOSED marker anywhere |
| **R-04** `[V]` ⚙ | **Deposit seals a fiscal receipt BEFORE validating its references** | `RecordDepositRequest.php` rules are `'payment_method_code' => ['required','string','max:64']`, `'repository_id' => ['required','uuid']` — **no existence check on either FK**, where the sibling `PayExpenseRequest.php:50-60` uses `ScopedExists::tenantAndCompany('payment_repositories',…)` for the identical field. `RecordCustomerDepositService::record():63-95` seals the `DEPOSIT_RECEIPT` into the chain, then resolves the FKs outside the transaction and throws | Reachable by **routine ops**, not malformed clients: both `TreasuryDepositBridge` resolvers filter `is_active = true`, so deactivating a payment method or repository while a back-office deposit dialog is open **mints a sealed orphan + 500**. Permanent artifacts per orphan: sealed chain event + `pos_deposit_receipts` row + one dead-lettered projection (5 retries / ~21 min, terminal). The over-stated record is the **customer-facing** one read in payment disputes. No delete route, by design. Already graded **PRE-ENABLE BLOCKER** by the program's own fiscal gate (W-5c D1 `:83-88`) | S | **open** |
| **R-05** `[N]` ⚙ | **cat-(b) cross-tenant: the launch program's own gate/verifier tooling is UNSOUND** | **`PreflightFiscalGateCommand` FALSE-PASSES.** `app/Modules/Fiscal/Infrastructure/Commands/PreflightFiscalGateCommand.php:77-82` — `tableCount()` returns `0` when `! Schema::hasTable($table)`; in central context all four probes (`pos_receipts`, `pos_z_reports`, `pos_receipt_prints`, `pos_terminals`) are absent → prints **"SERVER SURFACE: clear"** and **exits 0 before a schema-destructive fiscal rebuild**. The three launch verifiers named in `DISPATCH-PLAN-v4-first-tenant-2026-07-31.md:130-131` all query central: `VerifyFiscalChainsCommand.php:49-50,113`, `VerifyPosChainCommand.php:139,151,158,179,216,250`, `VerifyEventChainCommand.php:98,233,319,338` (its `--tenant` is a WHERE predicate, never a tenancy binding) | **This is a gate on E-7 and §Y, not a defect beside them.** E-7's closure and §Y's final fiscal re-run are *defined by* running these verifiers. Until they are converted, a green verifier run is not evidence of anything — and the preflight gate will wave through a destructive rebuild. **The fiscal evidence pack cannot be trusted until this lands** | M | **fix-lane-in-flight** — Wave 2 (operator/gate commands), `bindTenantAndCompanyFromOptions()` for the `--tenant`/`--company` four, `forEachTenant()` where fleet-wide is genuinely wanted, and `PreflightFiscalGateCommand` must treat a missing table as **FAILURE**, not zero |
| **R-06** `[N]` ⚙ | **cat-(b) cross-tenant: six automated surfaces broken NOW** | `channels:reconcile` scheduler closure — `ChannelServiceProvider.php:30-46`, `Schema::hasTable('channels')` at `:32` is false in central → early return at `:33`, **100% silent, zero log, zero failed_jobs row, scheduler records success**. `fiscal:lock-expired-periods` — `LockExpiredFiscalPeriodsCommand.php:84-88` swallows the exception, `runInBackground()`, no `onFailure()` → **silently dead nightly since 2026-05-28**. `fraud:detect` — `DetectFraudPatterns.php:56` `Company::all()` before the per-company try/catch. `enrichment:check-pending` — `CheckPendingEnrichmentsCommand.php:48` → `ProductEnrichmentQueryService.php:21` `Product::query()`. **Both unauthenticated webhook controllers 500 on every external callback:** `ChannelWebhookController.php:38` (`Channel::findOrFail` on a tenant table) and `EnrichmentWebhookController` → `ProcessEnrichmentEventListener.php:36` (`Product::…->sole()`, whose `QueryException` 42P01 is **not** caught by the `catch (ModelNotFoundException)` at `:37`) | `fiscal:lock-expired-periods` is a **fiscal-period control that has not run since the db-per-tenant flip** — tenant #1 inherits a fiscal-locking scheduler that does nothing. The others are silent-failure surfaces on a production deployment. **The structural cause:** `ConsoleCommandTenantContextTest` only asserts that a justification *string exists*, never that it is still true — so every broken class passes the arch gate today | M | **fix-lane-in-flight** — Wave 1 (automated surfaces), template `d6ed4e481` / `6f14f8232`; every converted scheduler entry gains `onFailure()`. The webhook pair needs a **design decision** on the central pointer (payload-echoed `tenant_id` preferred — it also closes Finding A) |
| **R-07** `[V]` ⚙ | **Payment allocated to a CANCELLED invoice resurrects it as `paid`** | `PaymentController.php` — the only status guard in the allocation-validation loop is supplier-only (`:496` inside the `:489` `SupplierInvoice` branch); customer invoices fall through with **no status check at all**. Write path `:1003-1005` gates on document **TYPE** (`canTransitionToPaid()`), never on status or `cancelled_at`. `grep -n cancelled PaymentController.php` → **zero hits** | Two ordinary UI actions, no race. `documents.status` becomes `paid` while `cancelled_at` stays populated; `GET /invoices/{id}` is then indistinguishable from a legitimate settlement. A cancelled sale reappears as collected revenue in every AR and revenue surface | S | **open** — W-7 F-6 (P0), pinned by `MTP-CONC-06` |
| **R-08** `[V]` ⚙ | **Aged receivables is blind AND sign-inverted — two independent P0s** | `Accounting/Application/Services/Reports/AgedReceivablesService.php:151` `->where('balance_due','>',0)` — `balance_due` is a PG trigger cache fired by `payment_allocations` DML only, so a never-allocated document stays `NULL` forever and `NULL > 0` is NULL → filtered out. No `whereNull` fallback, no `COALESCE` in `getOutstandingInvoices():144-151`. Separately `:197` `diffInDays($ref, false)` returns **b − a**, so an overdue invoice yields a **negative** value and `:230-233`'s `if ($daysOverdue < 0 \|\| $daysOverdue <= 30) return 'current'` files **everything as Current**. The sibling `Document/Application/Services/AgedReceivablesService.php:82-86` computes it correctly — two services, two routes (`Accounting/…/routes.php:178`, `Document/…/routes.php:360`), divergent logic | Ticket run 3 (2026-08-05): **165 posted invoices totalling 59 532.410 TND missing** against a reported grand total of 32 892.422 — **~64% of the receivable book omitted**, growing every campaign run. Nothing ever ages out of Current, so nothing is ever collectible-overdue. Tenant #1 runs partner deposits and customer accounts → treat as blocking | S (both) | **open** — W-6 D2 + D4 |
| **R-09** `[V]` 👤⚙ | **Unexecuted deploy-checklist stack + the permission-reseed gap** | **Zero `- [x]` checkboxes exist across all nine checklists and both consolidated runbooks.** Auto-deploy applies schema (`entrypoint.sh:123` `migrate`, `:141` `tenants:migrate-rolling`) and runs `permission:cache-reset` unconditionally (`:167`) — but the **reseed** at `:156` is gated on `SYNC_PERMISSIONS_ON_BOOT` (`:153`), which is set in **NO in-repo compose file** (`docker-compose.staging.yml`, `.dokploy.yml`, `.sidebar-demo.yml`: zero occurrences) — **however the live staging API app DOES set `SYNC_PERMISSIONS_ON_BOOT=true` in its Dokploy env** (verified 2026-08-05 via Dokploy API), so staging reseeds on every boot; the reseed-debt claims in older checklists may be partially stale for staging, and the real gap is that this is invisible in the repo and undecided for production. Resetting a cache that was never repopulated is a no-op. **Also absent from boot:** every backfill — `treasury:*`, `accounting:backfill-tolerance-purposes`, `pos:configure-cash-rounding`, `BanksSeeder`, `users:backfill-memberships`, `ChartOfAccountsService::seedForCompany`. Hard stops inside the stack: `treasury-phase5b:100` (*"a missing partial predicate, a non-shared private disk, permission-cache drift, or absent abandoned-preview cleanup is a DEPLOYMENT STOP"*), the `bank-directory:7` FK ordering gate, and `cash-rounding-phase2:165,:176-181` (**every terminal must be at `fiscal_schema_version = 3`, and there is NO CLI — only `POST /pos/terminals/{terminal}/fiscal-schema-cutover`**) | Without the reseed, W-X (R-11) and the dead-letter/compensation endpoints 403 for every role, and **no defect fixed by a seeder change self-heals on deploy**. Lane D2 consolidated only treasury ③④⑤a⑤b + multiloc into `STAGING-DEPLOY-RUNBOOK-2026-07-28.md`; **phase ② and both cash-rounding checklists were never folded in** and must still be executed from their own documents | L | **open** — three documented foot-guns: `tenants:run` **always exits 0** (gate on output tokens, never `$?`); the `?Company $company = null` seeder container trap silently no-ops `BanksSeeder`/`PaymentRepositorySeeder`; `pos:configure-cash-rounding --verify` prints `FAILURES: 0` on a **missing** `country_payment_settings` row (`ConfigureCashRoundingCommand.php:485-489`). Owner half = the `SYNC_PERMISSIONS_ON_BOOT` ruling |
| **R-10** `[V]` 👤⚙ | **POS device build must carry v67** | `apps/pos/src/lib/db/migrations.ts:2163`. See §2.3 for the three-way doc disagreement | Below v67 either refunds are hard-refused (missing v67 ceilings) or the terminal is silently split-path (missing v66's durable ack error), and **§Z campaign evidence is void** | M (build + install) | **open** ⚙; the E-8 gate-row correction is 👤 |
| **R-11** `[V]` ⚙ | **`taxation.tax_configurations.manage` is never seeded → tax config is DEAD on every real tenant** | Write endpoints gated by `can:taxation.tax_configurations.manage` (`Modules/Taxation/routes.php:23-31`). The permission is defined in `PermissionSeeder.php:121,:225`, but `PermissionSeeder` runs only from `ProductionSeeder.php:68` (the **central-DB/registration bootstrap** path). Per-tenant provisioning uses **only** `RolesAndPermissionsSeeder` (`TenantInitializationService.php:177-178`) — `grep -n taxation RolesAndPermissionsSeeder.php` → **no output**. Live proof in the ticket: 288 permission rows in the tenant DB, **zero matching `taxation.*`**; `PATCH /taxation/configurations/{id}` → **403 as the admin/owner**; reads stay 200 | On any real tenant **no user — not even the owner — can create, edit, deactivate or reorder a tax configuration.** `/settings/tax` is read-only in production. Provisioning seeds correct TN defaults so day-1 selling works, but tenant #1 is a **Tunisian pharmacy with VAT rates + stamp duty**, and **E-4 is precisely the process most likely to demand a rate change** — with no in-app remedy at all (requires DB access) | S + reseed | **open** — fix is `permissionNames()` + grant to `admin` (+`accountant`), then tenant-wide reseed + `permission:cache-reset` (Spatie cache is tenant-blind). **Depends on R-09.** Attribution corrected in `ae5a0c3f8` |

**Companion note (not a defect):** company `tax_status` is additionally protected by a fiscal lock returning `422 BUSINESS_ERROR` once posted documents exist. That is correct behaviour, GREEN-tripwired by `MTP-TAX-02`.

### 3.2 P1 — high impact, launch-relevant

| # | Item | What / where | Blocks tenant #1? | Effort | Status |
|---|---|---|---|---|---|
| **R-12** `[V]` ⚙ | Document TOTALS panel renders `en-US` under `fr` | Totals panel emits `1,234.567` beside correctly `fr-TN`-formatted lines | **YES** — a **1 000× misread** of the invoice total. Arguably belongs in P0 | S | open (W-7 F-7) |
| **R-13** `[V]` ⚙ | `FormatsReportNumbers::decimalString()` float-casts money | Casts to `float`, formats at scale 2, `rtrim`s zeros → `300.000` emitted as `"300"` in owner reports | **YES** — direct violation of the precision contract (CLAUDE.md rule 19) on the owner's reports | S | open (W-7 F-2) |
| **R-14** `[V]` ⚙ | `/finance/overview` shows six TND tiles **labelled EUR at 2 dp** | Beside four sibling tiles correctly showing TND at 3 dp | **YES for a TND tenant** — wrong currency symbol and lost millime precision on the owner's headline screen | S | open (W-6 D6) |
| **R-15** `[V]` ⚙ | `reports.view` / `ledger.view` seeded to `admin` only | `RolesAndPermissionsSeeder.php:262,:264` appear only in `permissionNames()`; grep count **0** in the `manager`(`:490`) / `cashier`(`:584`) / `viewer`(`:620`) / `accountant`(`:717`) blocks. FE routes gate on `accounts.view` → the pages render, then the API 403s | **conditional-YES** — bites the moment tenant #1 has a non-admin finance user. **An accountant is exactly the persona E-4 requires** | S + reseed | open (W-6 D5). Depends on R-09 |
| **R-16** `[V]` ⚙ | `createInvoiceGLEntries()` has **no** double-entry guard | `DoubleEntryValidator` exists at `Accounting/Domain/Services/DoubleEntryValidator.php:9` and is injected in exactly one place — `JournalEntryController.php:26`. `AccountingService::createInvoiceGLEntries():106-108` opens `DB::transaction()` and writes straight into the hash chain with no validator call | **conditional-YES** — defence-in-depth on an **immutable** ledger; the ticket's own grading is "must not ship" | S | open (W-6 D1a) |
| **R-17** `[V]` ⚙ | No optimistic concurrency anywhere on money documents | A stale second save silently overwrites a draft money document | conditional — single-operator tenant → low likelihood, high impact | M | open (W-7 F-5) |
| **R-18** `[V]` ⚙ | Document discount cap never consults `users.max_discount_percent` | PERM-13/14's premise does not match the implementation | conditional | S | open (W-7 F-8) |
| **R-19** `[V]` ⚙ | `GET companies/{id}/reservation-settings` has **no middleware** | `Company/routes.php:34-35`; `CompanyController::getReservationSettings():226-238` is tenant-scope-only and returns the full settings blob **including anti-fraud thresholds**. Sibling `GET .../pos-settings` at `:42-43` is ungated too | NO — info disclosure to an authenticated same-tenant user | S | open (`2026-08-02-authz-gate-followups.md` F2) |
| **R-20** `[V]` ⚙ | No `Str::isUuid()` guard in `CompanyController` | `grep -n "isUuid\|Str::" CompanyController.php` → **no matches**. Per MEMORY, `where('uuid', $malformed)` **500s** in PostgreSQL | NO — DoS-adjacent | S | open (F4) |
| **R-21** `[V]` ⚙ | `POST /api/v1/companies` commits, then answers 500 | `CompanyController::formatCompany()` — `default_target_margin` and `default_minimum_margin` are passed unguarded to `CurrencyScale::bcformatOrNull((string) $company->…, 2)`, while the **very next field** `default_max_discount_percent` is guarded correctly. Both margin columns are `NOT NULL` with DB defaults (30/10) not passed by `Company::create()`, so the fresh in-memory model holds `null`; `(string) null` is `""`, defeating the null guard, and `bcformatStrict` throws. `store()` formats **outside** the committed transaction | **CONDITIONAL** — tenant #1's company comes from `TenantInitializationService`, so first boot is unaffected. But *every* company created through the documented API is created-and-then-500s → the caller retries and duplicates. Bites the first time anyone (or an onboarding script) adds a company | S | open (W-8 F-5). Fix: guard the two casts like the third, or `$company->refresh()` before formatting |
| **R-22** `[V]` 👤⚙ | Cross-COMPANY authorization break in Accounting | `JournalEntryController.php` — `$companyId = $this->companyContext->requireCompanyId()` at `:34`/`:127`/`:145`, then the queries at `:39`/`:132`/`:150` are `->where('tenant_id', $tenantId)` **only**. `grep company_id` on the file matches only `:94-95` and `:198-199` (the CREATE path). Company A can list, read and **post** company B's journal entries. Same shape at `AccountController.php:29-34` (`Account::forTenant($tenantId)`) → whole-tenant chart of accounts exposed. It survived because `$companyId` **is assigned**, so no unused-variable lint or PHPStan rule fires | **NO** for a single-company tenant #1. **YES, P0, for any multi-company tenant** | S + sweep | open (W-8 F-1). Worth a PHPStan rule that `requireCompanyId()`'s value must reach a query constraint |
| **R-23** `[V]` 👤 | Second company in a tenant cannot author a sales document | `documents_tenant_id_type_document_number_unique` is `(tenant_id, type, document_number)` while numbering counters (`companies.invoice_next_number`, …) are **per company**. Company 2's first invoice is `INV-2026-0001`, already owned by company 1 → **unhandled 500, `SQLSTATE[23505]`** | NO for tenant #1 | — | **owner ruling first** (§2.8) — *"a data-model ruling, not a test fix"* |
| **R-24** `[V]` ⚙ | Trial balance is currency-blind | One payload emits `debit` at 3 dp (`777.770`), `credit` at 4 dp (`777.7700`), the zero side at 2 dp (`0.00`), totals at 2 dp — **none derived from `companies.currency`**. EUR and TND companies emit byte-identically | NO — invisible on a TND-only tenant, which is exactly why nine waves missed it. **Launch-blocking for any multi-currency tenant**; asks for a plan amendment on `MTP-GL-25` | M | open (W-8 F-3) |
| **R-25** `[V]` ⚙ | Cash-report location scoping unimplemented on **both** layers | — | NO — single-location tenant | M | open (W-7 F-3) |
| **R-26** `[N]` ⚙ | Module-gating audit count is wrong | The canonical `module:Marketplace` gating folded into R-02 corrects the vertical-gating audit: **true count is ≥11 ungated of 24+2 modules, not the recorded 9/22** | NO directly — but the audit ledger is under-reporting its own scope | S (ledger) | fix-lane-in-flight with R-02 |
| **R-27** `[V]` ⚙ | Twelve operator/one-shot cat-(b) commands still query central | `EnqueueResolvedEventProjectionsCommand:121,155,350` · `BackfillFiscalHashesCommand:75-76,92,167,187` · `BackfillFiscalYears:53,84` · `AuditDiscountsCommand:64-65` (declared a pre-deploy/CI gate, annotation `:30` claims whole-dataset coverage — unachievable from central) · `BackfillGoodsReceiptsCommand:45,92,97,105` · `FixOrphanedProducts:40-42,71,78` · `GenerateProductImageVariants:30` · `MigrateParapharmacyDataCommand:43,135-349` | NO directly — they fail **loud** (42P01) rather than silently, except where a `Schema::hasTable()` guard converts "table absent" into "nothing to report" | M | **fix-lane-in-flight** (Wave 2 tail) |

### 3.3 P2 — record, do not gate the launch

| # | Item | Where | Status |
|---|---|---|---|
| **R-28** `[V]` ⚙ | Empty-period trial-balance totals render at scale 2 not the report's scale 4 | W-6 D3 | open |
| **R-29** `[V]` ⚙ | JE form balance indicator uses a float tolerance of `0.01` — a `0.005` TND gap renders "Balanced" and submits; only the server refuses | W-6 R2 | open |
| **R-30** `[V]` ⚙ | `GET /ledger` totals are page-scoped but labelled as if window-scoped | W-6 R3 | open |
| **R-31** `[V]` ⚙ | `PATCH /settings/company` accept-and-drop answers 200 not 422, and `GET` omits the fields so the drop is undetectable | W-7 F-9 | open |
| **R-32** `[V]` ⚙ | `/sales/*` UI closed to the cashier by a ROLE alias while the API grants `invoices.create` | W-7 F-1 | open |
| **R-33** `[V]` ⚙ | `allocated_amount` emitted unscaled (`"500"`) beside a correct scale-3 `unallocated_amount` | W-7 F-2b | open |
| **R-34** `[V]` ⚙ | `ProgramManagementService.php:130` throws a bare `InvalidArgumentException` → **500 instead of 404** for an unknown loyalty program id. Not a leak, but a 500 is never an acceptable refusal shape | W-8 F-4 | open |
| **R-35** `[V]` ⚙ | `POST companies` still ungated — intentional per the route comment (`Company/routes.php:25`), but see R-21: the same route 500s after committing | authz-followups F3 | open |
| **R-36** `[V]` ⚙ | `UpdateCompanyRequest::authorize()` (`:20-23`) still returns `true` unconditionally — the route middleware is the sole gate, so a future route-file edit silently reopens the closed hole. Belt-and-braces | C.2 residual | open |
| **R-37** `[V]` ⚙ | Seeder debt, systemic: `TwoTenantIsolationDemoSeeder.php:92-111` provisions databases + one raw `companies` insert — **no users, no `central_identities`, no roles/permissions, no CoA, no tax config, no payment methods/repositories, no fiscal years, no `fiscal_chain_seed`** (so `DocumentPostingService::getCompanyGenesisSeed():510` aborts every invoice post). **Campaign debt C-4 is NOT dischargeable by running it.** Same root as W-7 F-4 (`CoffeeShopSeeder` never records users in the central identity index → cannot log in under db-per-tenant): seeders skip `IdentityIndexService::record()` | W-8 F-6 / W-7 F-4 | open — test-fixture debt |
| **R-38** `[V]` ⚙ | Money-test-plan `DemoPharmacySeeder` pins are stale: `Clinique Al Amal` has zero documents; `Medis Distribution SARL`'s payable is unreachable. Affects campaign evidence, not production | W-6 R4 | open |
| **R-39** `[V]` ⚙ | Nine cat-(b) commands need annotation RE-JUSTIFICATION + a fail-closed `tenancy()->initialized` guard so a bare run says why instead of emitting 42P01 (`SeedChartsCommand:53,80`, `BackfillBanksCommand:60,87`, `BackfillTolerancePurposesCommand:72`, `ConfigureCashRoundingCommand:65,465,485`, `BackfillPricingModeCommand:21,44`, `RematchDraftSupplierInvoicesCommand:19,45`, `GrirDriftReportCommand:15,33`, `TestTaxRecoverability:19,48,147`, `TestE2EGLPosting:22,35,36`) | cat-(b) 1c | open |
| **R-40** `[N]` ⚙ | **The arch test cannot detect any of R-05/R-06/R-27.** `ConsoleCommandTenantContextTest` / `QueueJobTenantContextTest` only require a **non-empty justification string** — every CONVERT class passes today. Proposed ratchet: fail any `@cross-tenant-by-design` class whose body reaches a tenant-table model or `DB::table()` without `extends TenantScopedCommand`, `use BindsTenantContext`, or a `tenancy()->initialize(` call. The sweep AST visitors (`FindCallVisitor`) already have the machinery | cat-(b) §4.1 | open — **this is the structural fix**; without it the class of breakage recurs |
| **R-41** `[N]` ⚙ | `Schema::hasTable()` in a console/scheduler path is a silent-pass generator (`ChannelServiceProvider:32`, `PreflightFiscalGateCommand:77`). Grep the codebase for it as a follow-up sweep | cat-(b) §4.2 | open |
| **R-42** `[V]` ⚙ | Ticket `2026-07-31-deptrac-36-edges.md` transcript is one revision stale (`TOTAL 97 97`, `ModuleDomain 34`) and defers a burndown to `project_deptrac_phase_1_2_burndown.md`, which **does not exist** | deptrac residual | open — cosmetic |
| **R-43** `[V]` ⚙ | Productize the ad-hoc deploy steps: `accounting:seed-charts` (*"neither ad-hoc tinker form should survive to a real-tenant deploy"*, `treasury-phase2-deploy-checklist.md:46`); the bank backfill (`bank-directory-deploy-checklist.md:57-59`); the opening-balance backfill command is **still UNBUILT** | pre-launch tickets | open |

### 3.4 Done this session ✅

| # | Item | Evidence |
|---|---|---|
| **R-44** `[N]` ⚙ | **`BatchChainE2ETest` now has a CI slot** — closes the launch-program follow-up "BatchChainE2ETest runs in no gate" | `24bd8f941` — *ci(test): run BatchChainE2ETest in the pgsql merge gate*; proven green on pgsql locally |
| **R-45** ⚙ | `forEachTenant()` iterates ACTIVE tenants only (A7 scheduler noise) | `cb3c67d67` |

### 3.5 Verified CLOSED — do not re-raise

| Item | Closing commit | Evidence |
|---|---|---|
| `PUT /companies` + sibling mutations unauthorized | **`b9c0bd37a`** (+ `10ad37743` for `/settings/setup`) | `Company/routes.php:29-31,:37-39,:46-48` all `can:settings.update`; regression coverage `tests/Feature/Company/CompanyUpdateAuthorizationTest.php:73,116,142` |
| 5 `SUM(pos_receipts.total)` positive-refund aggregates | wave 4 | All 6 call sites era-aware via a per-row `CASE WHEN receipt_type = 'return' THEN -ABS(col) ELSE col END`: `PosAnalyticsService.php:39,174,203-204,337`, `GrandtotalService.php:236`, `ReportGenerationService.php:528`; helpers `:455`/`:473`. **The HARD pre-enable gate on `EnableV4RefundAuthoringCommand` is DISCHARGED** |
| Documents-W1 credit-note numbering collision | `0939fcab6` | `CreditNoteService.php:828,934,1127` delegate to `numberingService->generateNumber(...)`; `grep -rn generateCreditNoteNumber apps/api/app` → no matches |
| Documents-W1 FE credit-note routes | — | `apps/web/src/features/documents/api/creditNotes.ts:66,76` now POST `/credit-notes/{id}/confirm\|post` |
| Confirm-zeroes-VAT | `7258a409f` | ticket marked CLOSED 2026-08-02 |
| `/settings/setup` ungated | `10ad37743` | `Tenant/routes.php:34` gated (adjudicated P1 not P0) |
| Web-POS demo-only gate (server side) | — | `POS/routes.php:93` wraps the mutating group in `EnsureWebPosDemoTenant` (`…/Middleware/EnsureWebPosDemoTenant.php:31`). Residual is FE nav-hiding only |
| Tenant-observer deprovision under db-per-tenant | — | **Ticket text is wrong, code is already fixed:** `TenantObserver.php:69-72` delegates to `TenantTokenRevoker`, which at `app/Services/TenantTokenRevoker.php:52-65` wraps the user lookup in `$tenant->run(...)` with a `catch (Throwable)` fallback to `CentralIdentity`. **Close the ticket; no code work** |

---

## 4. Memory / ledger corrections

Each row is a factual error in the current memory or launch ledger, with the evidence that corrects it. **These are the traps most likely to mislead a successor session.**

| # | Ledger / memory says | Truth | Evidence | Action |
|---|---|---|---|---|
| **C-1** | Deptrac: *"97 vs baseline 61 on dev (pre-existing); bites at dev→main PR"* | **Deptrac PASSES at 98/98, exit 0.** All six categories held. Re-baselined `61 → 97` at `fa83963be` (owner-approved 2026-07-31), then `97/34 → 98/35` at **`ac165f2c2`** (2026-08-03). The +1 is a genuine new Domain→Application edge — `POS\Domain\Services\ReceiptHashService` constructor-injecting `POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer`, introduced by Lane C (`bf7b7ba90`) — now tracked as **named** baseline debt. CI job `backend-architecture` (`ci.yml:143-148,173-178`) runs the ratchet with **no `if:` guard**, on PRs to `main` **and** `dev` | ran `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` on clean local `dev`; `deptrac.baseline.json` `generated_at: 2026-08-03, total: 98` | **Correct the memory line. Deptrac is NOT a blocker and does not gate dev→main.** Note deptrac is not in `scripts/preflight.sh` and has no composer script — it is a custom ratchet wrapper documented at `deptrac.yaml:3-4` |
| **C-2** | `PUT /companies` authz closed by **`6ef9bc1f9`** | **`6ef9bc1f9` is docs-only.** `git show --stat 6ef9bc1f9` touches only `docs/superpowers/reviews/2026-08-02-documents-fixlane-gate.md` and `docs/superpowers/tickets/2026-08-03-f2f3-regate-carryovers.md` — **zero `apps/api` changes**. Correct attribution: **`b9c0bd37a`** (companies) and **`10ad37743`** (`/settings/setup` + onboarding/status) | see §3.5 | Fix the citation in the launch ledger |
| **C-3** | *"🎫 5 `SUM(pos_receipts.total)` aggregates = HARD PRE-ENABLE gate"* — reads as OPEN | **Satisfied in code**, all 6 call sites era-aware (§3.5). But the ticket file `2026-08-01-positive-refund-total-consumers.md` carries **no FIXED/CLOSED marker** — its last line `:18` still says *"fix in Lane C wave 3 … BEFORE the enable step"*, so it reads as OPEN on any text sweep, while `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:84` already calls it *"CLOSED by wave 4"*. The two disagree | `PosAnalyticsService.php:455,473` docblock `:434-453` names the ticket by filename | **Add a close-out header to the ticket** so the next auditor does not re-raise it. Pure housekeeping |
| **C-4** | *"permission-reseed debt"* recorded as a per-deploy chore of unknown origin | **Root cause identified:** `entrypoint.sh:156` runs `tenants:seed --class=RolesAndPermissionsSeeder` **only** when `SYNC_PERMISSIONS_ON_BOOT="true"` (`:153`), and that variable is **set in no in-repo compose file** — `docker-compose.staging.yml`, `docker-compose.dokploy.yml`, `docker-compose.sidebar-demo.yml` all have **zero occurrences**. **Correction (2026-08-05): the live staging API app sets `SYNC_PERMISSIONS_ON_BOOT=true` via Dokploy env** — staging DOES reseed on boot; the repo-invisible override is itself the hazard (a rebuilt/new environment silently loses the behavior). `permission:cache-reset` **does** run unconditionally at `:167`; on any environment lacking the env var, resetting a cache that was never repopulated is a no-op for this purpose | see R-09 | **Owner ruling (§2.8) + a line in E-10.** This is why W-X (R-11) and W-6 D5 (R-15) will not self-heal on deploy |
| **C-5** | `OWNER-INDEX-2026-08-03.md:3` — *"state of truth: `origin/dev = cce3491b6`"* | ~30 commits stale; local `dev` is 56 ahead of `origin/dev` | `git log origin/dev..dev --oneline \| wc -l` → 56 | Refresh after the §A push |
| **C-6** | Entrypoint assumed to do nothing at boot | **Better than assumed:** `migrate --force` (`:123`), `tenants:migrate-rolling --force` (`:141`, idempotent) and `permission:cache-reset` (`:167`) all run. `entrypoint-worker.sh` / `-scheduler.sh` / `-websocket.sh` **none** migrate, seed, or reset permissions | `apps/api/Dockerfile:108-112,157,168,176,188` | Record; it changes what E-10 must do by hand |
| **C-7** | E-10 environment decision treated as an open question | The **decision is made** (production, after the staging campaign passes) at `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:22-23`. It is the **transcription into the human-only gate sheet** that is missing | §2.1 | Owner transcribes; agents may not |
| **C-8** | Vertical-module-gating audit: *"9/22 modules ungated"* | **≥11 of 24+2** once `module:Marketplace` is counted canonically | R-02 / R-26 | Update the audit ledger |
| **C-9** | E-8 gate row: device **v64** | **v67** (`migrations.ts:2163`). Three docs give three different minimums | §2.3 | Owner-only correction |
| **C-10** | `DemoPharmacySeeder` believed production-gated | **Gated by convention, not by code.** Not invoked from `ProductionSeeder` (`:33-68`) nor `DatabaseSeeder` (`:40-161`), and no production call site exists — but there is **no `is_demo` gate and no `app()->environment()` gate inside the seeder**; `run():361-400` branches only on tenant-slug existence, and the tenant row it creates (`ParapharmacySeeder.php:497-520`) does **not** set `is_demo` (defaults `false`). The only real gate is the env var `SEED_DEMO_PHARMACY` (`entrypoint.sh:198`), defaulted true **only** in `docker-compose.staging.yml:56` and absent from `docker-compose.dokploy.yml`. Related: it never calls `CountryPaymentSettingsSeeder` (`cash-rounding-phase1-deploy-checklist.md:685`), so a demo tenant has no `country_payment_settings` row until Step 0 is run by hand | — | **Add an explicit E-10 line: confirm `SEED_DEMO_PHARMACY` is unset/false on production.** Cheap insurance |

---

## 5. Sequencing — today → tenant onboarding

Each arrow is a hard dependency. Nothing downstream may start before everything upstream is evidenced.

### Stage 0 — unblock the calendar (start today, 👤)

**Name the six second humans (§2.2).** E-2, E-3, E-4, E-6 and E-8 cannot START without them, and E-4's Tunisia legal reviewer is the single longest-lead item on the register. This is parallel to everything else and gates the tail of the whole plan — start it before any code work.

In parallel, the four gates with no second-human dependency can run now: **E-1** (secret rotation, via the Part C secrets store), **E-5** (run `scripts/preflight-runbooks.sh` — the cheapest gate on the sheet), **E-6** prep, **E-4** question dispatch.

### Stage 1 — code (⚙, parallelizable)

```
P0 fix wave                     cat-(b) Wave 1 (R-06)      cat-(b) Wave 2 (R-05)
R-01 R-03 R-04 R-07 R-08 R-11   automated surfaces         gate + verifier commands
R-02 (marketplace, in flight)   + onFailure() hooks        + PreflightFiscalGate must FAIL
        │                              │                       on a missing table
        └──────────────┬───────────────┴───────────────────────────┘
                       ▼
              R-40 arch-test ratchet (so the class does not recur)
```

**R-05 is on the critical path for E-7, not beside it.** §Y's final fiscal re-run and E-7's closure evidence are *produced by* `fiscal:verify-chains`, `pos:verify-chains` and `fiscal:verify-event-chain`. All three query central context today, and `fiscal:preflight-gate` **false-passes with exit 0** before a schema-destructive rebuild. **Until Wave 2 lands, a green verifier run proves nothing** — so §Y cannot be run for evidence, and E-7 cannot legitimately close.

### Stage 2 — §A staging push (👤 clearance)

`git fetch origin dev` → confirm clean fast-forward → push the 56 commits (+ the fix wave). Auto-deploys staging including `tenants:migrate`. Refresh `OWNER-INDEX` (C-5).

### Stage 3 — deploy-checklist execution on staging (⚙, R-09)

Execute the stacked checklists **in their documented order**, respecting:
- the `bank-directory:7` FK ordering gate (**do not use unscoped `tenants:migrate` across that boundary**),
- `treasury-phase5b:17-27` nine migrations in order (`110005` mandatory even where `110000`–`110004` already ran) and `:28` shared node-stable private storage,
- `cash-rounding-phase1` Step 1 → Step 2 token gates,
- the **terminal `fiscal_schema_version = 3` cutover** — HTTP endpoint only, no CLI (`cash-rounding-phase2:165,176-181`),
- `RolesAndPermissionsSeeder` + `permission:cache-reset` after every permission-touching step.

**Three foot-guns govern this stage:** `tenants:run` **always exits 0** → gate on output tokens, never `$?`; the `?Company $company = null` container trap silently no-ops `BanksSeeder`/`PaymentRepositorySeeder`; `pos:configure-cash-rounding --verify` prints `FAILURES: 0` on a missing `country_payment_settings` row — **read the output, not the token**.

### Stage 4 — staging campaign

```
D1: Playwright T1 — 192 P0 web cases
D2: POS §Z — 64 items, on a device at v67 (below 67, §Z evidence is VOID)
```

Both must pass on the **same** artifact. Device build (R-10) must be cut and installed before D2.

### Stage 5 — §Y final fiscal re-run

Depends on Stage 1's Wave 2 (R-05). **Tolerance note:** the re-run must either tolerate a shifted August VAT position or the fiscal fixtures must be reseeded — a re-run against drifted month-boundary data will produce a mismatch that is an artefact, not a defect. Decide which before executing, and record the choice in the evidence pack. Annotate or correct the stranded `19.000` D1b imbalance (§2.8) so it does not contaminate the pack.

### Stage 6 — E-7 closes 👤

Requires Stage 4 + Stage 5 evidence. **This is the non-waivable gate.** The NO-REFUNDS / NO-VOIDS prohibition lifts only here.

### Stage 7 — `dev` → `main` 👤

Gated on the pre-launch-audit ruling (§2.4): run Graphify + a fresh security audit + doc realignment, **or** explicitly waive the roadmap coupling. Deptrac will pass (C-1). E-9 runs top-to-bottom on the release candidate, including all **36 `RERUN-ON-FINAL-CANDIDATE`** steps regardless of history.

### Stage 8 — production deploy (E-10, Part C environment)

Per Part C: Hetzner VPS + Dokploy, db-per-tenant, backups/DR, secrets store. E-10's own seven steps apply, notably **(7) a FRESH backup on the ACTUAL target immediately before its migration — E-3's rehearsal backup can never satisfy this**. E-3 must have been rehearsed on a staging clone before this point. Add the explicit `SEED_DEMO_PHARMACY` confirmation (C-10) and the `SYNC_PERMISSIONS_ON_BOOT` ruling (C-4).

### Stage 9 — device + smoke, then first real sale

**E-8 before E-2**, in that order and on the same promoted artifact: manager briefing → device install at **v67** → `pos:configure-cash-rounding` dry-run then real **with explicit `--tenants=<uuid>`** (omitting it hits EVERY tenant) → verify device SQLite migration by query → E-2 smoke → **then** the first real transaction.

### Stage 10 — tenant onboarding

Only after every gate cell is filled by its named human.

---

## Appendix — counts at a glance

| Bucket | Count |
|---|---|
| Owner gates OPEN | 10 / 10 |
| Owner gates that cannot START (unnamed 2nd human) | 5 (E-2, E-3, E-4, E-6, E-8) |
| Executable register items | 45 (R-01 … R-45) |
| — P0 (blocks tenant #1) | 11 |
| — P1 | 16 |
| — P2 | 16 |
| — done this session | 2 |
| Verified CLOSED (do not re-raise) | 8 |
| Ledger corrections | 10 |
| cat-(b) CONVERT classes | 18 (6 automated + 12 operator/one-shot) |
| Deploy checkboxes ticked, across 9 checklists + 2 runbooks | **0** |
