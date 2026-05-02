# Tenant-Isolation Sweep — Master Plan (API + Web + Tauri + Super-Admin + Module-Gating)

> **STATUS: SUPERSEDED.** This document was the first attempt at the master
> spec. Codex's adversarial review (`docs/superpowers/reviews/2026-05-02-tenant-isolation-sweep-spec-codex-review.md`)
> returned verdict REQUIRES-DIFFERENT-APPROACH citing five blockers:
> incomplete API cluster coverage (missed Catalog/Contact/Compliance/Pricing/
> Service/Cart/Workshop), broken YAML cluster-id semantics, wrong Tauri
> application paths (it's `apps/pos/`, not `apps/desktop/`), POS orchestrator
> conflict, and code-vs-YAML source-of-truth ambiguity. All five are fixed
> in the unified master plan at:
>
>   `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md`
>
> which also integrates Codex's strategic foundation plan
> (`2026-05-02-tenant-isolation-nf525-database-foundation-plan.md`) and the
> certification SOT YAML (`2026-05-02-tenant-isolation-certification-sot.yaml`).
>
> Do NOT execute against this version — execute against the master plan only.

> **Status:** PROPOSAL — pending Codex adversarial review (verdict required before execution).
> **Supersedes:** `docs/superpowers/plans/2026-05-01-tenant-isolation-sweep-phase-b.md` (API-only, archived as the original Phase B; its contents are subsumed here and extended).
> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox `- [ ]` syntax. The YAML inventory at `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` is the coordination contract — claim work via the artisan commands, never edit by hand.

**Goal:** Close every unscoped multi-tenant resource lookup across the entire AutoERP product surface — Laravel API, React web app, Tauri desktop POS — and install permanent CI gates that prevent the regression class from re-emerging. Make the explicit tenant-scoping discipline survive the future migration to Stancl multi-database mode (the helper, tests, and convention doc apply to both row-level and multi-DB modes).

**Architecture:** Per Codex's adversarial review of the original B proposal (`docs/superpowers/reviews/2026-05-01-tenant-isolation-B-architecture-codex-review.md`, verdict REQUIRES-DIFFERENT-APPROACH applied):
- Three-method `ScopedExists` helper at `App\Shared\Presentation\Validation\ScopedExists` (no internal `auth()`/`app()`, parameter-explicit, supports tenant+company / tenant-only / company-only via separate factories — required because `users` and `companies` tables have no `company_id` column).
- FormRequests that need company context constructor-inject `CompanyContext` (matches the existing `UpdateCouponRequest` precedent — Rule #13 compliant).
- Sweep granularity is **module/callsite cluster**, not resource-family — same family appears across multiple modules with different parent contexts (e.g., `payment_methods` in POS vs Treasury vs Z-report manager flows).
- Per-cluster commit fixes BOTH validator rules AND the immediate service-layer resolver/writer in one atomic change.
- Three layers of permanent CI gates: presentation `exists:` gate, application-tier `find()` gate, web frontend `useQuery` queryKey gate.
- Two parallel agents: Claude Code (lead, plan, review, reference cluster, super-admin context, module-gating cluster). Codex (mechanical per-cluster sweeps, adversarial review per cluster). Coordination via the YAML inventory.

**Tech Stack:** Laravel 12 (PHP 8.4) + PostgreSQL 16 + PHPUnit 11 + PHPStan level 8 + Pint (API). React 19 + Vite 7 + TypeScript strict + TanStack Query 5 + Zustand 5 + Tailwind 4 (web). Tauri 2 + Rust + SQLite (desktop). Vitest (web tests).

**Branch:** `fix/tenant-isolation` (continuing on top of `fc5c0763` — A.1 already verified clean by Codex per `2026-05-01-tenant-isolation-A1-codex-rereview.md`).

---

## Out of scope (deferred — explicit list)

- **Migration to Stancl multi-database mode** — strategic decision; this work is the foundation for it. Mechanics in `docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md`.
- **RLS as defence-in-depth** — separate ~4-day project, not blocked by this sweep.
- **Frontend feature changes** beyond tenant-isolation (no UX redesigns, no behavior changes).
- **Module-gating UX changes** — sweep audits the security boundary; doesn't change how modules activate.
- **Synerivia platform integration changes** — outbound HTTP push works regardless of tenancy model.
- **Cross-tenant analytics / data platform** — separate scope, separate audit.

The original Phase B spec also explicitly deferred the POS cluster (sync, finalization, order, creation). **That deferral is reversed in this version per user direction 2026-05-02.** It re-folds in as a normal cluster.

---

## Surfaces and clusters

The sweep covers three surfaces. Each surface is broken into clusters that match natural module/feature boundaries. One cluster = one or more atomic commits + a regression test.

### API surface (Laravel backend)

| Cluster | Owner | Blocked by | Notes |
|---|---|---|---|
| treasury | claude | — | **Reference cluster.** Claude lands first to prove the pattern. |
| document | codex | api.treasury | Conversion, Refund, Pdf controllers + Document FormRequests. |
| inventory | codex | api.treasury | Batch, counting, stock reservation/adjustment. |
| taxation | codex | api.treasury | Withholding certificates, FEC export inputs. |
| loyalty | codex | api.treasury | Programs, member lookups. Note: `users`/`companies` are tenant-only. |
| accounting | codex | api.treasury | Ledger, partner balance, reconciliation. |
| pos-stabilization | codex | api.treasury | Re-folded from prior deferral: ReceiptSyncService, ReceiptCreationService, OrderManagementService, ReceiptFinalizationService, VoucherLookupService, VoucherRedemptionService voucher lookup. |
| super-admin-context | claude | api.treasury | New architectural primitive — explicit "super-admin context" boundary so cross-tenant access opt-in. |
| module-gating | claude | api.super-admin-context | enabled_extras updates, RequireModule middleware audit, Progression+Growth Advisor calls. |

### Web surface (React app)

| Cluster | Owner | Blocked by | Notes |
|---|---|---|---|
| tanstack-keys | codex | api.* | All `useQuery`/`useMutation` queryKeys must include tenant scope; cache invalidation on tenant switch. |
| form-selectors | codex | web.tanstack-keys | Forms with foreign-key UUID inputs; selectors only show current-tenant options. |
| stores-localstorage | codex | web.tanstack-keys | Zustand stores + localStorage; clear on logout/tenant switch. |
| super-admin-frontend | claude | api.super-admin-context | Super-admin pages need explicit "cross-tenant context" UI + matching API boundary. |

### Tauri surface (desktop POS)

| Cluster | Owner | Blocked by | Notes |
|---|---|---|---|
| sqlite-cache | codex | api.*, web.* | SQLite cache lifecycle on user/tenant switch; sync envelope row validation. |
| sync-envelope | codex | api.pos-stabilization | Sync envelope schema enforcement + tenant tagging. |

---

## YAML inventory — the coordination contract

The single source of truth for the sweep is `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`, generated and mutated via artisan commands defined in Section 8 below. Schema documented at `docs/superpowers/plans/tenant-isolation-sweep-inventory.example.yml`.

**Why YAML over a database table or markdown table:**
- Diff-friendly: PR reviewer sees exactly which callsites moved status.
- Schema-validatable: JSON Schema in CI rejects malformed mutations.
- Tooling-friendly: generation (greps + AST scans) writes YAML naturally; mutation commands deserialize/serialize cleanly.
- Plain-text auditable: no DB migration needed, no extra service to run, lives in the repo.
- Both Claude and Codex can read/write the same file via the artisan CLI without conflicts (commands acquire a file lock).

**Coordination model:**
- Each callsite has a unique id (e.g., `api.treasury.001`).
- Each cluster has an `owner` field (`claude` or `codex`) and a `status`.
- Agents claim a cluster via `php artisan sweep:inventory:claim --agent=<name> --cluster=<name>`. Refused if already owned.
- Agents resolve callsites via `php artisan sweep:inventory:resolve --id=<id> --commit=<sha> --test=<path::name>`. CI verifies the commit + test exist.
- Agents block callsites via `php artisan sweep:inventory:block --id=<id> --reason="..."`. Triggers human review.
- Aggregate progress via `php artisan sweep:inventory:status`. Surfaces in PR comments + the master spec's progress section.

**Update discipline:**
- Generation is idempotent — re-running the generator merges new findings without resetting state on already-claimed callsites.
- Status transitions append to `history[]`. Never silent overwrites.
- The architecture tests (Sections 4 + 9 + 11) consume the YAML to compute their failure messages — failure says "callsite api.treasury.001 still has bare exists rule" rather than abstract grep output.

---

## Pre-flight — confirm starting state

- [ ] **Step 0.1: Verify branch state**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git status -sb
# Expected: ## fix/tenant-isolation ... no uncommitted changes (the sweep starts from a clean tip)
git log --oneline -3
# Expected tip: fc5c0763 fix(pos): close service-layer tenant-isolation gap on customerId + tighten regression coverage
```

- [ ] **Step 0.2: Confirm A.1 Codex verdict**

Read `docs/superpowers/reviews/2026-05-01-tenant-isolation-A1-codex-rereview.md`. Verdict line 1 must be `A1-CLEAN-PROCEED-TO-B`.

- [ ] **Step 0.3: Confirm this spec has been adversarially reviewed**

Read `docs/superpowers/reviews/2026-05-02-tenant-isolation-sweep-spec-codex-review.md` (created during Codex review of THIS document). Verdict must be `APPROVE-AS-PROPOSED` or `APPROVE-WITH-MINOR-EDITS-APPLIED` (apply the edits before proceeding to Section 1).

- [ ] **Step 0.4: Preflight is clean**

```bash
cd apps/api
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
./vendor/bin/pint --test
php artisan test --exclude-group=sweep-progress
# Expected: all green except known pre-existing failures (HashGoldenByteTest at the time of writing)
```

---

## Section 1: ScopedExists helper + unit tests

(Identical content to the original Phase B Task 1. Reproduced here so this spec is self-contained.)

**Files:**
- Create: `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`
- Create: `apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php`

- [ ] **Step 1.1: Failing unit tests for the three factory methods.**

Test names: `tenantAndCompany_returns_exists_rule_with_both_predicates`, `tenant_returns_exists_rule_with_tenant_predicate_only`, `company_returns_exists_rule_with_company_predicate_only`, `null_tenant_id_translates_to_is_null_predicate`, `custom_column_name_is_honored`. Code reproduced in the original Phase B spec at `2026-05-01-tenant-isolation-sweep-phase-b.md` Task 1.1.

- [ ] **Step 1.2: Verify RED.**

- [ ] **Step 1.3: Implement helper.**

Code reproduced in the original Phase B spec at Task 1.3. Three factory methods, all parameter-explicit, no internal auth/app/context resolution.

- [ ] **Step 1.4: Verify GREEN. PHPStan + Pint clean. Commit.**

Owner: claude. Single commit.

---

## Section 2: Architecture-test CI gate (presentation tier)

(Identical content to original Task 2. Reproduced here for completeness.)

**Files:**
- Create: `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`

The `GUARDED_TABLES` list extends to include the secondary multi-tenant tables surfaced during the inventory pass: payment_methods, payment_repositories, partners, products, documents, users, companies, accounts, locations, payment_instruments, document_lines, pos_terminals, contacts, modifiers, modifier_groups, services. **Plus** any new tables added by the inventory pass.

The test runs in `@group sweep-progress` and is excluded from default CI runs until the sweep finishes. CI runs the group separately as informational.

Owner: claude. Single commit.

---

## Section 3: Correct architecture documentation

(Identical content to original Task 3.)

- `apps/erp/CLAUDE.md` line 111 — replace "schema-based multi-tenancy" with truthful description.
- `apps/erp/.claude/context/architecture.md` lines 54-57 — replace with truthful "shared-DB with row-level scoping" description.
- New `apps/erp/docs/conventions/08-TENANT-ISOLATION.md` — codebase-wide rule for scoping discipline.

Owner: claude. Single commit.

---

## Section 4: Inventory generation

**This is the new section that wasn't in the original Phase B spec.** Generates the YAML inventory by running mechanical scans across all three surfaces.

**Files:**
- Create: `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php`
- Create: `apps/api/app/Console/Commands/SweepInventoryClaimCommand.php`
- Create: `apps/api/app/Console/Commands/SweepInventoryResolveCommand.php`
- Create: `apps/api/app/Console/Commands/SweepInventoryBlockCommand.php`
- Create: `apps/api/app/Console/Commands/SweepInventoryStatusCommand.php`
- Create: `apps/api/app/Application/Sweep/InventoryService.php` (loads/saves YAML; validates schema)
- Create: `apps/api/app/Application/Sweep/InventoryYamlSchema.json` (JSON Schema)
- Create: `apps/api/tools/audit-tanstack-keys.mjs` (web scanner)
- Create: `apps/api/tools/audit-tauri-sync.mjs` (Tauri scanner)
- Generate: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` (output, gitignored? NO — committed, this is the coordination state)

- [ ] **Step 4.1: Implement `InventoryService`** — load/save YAML, validate against JSON Schema on every write, file-locked.

- [ ] **Step 4.2: Implement `SweepInventoryGenerateCommand`.**

The generate command runs four scans in sequence:

**Scan A — API presentation `exists:` rules.** Reuses the regex from `TenantScopedExistsRulesTest`. Emits one callsite per match.

**Scan B — API service `find()`/`findOrFail()`.** AST-based: parses each PHP file in `app/Modules/*/Application/`, `app/Modules/*/Domain/Services/`, and `app/Modules/*/Presentation/Controllers/` (latter for inline lookups). For each `Model::find($var)` or `Model::findOrFail($var)` call where `Model` is a guarded model class, looks back up to 5 lines for a `->where('tenant_id', ...)` filter. Flags if absent.

**Scan C — Web TanStack Query keys.** Parses every `.ts`/`.tsx` file in `apps/web/src/`. Greps for `useQuery({` and `useMutation({` calls. Inspects the queryKey array. Flags if it doesn't include a tenant/company scope variable (heuristic: must contain `companyId` or `tenantId` literal).

**Scan D — Tauri sync envelope handlers.** Reads `apps/desktop/src-tauri/src/` Rust files. Greps for sync envelope deserialization. Flags handlers that don't validate `tenant_id` against the current tenant context.

Each scan emits its findings as YAML rows. The merge logic preserves existing status/owner if the same callsite (same file+line+pattern) already exists in the inventory.

- [ ] **Step 4.3: Run inventory generation.**

```bash
php artisan sweep:inventory:generate
# Expected: ~120-150 callsites populated. Output prints a per-cluster summary.
git add docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
git commit -m "chore(sweep): initial inventory — N callsites across api/web/tauri"
```

- [ ] **Step 4.4: Implement claim/resolve/block/status commands.**

These mutate the YAML idempotently. Each is a few-dozen lines of Laravel command code.

- [ ] **Step 4.5: Document the agent coordination protocol in the convention doc.**

`docs/conventions/08-TENANT-ISOLATION.md` gets a new section:

```markdown
## Sweep coordination (Phase B)

The sweep runs against `tenant-isolation-sweep-inventory.yml` as the
single source of truth. Both Claude Code and Codex use the artisan CLI
to claim and resolve callsites:

  php artisan sweep:inventory:claim --agent=<name> --cluster=<name>
  php artisan sweep:inventory:resolve --id=<id> --commit=<sha> --test=<path::name>
  php artisan sweep:inventory:block --id=<id> --reason="..."
  php artisan sweep:inventory:status

Mutations are atomic via file lock + JSON Schema validation. Two agents
cannot claim the same cluster simultaneously.
```

Owner: claude. Single commit.

---

## Section 5: Treasury cluster (reference cluster — Claude executes)

**This is the load-bearing cluster.** Claude lands it first. Pattern proven here is the template for every other cluster.

**Files:** filled by inventory generation (Section 4) — `cluster: treasury` callsites in the YAML. Roughly 12-15 callsites expected.

- [ ] **Step 5.1: Claim the cluster.**

```bash
php artisan sweep:inventory:claim --agent=claude --cluster=treasury
```

- [ ] **Step 5.2: Read every callsite in the cluster.**

```bash
php artisan sweep:inventory:status --cluster=treasury --format=detailed
```

For each callsite, read the file at the cited line, verify the inventory's classification is correct, and (if it's a controller-inline-validation that should become a FormRequest) flag any structural decisions for human review BEFORE coding.

- [ ] **Step 5.3: Write the cluster regression test FIRST (TDD).**

`apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php` mirrors the shape of `apps/api/tests/Feature/POS/StoreReceiptPaymentsTenantIsolationTest.php`. Negative tests for cross-tenant + same-tenant/cross-company. Positive control. Tests fail until the fixes land.

- [ ] **Step 5.4: Verify RED.**

- [ ] **Step 5.5: Land fixes per callsite.**

For each callsite:
- Replace bare `exists:` with `ScopedExists::*`.
- Replace bare `find()`/`findOrFail()` with explicit `where('tenant_id'/'company_id')->findOrFail()`.
- For controller-inline-validation, refactor to FormRequest with constructor-injected `CompanyContext` (matches `UpdateCouponRequest` precedent).

- [ ] **Step 5.6: Verify GREEN.**

```bash
php artisan test --filter=TreasuryTenantIsolationTest
php artisan test --filter=Treasury  # broader suite, no regression
```

- [ ] **Step 5.7: PHPStan + Pint clean. Commit. Resolve callsites.**

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Treasury/
./vendor/bin/pint --test app/Modules/Treasury/ tests/Feature/Treasury/
git commit -m "fix(treasury): tenant-scope all multi-tenant resource lookups (cluster reference)"
# Then resolve each callsite:
php artisan sweep:inventory:resolve --id=api.treasury.001 --commit=<sha> --test="Tests\\Feature\\Treasury\\TreasuryTenantIsolationTest::test_cross_tenant_payment_method_id_rejected"
# ... for each callsite ...
```

- [ ] **Step 5.8: Hand to Codex for adversarial cluster review.**

```
Codex prompt: review treasury cluster fix at commit <sha>. Specifically:
- All callsites in cluster=treasury are resolved with status=fixed.
- No regression in the existing Treasury suite (271 tests).
- TenantScopedExistsRulesTest passes for the Treasury module subset.
- TenantScopedFindCallsTest (Section 9) passes for the Treasury module subset.
- The cluster regression test pins specific error keys / exception models per A.1 review's R5 hardening recommendations.
- No new gap introduced. Save review to docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md.
```

If review verdict is APPROVE: cluster done, unblocks the next clusters.
If REQUIRES-FIXES: address before proceeding.

---

## Section 6: Codex-driven clusters (parallel after Treasury proves the pattern)

Once Treasury is Codex-clean, the remaining API clusters can proceed in parallel. Codex claims them one at a time:

- **Document cluster** (`api.document.*`): DocumentConversionController, RefundController, DocumentPdfController + Document FormRequests.
- **Inventory cluster**: BatchExpiry, Counting, StockReservationService, StockAdjustmentService.
- **Taxation cluster**: WithholdingCertificate.
- **Loyalty cluster**: Programs, members. Note: `users`/`companies` are tenant-only (use `ScopedExists::tenant`).
- **Accounting cluster**: Ledger, PartnerBalanceService.
- **POS-stabilization cluster** (re-folded): ReceiptSyncService, ReceiptCreationService, OrderManagementService, ReceiptFinalizationService, VoucherLookupService, VoucherRedemptionService voucher lookup. The POS cluster is heavier than others because it touches fiscal-chain code paths — Codex must run the Voucher + Receipt + Refund + Fiscal test suites and confirm zero regression on each.

For each cluster, Codex follows the same template as Section 5:
1. Claim the cluster.
2. Read every callsite.
3. Write the regression test FIRST.
4. Verify RED.
5. Land fixes.
6. Verify GREEN.
7. PHPStan + Pint clean. Commit. Resolve callsites.
8. Adversarial review by Codex (self-review pass, then Claude reviews Codex's diff).

Each cluster is one or more atomic commits + one regression test file. Inventory is updated per resolution.

---

## Section 7: Super-admin context boundary (Claude — architectural primitive)

**The problem.** Super-admin routes (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php`, `apps/api/app/Modules/Admin/Presentation/Controllers/*`) are LEGITIMATELY cross-tenant by design. They manage all tenants from one panel. Every other route in the codebase is tenant-scoped via `auth()->user()->tenant_id` and `CompanyContext`. Today nothing distinguishes "this code is allowed to be cross-tenant" from "this code is missing scoping" — both look the same.

**The fix.** Introduce an explicit super-admin context primitive. Routes opt INTO cross-tenant access via middleware; the architecture test allows unscoped queries only inside super-admin context.

**Files:**
- Create: `apps/api/app/Http/Middleware/SuperAdminContext.php` (sets a request attribute / app singleton flag)
- Create: `apps/api/app/Shared/Presentation/Validation/CrossTenantExists.php` (a sibling to `ScopedExists` for super-admin contexts; explicitly says "I want a row from any tenant")
- Modify: `apps/api/routes/api.php` (super-admin route group gets `SuperAdminContext` middleware)
- Modify: `tests/Architecture/TenantScopedExistsRulesTest.php` (skip files inside super-admin route paths)
- Modify: `tests/Architecture/TenantScopedFindCallsTest.php` (same skip)

- [ ] **Step 7.1: Failing test for super-admin context flag.**

Test asserts that a route inside the super-admin group has `SuperAdminContext::isActive() === true` during the request, and a route outside has it false.

- [ ] **Step 7.2: Implement `SuperAdminContext` middleware** — sets a request attribute. Lifecycle bound to the request.

- [ ] **Step 7.3: Implement `CrossTenantExists::cross()` helper** — `Rule::exists($table, $column)` with no where clauses, but only callable when `SuperAdminContext::isActive()` is true (throws RuntimeException otherwise to catch accidental usage).

- [ ] **Step 7.4: Apply `SuperAdminContext` middleware to the super-admin route group.**

- [ ] **Step 7.5: Update architecture tests to skip super-admin paths.** They're allowed to use `CrossTenantExists::cross` and bare `find()` — but only inside `SuperAdminContext::isActive()` blocks.

- [ ] **Step 7.6: Audit existing super-admin code.** Where it has the old patterns, leave as-is (already cross-tenant by design). Where it has scoped patterns that were defensive copy-paste, document them as intentional defence-in-depth.

- [ ] **Step 7.7: Regression test.** A non-super-admin user hitting a super-admin route is rejected (403) BEFORE the SuperAdminContext middleware runs.

Owner: claude. One commit.

---

## Section 8: Module-gating sweep (Claude)

**The problem.** The module-gating system (`tenants.enabled_extras` JSONB, `RequireModule` middleware, `SuperAdminController::updateExtras`, `ProgressionService`) is a multi-tenant trust boundary in two ways:
1. The `updateExtras` endpoint accepts a `tenant_id` from the URL and `enabled_extras[]` from the body. Super-admin only — but cross-tenant by design, must use the new super-admin context primitive.
2. `ProgressionService::activateModule` calls the external Growth Advisor service. If the activation request is mis-targeted, a tenant could activate a module on another tenant's account.
3. `RequireModule` middleware reads from `CompanyConfigService::getConfigForTenant`, which is cached per-tenant for 24h. The cache key MUST include tenant scope (verify it does).

**Files** (filled by inventory generation):
- `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php` (updateExtras)
- `apps/api/app/Modules/Progression/Application/Services/ProgressionService.php`
- `apps/api/app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php`
- `apps/api/app/Http/Middleware/RequireModule.php`
- `apps/api/app/Services/CompanyConfigService.php`

- [ ] **Step 8.1: Audit each file's tenant scope.**

For each, confirm:
- The route is inside the super-admin group (uses `SuperAdminContext` middleware) IF cross-tenant by design.
- Cache keys include tenant scope (no cross-tenant cache poisoning).
- External service calls (Growth Advisor) include tenant identifier and validate the response targets the intended tenant.

- [ ] **Step 8.2: Write regression tests.**

`apps/api/tests/Feature/ModuleGating/ModuleGatingTenantIsolationTest.php`:
- Tenant A's super-admin cannot activate a module on tenant B (without super-admin role).
- A regular tenant user cannot call `updateExtras` (403).
- A super-admin's `updateExtras` call correctly updates only the targeted tenant's `enabled_extras`.
- ProgressionService::activateModule includes the tenant identifier in the Growth Advisor call AND validates the response.
- CompanyConfigService cache keys include tenant_id.

- [ ] **Step 8.3: Verify RED → GREEN → commit.**

Owner: claude. One or two commits.

---

## Section 9: Architecture-test CI gate (application tier — service `find()` calls)

**Files:**
- Create: `apps/api/tests/Architecture/TenantScopedFindCallsTest.php`

**The mechanism.** Scans `app/Modules/*/Application/` and `app/Modules/*/Domain/Services/` for `(GuardedModel)::find(` and `(GuardedModel)::findOrFail(` patterns. For each match, looks back up to 5 lines (or matches a fluent chain on the same statement) for `->where('tenant_id', ...)` filter. Flags if absent.

Files inside super-admin paths (per Section 7's allowlist) are skipped.

- [ ] **Step 9.1: Implement the test using PHP-Parser AST traversal** (rather than regex — service code has multi-line method chains that regex misses).

- [ ] **Step 9.2: Run against current state.** Initial violation list IS the inventory for service-layer callsites.

- [ ] **Step 9.3: Add to `@group sweep-progress`.** Excluded from default test run until the sweep clears it.

Owner: claude. One commit.

---

## Section 10: Web TanStack Query queryKey audit (Codex cluster)

**The problem.** TanStack Query caches by queryKey. If the queryKey doesn't include tenant scope, switching tenants (via super-admin impersonation flow) shows tenant A's data while authenticated as tenant B until the cache expires.

**Files** (filled by inventory generation Scan C):
- All files matching `apps/web/src/**/*.ts(x)` containing `useQuery(` or `useMutation(`.

- [ ] **Step 10.1: For each `useQuery`, ensure queryKey includes a tenant-scope variable.**

Pattern:

```typescript
// Wrong:
useQuery({
  queryKey: ['payment-methods'],
  queryFn: fetchPaymentMethods,
})

// Right:
const { companyId } = useCompanyConfig()
useQuery({
  queryKey: ['payment-methods', companyId],
  queryFn: fetchPaymentMethods,
  enabled: !!companyId,
})
```

- [ ] **Step 10.2: Audit `queryClient.invalidateQueries` calls** — ensure they trigger on company switch.

- [ ] **Step 10.3: Audit logout flow.** `queryClient.clear()` must be called on logout AND on tenant impersonation in/out.

- [ ] **Step 10.4: Add CI gate.**

`apps/web/tools/audit-tanstack-keys.test.ts` (a Vitest architecture test): scans for `useQuery`/`useMutation` calls whose queryKey doesn't include `companyId`/`tenantId` literal. Fails the build.

- [ ] **Step 10.5: Regression test.** Switch tenants in a test flow, assert no stale cache.

Owner: codex. One commit per ~10 callsites cluster (groups of features).

---

## Section 11: Web form selectors + stores (Codex cluster)

**The problem.** Frontend forms have selectors (dropdowns, autocomplete) that fetch lists. If the list endpoint doesn't filter by tenant (covered in API sweep), backend rejects. But frontend should ALSO filter — defence-in-depth + better UX.

Zustand stores + localStorage may persist tenant data across sessions.

**Files** (filled by inventory generation):
- `apps/web/src/features/*/components/*Selector*.tsx`
- `apps/web/src/features/*/components/*Picker*.tsx`
- `apps/web/src/stores/*.ts`
- `apps/web/src/lib/storage.ts` (or equivalent)

- [ ] **Step 11.1: For each selector, ensure list query is tenant-scoped (queryKey audit covers this; verify here).**

- [ ] **Step 11.2: For each Zustand store, audit persist middleware.** If it persists tenant-scoped data, must clear on tenant switch.

- [ ] **Step 11.3: localStorage audit.** Tenant data must namespace by tenant id; clear on logout.

- [ ] **Step 11.4: Cross-vertical localhost conflict** (per project memory `feedback_modal_fixed_size.md` and IziPOS/Otospex shared localhost). Multi-tenant compounds the issue. Document the resolution.

- [ ] **Step 11.5: Regression test.** Vitest test that simulates tenant switch and asserts stores are cleared.

Owner: codex. Per-feature-cluster commits.

---

## Section 12: Super-admin frontend cluster (Claude)

**The problem.** Super-admin pages (`apps/web/src/features/admin/*`) render data from multiple tenants. Need an explicit "I am operating cross-tenant" UI affordance + matching API context boundary.

**Files** (per investigation):
- `apps/web/src/features/admin/pages/TenantsPage.tsx`, `TenantDetailModal.tsx`, etc.

- [ ] **Step 12.1: Audit how super-admin pages call the API.** Confirm they hit super-admin-only routes (which use `SuperAdminContext` middleware from Section 7).

- [ ] **Step 12.2: Add UI affordance.** A persistent banner / mode indicator that says "Super-admin mode — viewing N tenants." Prevents confusion.

- [ ] **Step 12.3: Audit super-admin's queryKeys.** They legitimately fetch across tenants — queryKey should include `'super-admin'` namespace prefix and tenant filter when filtering. Don't apply the "must include companyId" rule from Section 10 here.

- [ ] **Step 12.4: Regression test.** A non-super-admin user navigating to a super-admin page is redirected.

Owner: claude. One commit.

---

## Section 13: Tauri SQLite cache (Codex cluster)

**The problem.** Tauri's SQLite cache persists data on the desktop install. Per project memory, multiple verticals share `localhost`. If a cashier signs out of tenant A and a different cashier signs into tenant B on the same install, the SQLite cache from A may leak into B's UI.

**Files** (filled by Scan D):
- `apps/desktop/src-tauri/src/db/*.rs`
- `apps/desktop/src-tauri/src/sync/*.rs`
- `apps/desktop/src/lib/sqlite.ts` (TS-side wrappers)

- [ ] **Step 13.1: Audit cache lifecycle.** On user logout / tenant switch, the SQLite database file must be DELETED (or fully cleared) before the new tenant's data syncs.

- [ ] **Step 13.2: Tag every cached row with tenant_id.** Reject incoming sync envelope rows where tenant_id != current_tenant.

- [ ] **Step 13.3: Sync envelope schema.** Every row in the sync envelope must carry `tenant_id` + `company_id`. The Tauri client validates these match the authenticated context.

- [ ] **Step 13.4: Regression test.** Rust test that simulates tenant switch and asserts the cache is fully clean.

Owner: codex. Two-three commits (cache lifecycle, sync envelope, tests).

---

## Section 14: Tauri sync-envelope cluster (Codex)

Covered partially in Section 13. Specific concerns:
- The sync envelope schema between API and Tauri.
- Activation flow: which tenant context is the desktop in when offline?

- [ ] **Step 14.1: Document the sync envelope schema.** Pin tenant_id + company_id as required fields.

- [ ] **Step 14.2: API-side: API serializes the envelope with tenant_id from auth.user.tenant_id. Verify.**

- [ ] **Step 14.3: Tauri-side: deserializer rejects mismatched tenant.**

- [ ] **Step 14.4: Activation flow audit.** When the desktop boots offline, how does it know which tenant context it's in? Audit the activation flow + tenant token storage.

Owner: codex. Two commits.

---

## Section 15: Final verification + PR

- [ ] **Step 15.1: Strip `@group sweep-progress` markers** once all clusters are clean.

- [ ] **Step 15.2: Full preflight** across API + web + Tauri.

```bash
cd apps/api && ./scripts/preflight.sh
cd apps/web && pnpm test && pnpm typecheck && pnpm lint
cd apps/desktop && pnpm tauri test  # or whatever the Tauri test command is
```

- [ ] **Step 15.3: Run the inventory status report.**

```bash
php artisan sweep:inventory:status
# Expected: 100% fixed, zero pending/blocked.
```

- [ ] **Step 15.4: PR `fix/tenant-isolation` → `dev`.**

PR body includes:
- The diagnostic memory + Codex reviews as references.
- The cluster-by-cluster commit list.
- The architecture-test enforcement story.
- The corrected docs as a load-bearing artefact.
- The inventory YAML's final status.

- [ ] **Step 15.5: Hand to user for `dev → main` promotion.**

---

## Out-of-scope items deliberately left for follow-up

| Item | Where it lives | Why deferred |
|---|---|---|
| Migration to Stancl multi-DB mode | Separate strategic project | See `docs/superpowers/research/2026-05-02-multi-tenancy-architecture-survey.md` and `docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md`. |
| Adding RLS as defence-in-depth | Separate ~4-day project | Not blocked by this sweep. |
| Frontend feature changes beyond tenant-isolation | Separate work | This sweep is security/correctness, not UX. |
| SKU packaging refinement (verticals.php for "POS-only" SKU) | Product strategy work | Not blocked by this sweep — module gating system already supports it. |

---

## Self-review checklist (run before handing to Codex)

- [ ] All three surfaces (API, web, Tauri) covered.
- [ ] Super-admin context boundary explicit (Section 7).
- [ ] Module-gating audited (Section 8).
- [ ] POS cluster re-folded back in (Section 6 list).
- [ ] YAML inventory schema documented (Section 4 + example file).
- [ ] Coordination protocol via artisan commands documented.
- [ ] No placeholder steps. Every step has actual command or actual file path.
- [ ] Method signatures consistent across helper + usages + architecture tests.
- [ ] Convention doc updates referenced.
- [ ] Cluster ownership assigned + blocking dependencies declared.
- [ ] Adversarial review checkpoint (Step 0.3) gate before any execution.
