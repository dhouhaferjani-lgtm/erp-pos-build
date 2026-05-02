# Tenant-Isolation + NF525 Foundation — Unified Master Plan

> **Status:** PROPOSAL — pending one final adversarial review of THIS unified document before execution starts.
>
> **Supersedes:**
> - `docs/superpowers/plans/2026-05-01-tenant-isolation-sweep-phase-b.md` (API-only original Phase B)
> - `docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md` (first attempt at master spec — flawed per Codex review)
>
> **Integrates:**
> - `docs/superpowers/plans/2026-05-02-tenant-isolation-nf525-database-foundation-plan.md` (Codex's strategic foundation plan)
> - `docs/superpowers/reviews/2026-05-02-tenant-isolation-sweep-spec-codex-review.md` (Codex's required-different-approach review — every applied fix is annotated)
>
> **Source-of-truth artifacts:**
> - `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — generated callsite inventory (one row per code site, mechanically scanned)
> - `docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml` — workstream-level certification + DB-hardening + e-invoicing tracking (Codex-authored, 14 items across 6 workstreams)
>
> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox `- [ ]` syntax. Both YAMLs are mutated only via the artisan commands defined here — never edit by hand.

---

## Bottom line

The current AutoERP runtime is shared-database PostgreSQL with row-level `tenant_id`/`company_id` columns. Live DB has zero RLS policies, no per-tenant schemas, no Stancl multi-DB activation. The architectural documentation said otherwise; that's been corrected. The team's commercial position — first paying customers in Tunisia (no NF525 requirement, no PDP/e-invoicing requirement), France/regulated coming later — gives a runway to do this properly without breaking what exists.

This plan covers the **tactical** code-level sweep across API + web + Tauri + super-admin + module-gating + POS, AND the **strategic** layer above it (DB audit baseline, composite FK guardrails on POS fiscal tables, optional RLS pilot, NF525 certification evidence pack, French e-invoicing track, future ERP DB-per-tenant migration).

**Two layers, two YAMLs, one plan.**

| Layer | Scope | YAML | Owner | Time |
|---|---|---|---|---|
| Tactical | Per-callsite scoping fixes across API/web/Tauri | `tenant-isolation-sweep-inventory.yml` (mechanically generated) | Claude + Codex | ~1.5-2 weeks |
| Strategic | DB hardening, NF525 evidence, e-invoicing, ERP migration | `2026-05-02-tenant-isolation-certification-sot.yaml` (Codex-curated) | Human-driven decisions, Claude/Codex execute | ~6-12 weeks (sequenced) |

Tactical is the prerequisite for strategic. Strategic is sequenced — not all of it happens before launch.

---

## Hard constraints surfaced by Codex's foundation plan

These dates are fixed by external regulators. Plan must respect them.

| Constraint | Date | Impact |
|---|---|---|
| France POS self-certification ends | **2026-08-31** | After this, software must use accredited certification. AutoERP's current shared-DB posture is acceptable for self-cert if app-layer scoping is hardened, but accredited cert demands stronger evidence. |
| France accredited POS certification required | **2026-09-01** | Need certification evidence pack + accredited certifier engagement before this. |
| France e-invoicing rollout starts | **2026-09-01** | All companies must be able to RECEIVE e-invoices; large/ETI companies must ISSUE/e-report. AutoERP B2B customers will need PDP integration. |
| Tunisian POS launch (current target) | TBD | No NF525 requirement, no PDP requirement. Tactical sweep is sufficient. |

**Implication:** The tactical sweep is launch-blocking for Tunisia. The strategic layer is launch-blocking for France/EU regulated markets. We can launch Tunisia after tactical lands; France launches after strategic Phase 4 (NF525 evidence pack) lands.

---

## What changed since the previous master spec

This plan applies every required-different-approach edit from Codex's review:

| Codex review finding | Applied fix |
|---|---|
| 117 bare `exists:` matches across 12 modules; spec only listed 7 clusters | Cluster list is now generated from inventory, not hand-written. Sections 6 + 7 enumerate all 13 known API clusters: treasury, document, inventory, taxation, loyalty, accounting, catalog, contact, compliance, pricing, service, cart, workshop, identity-company. POS-specific cluster blocked on POS orchestrator branch. |
| YAML cluster names mixed (`treasury` vs `api.treasury`) | Canonical id `cluster_id: api.treasury` everywhere. `display_name` is presentational only. |
| Status values disagreed (cluster=`done`, callsite=`fixed`) | Single status enum: `pending → claimed → in_progress → under_review → fixed → blocked → deferred → needs_recheck`. |
| `history[]` schema undefined | Documented inline (Section 4). |
| Tauri paths wrong: `apps/desktop/...` doesn't exist; SQLite is in `apps/pos/src/lib/db.ts` (TypeScript), not Rust | Sections 13-14 corrected to `apps/pos/src/lib/db.ts`, `apps/pos/src/lib/db/migrations.ts`, `apps/pos/src/lib/sync/syncService.ts`. Rust scanner removed. |
| POS re-fold conflicts with POS orchestrator branch (`2026-04-30-pos-consolidation-checkpoint.md`) | POS-specific tenant-isolation work is BLOCKED on the POS orchestrator branch's merge. Tracked but not parallel. |
| CI gates ambiguous on source of truth (file+line identity, YAML-derived pass/fail) | Code is source of truth. Tests parse code and fail on unsafe patterns. YAML provides ownership/status metadata for failure messages, never derives pass/fail. Stable callsite identity = AST fingerprint, not file+line. |
| `CrossTenantExists::cross()` helper proposed for super-admin context | Replaced with `#[CrossTenantRoute(reason: "...")]` PHP attribute on controller methods + `cross_tenant` middleware alias. Tests inspect route definitions, not paths. |
| Module-gating exploit class overbroad (`updateExtras` is super-admin-only and not exploitable; cache key already correctly tenant-scoped) | Module-gating cluster narrowed to ONE real bug: `ProgressionService` ignores validated `CompanyContext` and trusts raw `X-Company-Id` header. |
| Self-review by same agent isn't adversarial | Different reviewer per cluster: Claude fixes Treasury → Codex reviews; Codex fixes a cluster → Claude reviews. |
| Treasury checkpoint not a hard gate | Hard gate: no worker may claim non-Treasury API clusters until `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` verdict is `APPROVE` and YAML cluster status is `fixed`. |
| Cluster ownership not enforced in `can_claim` | Explicit `can_claim` lists per agent, override requires `--force --reason --approved-by`. |
| PlatformIntegration outbound, Stripe webhook, broadcast channels, scheduled jobs (DailyExpiryCheck crosses tenants) missing | All added as new clusters in Section 6. |
| Stancl multi-DB compatibility claim overstated | Mode-switch infrastructure: `TENANCY_MODE=row_level` (current) vs `TENANCY_MODE=multi_db` (future). Architecture tests parameterize on it. |
| Strategic context (NF525 deadlines, DB hardening, e-invoicing) missing | Sections 16-21 cover the strategic phases, sequenced after tactical. |

---

## Pre-flight gate — confirm starting state

Before any execution:

- [ ] **Step 0.1: Branch state.** On `fix/tenant-isolation`, tip is `457af457` (planning bundle), no uncommitted changes.
- [ ] **Step 0.2: Prior work cleared.** `docs/superpowers/reviews/2026-05-01-tenant-isolation-A1-codex-rereview.md` verdict = `A1-CLEAN-PROCEED-TO-B`.
- [ ] **Step 0.3: This plan adversarially reviewed.** `docs/superpowers/reviews/2026-05-02-tenant-isolation-master-plan-codex-review.md` verdict = `APPROVE-AS-PROPOSED` or `APPROVE-WITH-MINOR-EDITS-APPLIED`. Apply edits before Section 1.
- [ ] **Step 0.4: Existing tests green** except known pre-existing `HashGoldenByteTest`.
- [ ] **Step 0.5: POS orchestrator branch state confirmed.** Read `docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md`. POS-specific clusters in Section 6 are BLOCKED until that branch merges OR the POS orchestrator explicitly hands ownership.

---

## Tactical phase — code-level sweep (Sections 1-15)

Owners: Claude Code + Codex via the callsite inventory YAML.

### Section 1: Foundation — `ScopedExists` helper + unit tests

**Files:** `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`, `apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php`.

Three factories: `tenantAndCompany($table, $tenantId, $companyId)`, `tenant($table, $tenantId)`, `company($table, $companyId)`. Pure factory, no internal `auth()`/`app()`. FormRequests inject `CompanyContext` per `UpdateCouponRequest` precedent.

Owner: claude. Single commit.

### Section 2: Cross-tenant route attribute (replaces `CrossTenantExists` helper)

**Files:**
- Create: `apps/api/app/Shared/Architecture/CrossTenantRoute.php` (PHP attribute)
- Create: `apps/api/app/Http/Middleware/CrossTenantContext.php` (sets request attribute)
- Create: alias `cross_tenant` in `bootstrap/app.php`

PHP attribute applied to controller methods that legitimately operate across tenants:

```php
use App\Shared\Architecture\CrossTenantRoute;

class SuperAdminController
{
    #[CrossTenantRoute(reason: "Super-admin manages all tenants from one panel")]
    public function updateExtras(string $id, Request $request) { ... }
}
```

Architecture tests inspect route definitions and controller method attributes — not path strings. A controller method without the attribute can't legitimately bypass scoping.

Owner: claude. Single commit.

### Section 3: Architecture-test CI gates (code as source of truth)

Three gates:

**Gate A — Presentation `exists:` rules.** `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`. Scans every Presentation-tier PHP file for bare `exists:<guarded_table>` patterns. Fails build. Skipped if controller method has `#[CrossTenantRoute]`.

**Gate B — Application-tier `find()`/`findOrFail()`.** `apps/api/tests/Architecture/TenantScopedFindCallsTest.php`. AST-based via `nikic/php-parser` (added as dev dependency). Scans `app/Modules/*/Application/`, `app/Modules/*/Domain/Services/`, `app/Modules/*/Presentation/Controllers/`. Flags guarded-model `find()`/`findOrFail()` calls without preceding `where('tenant_id', ...)`/`where('company_id', ...)` filter. Skipped if class has `#[CrossTenantRoute]` or method has local `// @cross-tenant-by-design <reason>` annotation.

**Gate C — Web TanStack Query keys.** `apps/web/tools/audit-tanstack-keys.mjs` run via `pnpm test:arch`. TS-AST scanner (TypeScript compiler API). Approved key factories: `tenantScopedKey()`, `currentCompanyId`, `companyStore.currentCompanyId`, super-admin namespace prefix `'super-admin'`/`'admin'`.

All three gates run in CI. Initially marked `@group sweep-progress` so they're informational while the sweep is in flight; default test run excludes them. Once tactical sweep is complete, the group is removed and the gates become hard CI requirements.

**Code is source of truth:** if gate fails, code is unsafe. YAML status is metadata for the failure message ("callsite api.treasury.001 owner=codex still has bare exists rule"). YAML cannot mark a callsite `fixed` while the gate fails — `sweep:inventory:resolve` runs the relevant gate against the resolved file before accepting the mutation.

Owner: claude. One commit per gate.

### Section 4: YAML inventory schema (canonical IDs, fixed)

**File:** `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` (live, generated and mutated). Schema documented at `docs/superpowers/plans/tenant-isolation-sweep-inventory.example.yml` (UPDATED to match this section).

Required schema corrections from Codex review:

```yaml
metadata:
  schema_version: "2"  # bumped from 1 — incompatible changes
  spec_version: "2026-05-02-master"
  spec_path: "docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md"
  branch: "fix/tenant-isolation"
  base_commit: "457af457"
  generated_at: "2026-05-02T..."
  generated_by: "claude" | "codex"
  schema_sha256: "..."  # for optimistic concurrency

agents:
  claude:
    role: "lead, plan, review, complex refactors, reference cluster, security-architecture clusters"
    can_claim:
      - "api.treasury"
      - "api.super-admin-context"
      - "api.module-gating"
      - "web.super-admin-frontend"
      - "tactical.foundation.*"  # Sections 1-3 (helper, attribute, CI gates)
    can_review: ["*"]
  codex:
    role: "mechanical per-cluster sweeps, adversarial review"
    can_claim:
      - "api.document"
      - "api.inventory"
      - "api.taxation"
      - "api.loyalty"
      - "api.accounting"
      - "api.catalog"
      - "api.contact"
      - "api.compliance"
      - "api.pricing"
      - "api.service"
      - "api.cart"
      - "api.workshop"
      - "api.identity-company"
      - "api.platform-integration"
      - "api.webhooks-incoming"
      - "api.broadcast-channels"
      - "api.scheduled-jobs"
      - "tauri.*"
      - "web.tanstack-keys"
      - "web.form-selectors"
      - "web.stores-localstorage"
    can_review: ["*"]

statuses_enum:
  - "pending"        # detected, not yet claimed
  - "claimed"        # owner set, work not started
  - "in_progress"    # work started
  - "under_review"   # fix landed, waiting for adversarial review by another agent
  - "fixed"          # review approved, callsite resolved
  - "blocked"        # cannot proceed; reason required
  - "deferred"       # acknowledged but explicitly out of scope; reason required
  - "needs_recheck"  # code at this callsite changed; status auto-reset on regeneration

clusters:
  - id: "api.treasury"               # canonical id; all references use this exact form
    display_name: "Treasury"
    surface: "api"
    owner: null
    required_owner: "claude"          # only this agent can claim, unless --force --approved-by
    status: "pending"
    blocked_by: []                    # references other cluster ids (canonical form)
    blocks: ["api.document", "api.inventory", "api.taxation", "api.loyalty", "api.accounting", "api.catalog", "api.contact", "api.compliance", "api.pricing", "api.service", "api.cart", "api.workshop", "api.identity-company", "api.platform-integration", "api.webhooks-incoming", "api.broadcast-channels", "api.scheduled-jobs", "web.*", "tauri.*"]
    is_reference: true
    expected_callsite_count: null     # filled by inventory generation
    test_file: "apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php"
    review_file: "docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md"
    notes: "Reference cluster. HARD GATE: no other API cluster may begin until this review verdict is APPROVE and YAML status is fixed."

callsites:
  - id: "api.treasury.001"
    stable_key: "sha256:<ast-fingerprint>"   # NOT file+line. Survives line-shift edits.
    surface: "api"
    cluster_id: "api.treasury"
    scanner: "php_presentation_exists"        # which scanner detected it
    file: "apps/api/app/Modules/Treasury/Presentation/Requests/RefundPrepaymentRequest.php"
    line: 23                                   # display metadata only — never used as identity
    symbol: "App\\Modules\\Treasury\\Presentation\\Requests\\RefundPrepaymentRequest::rules"
    pattern_type: "bare_exists_validator"
    resource: "payment_methods"
    expected_scope: "tenant_and_company"      # tenant_and_company | tenant_only | company_only | parent_context | cross_tenant
    expected_fix: "Replace 'exists:payment_methods,id' with ScopedExists::tenantAndCompany"
    severity: "high"                           # high | medium | low
    fiscal_path: false                          # touches NF525 hash chain or fiscal data?
    cross_module: false
    status: "pending"
    owner: null
    claimed_at: null
    review:
      reviewer: null                           # different agent than owner
      verdict: null                            # APPROVE | REQUEST-CHANGES | BLOCK
      reviewed_at: null
    fix_commit: null
    regression_test: null                      # tests/path::test_name once resolved
    blocked_reason: null
    history:
      - at: "2026-05-02T00:00:00Z"
        actor: "generator"
        action: "generate"
        from_status: null
        to_status: "pending"
        commit: null
        test: null
        note: "initial detection"

progress:
  total_callsites: 0
  total_clusters: 0
  by_status: { pending: 0, claimed: 0, in_progress: 0, under_review: 0, fixed: 0, blocked: 0, deferred: 0, needs_recheck: 0 }
  by_surface: { api: { total: 0, fixed: 0 }, web: { total: 0, fixed: 0 }, tauri: { total: 0, fixed: 0 } }
  by_owner: { claude: { claimed: 0, fixed: 0 }, codex: { claimed: 0, fixed: 0 }, unassigned: 0 }
```

**Stable callsite identity (Codex's Y3 fix):**

```
sha256(surface || scanner || normalized_relative_path || symbol_fqn || ast_node_kind ||
       model_or_table || field_or_method || normalized_argument_name || statement_fingerprint)
```

Line numbers shift on edits. AST fingerprint survives non-semantic changes. On regeneration, if fingerprint matches an existing row, preserve status/owner. If fingerprint changed but path/symbol/resource match, mark `needs_recheck` (don't silently reset to pending).

**Optimistic concurrency (Codex's Y2 fix):**

`sweep:inventory:claim|resolve|block` reads file → computes SHA-256 of full YAML → acquires `flock()` → re-reads → verifies hash unchanged → validates schema → applies mutation → writes to temp → fsync → atomic rename. Concurrent mutation aborts with retry message.

CI rejects direct hand-edits: any commit touching the inventory YAML must include matching command-generated `history[]` entries; if it doesn't, CI fails.

### Section 5: Inventory generation — five scanners

**Files:**
- `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryClaimCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryResolveCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryBlockCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryStatusCommand.php`
- `apps/api/app/Application/Sweep/InventoryService.php`
- `apps/api/app/Application/Sweep/InventoryYamlSchema.json`
- `apps/web/tools/audit-tanstack-keys.mjs` (TS-AST queryKey scanner)
- `apps/web/tools/audit-pos-local-cache.mjs` (POS SQLite cache scanner targeting `apps/pos/src/`)

Five scanners, each emitting callsite rows:

1. **php_presentation_exists** — regex over Presentation tier files for bare `exists:<guarded_table>`. Fast, regex sufficient.
2. **php_ast_find** — `nikic/php-parser` AST traversal. Finds guarded `Model::find()` / `findOrFail()` without preceding tenant-scope filter. Slower but precise.
3. **ts_query_key** — TypeScript compiler API over `apps/web/src/`. Finds `useQuery`/`useMutation` calls with queryKey not including approved tenant scope.
4. **pos_sqlite_cache** — TS-AST + SQL parser over `apps/pos/src/lib/db.ts`, `apps/pos/src/lib/db/migrations.ts`, `apps/pos/src/lib/sync/syncService.ts`. Finds local cache tables missing `tenant_id`/`company_id` columns and sync handlers not validating envelope tenant identity.
5. **manual** — for things that can't be mechanically scanned (PlatformIntegration outbound, broadcast channels, scheduled jobs). Hand-curated rows added during inventory generation.

`php artisan sweep:inventory:generate` runs all five idempotently. Re-running merges new findings by `stable_key` without resetting state on already-resolved callsites.

Owner: claude. One commit (or two — schema + commands as one, scanners as another).

### Section 6: Cluster catalogue (regenerated, not hand-written)

After Section 5 runs, the inventory YAML enumerates clusters. Expected cluster set for the tactical sweep:

**API surface (16 clusters):**

| Cluster id | Owner | Blocked by | Notes |
|---|---|---|---|
| `api.treasury` | claude | — | **Reference cluster.** Hard gate. |
| `api.document` | codex | api.treasury | Document conversion, refund, PDF, FormRequests. |
| `api.inventory` | codex | api.treasury | BatchExpiry, counting, stock reservation/adjustment. |
| `api.taxation` | codex | api.treasury | Withholding certificates, FEC export inputs. |
| `api.loyalty` | codex | api.treasury | Programs, members. Some refs are tenant-only (`users`, `companies`). |
| `api.accounting` | codex | api.treasury | Ledger, partner balance, reconciliation. |
| `api.catalog` | codex | api.treasury | Modifier groups, modifiers, product catalog selectors. **Added per Codex S1.** |
| `api.contact` | codex | api.treasury | Contact-party linking. **Added per Codex S1.** |
| `api.compliance` | codex | api.treasury | Fraud alert assignment. **Added per Codex S1.** |
| `api.pricing` | codex | api.treasury | Margin checks, pricing rules. **Added per Codex S1.** |
| `api.service` | codex | api.treasury | Service catalog CRUD. **Added per Codex S1.** |
| `api.cart` | codex | api.treasury | Cart-to-document conversion. **Added per Codex S1.** |
| `api.workshop` | codex | api.treasury | Work orders, document generation. **Added per Codex S1.** |
| `api.identity-company` | codex | api.treasury | User, Company, Membership lookups outside super-admin paths. |
| `api.platform-integration` | codex | api.treasury | `PlatformHttpClient` outbound: verify tenant identity in payloads, API key isn't shared tenant credential. **Added per Codex O1.** |
| `api.webhooks-incoming` | codex | api.treasury | Stripe webhook + enrichment webhooks: tenant binding via signature/tracking id. **Added per Codex O1.** |
| `api.broadcast-channels` | codex | api.treasury | `routes/channels.php` channel authorization (`canAccessCompanyChannel`). **Added per Codex O1.** |
| `api.scheduled-jobs` | codex | api.treasury | `DailyExpiryCheck` and other globals; explicit per-tenant iteration. **Added per Codex O1.** |
| `api.super-admin-context` | claude | api.treasury | Apply `#[CrossTenantRoute]` attribute to existing super-admin routes; verify `EnsureSuperAdmin` middleware coverage. |
| `api.module-gating` | claude | api.super-admin-context | **Narrowed** to one real bug: `ProgressionService` ignores validated `CompanyContext`, trusts raw `X-Company-Id` header. (`updateExtras` is super-admin-only and not exploitable; cache key already correctly tenant-scoped per Codex S4.) |

**API-POS surface (BLOCKED on POS orchestrator branch):**

| Cluster id | Owner | Blocked by | Notes |
|---|---|---|---|
| `api.pos-stabilization` | (POS orchestrator) | POS orchestrator branch merge | ReceiptSyncService, ReceiptCreationService, OrderManagementService, ReceiptFinalizationService, VoucherLookupService, VoucherRedemptionService voucher lookup. Not parallel — done inside the POS orchestrator branch or after it merges, per `2026-04-30-pos-consolidation-checkpoint.md`. |

**Web surface (4 clusters):**

| Cluster id | Owner | Blocked by | Notes |
|---|---|---|---|
| `web.tanstack-keys` | codex | api.* (all API clusters fixed) | queryKey audit; cache invalidation on company switch; `queryClient.clear()` on logout/impersonation. |
| `web.form-selectors` | codex | web.tanstack-keys | Selector/dropdown components fetch tenant-scoped lists. |
| `web.stores-localstorage` | codex | web.tanstack-keys | Zustand stores + localStorage clear on tenant switch; cross-vertical localhost conflict. |
| `web.super-admin-frontend` | claude | api.super-admin-context | UI affordance for super-admin mode; queryKey namespace. |

**Tauri surface (BLOCKED on POS orchestrator branch):**

| Cluster id | Owner | Blocked by | Notes |
|---|---|---|---|
| `tauri.sqlite-cache` | (POS orchestrator) | POS orchestrator branch merge | `apps/pos/src/lib/db.ts` (per-company DB file), migrations missing tenant/company columns on `products`/`payment_methods`/`offline_receipts` (per Codex O1). Logout doesn't delete SQLite files. |
| `tauri.sync-envelope` | (POS orchestrator) | POS orchestrator branch merge | Sync envelope schema enforcement, tenant tagging, offline activation tenant resolution. |

### Section 7: Treasury reference cluster — Claude

**HARD GATE:** No worker may claim non-Treasury API clusters until:
1. `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` exists.
2. Verdict line 1 is `APPROVE` or `APPROVE-WITH-MINOR-EDITS-APPLIED`.
3. YAML cluster status `api.treasury` is `fixed`.

The gate is enforced by `sweep:inventory:claim`, which checks Treasury status before allowing a non-Treasury claim and refuses with a clear error otherwise.

Workflow:
1. Claude claims `api.treasury`.
2. Reads every callsite in the cluster.
3. Writes the cluster regression test FIRST (TDD): `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php`.
4. Verifies RED.
5. Lands fixes per callsite.
6. Verifies GREEN.
7. PHPStan + Pint clean. Commits. Resolves callsites via `sweep:inventory:resolve`.
8. Hands to Codex for **adversarial cluster review** (different agent from the fixer).
9. Review verdict written to `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md`.

Owner: claude. ~12-15 callsites expected, one commit per logical sub-group.

### Section 8: Codex-driven API clusters (parallel after Treasury gate clears)

Once Treasury is gated through, Codex claims clusters one at a time and follows the Treasury template:

```
1. claim cluster
2. read every callsite
3. write regression test FIRST (TDD)
4. verify RED
5. land fixes per callsite
6. verify GREEN (cluster suite + broader module suite)
7. PHPStan + Pint clean
8. commit; resolve callsites
9. hand to Claude for adversarial cluster review (cross-agent review)
10. review verdict written to docs/superpowers/reviews/2026-05-02-<cluster>-cluster-claude-review.md
```

Cross-agent review is non-negotiable. Codex doesn't review its own cluster fixes.

Codex sweeps in this order (parallelizable after Treasury clears):
- api.document, api.inventory, api.taxation, api.loyalty, api.accounting (dependent on api.treasury only)
- api.catalog, api.contact, api.compliance, api.pricing, api.service, api.cart, api.workshop, api.identity-company (added per Codex S1)
- api.platform-integration, api.webhooks-incoming, api.broadcast-channels, api.scheduled-jobs (the four newly-added clusters per Codex O1)

### Section 9: Super-admin context — Claude (api.super-admin-context cluster)

**Files:**
- Create: `apps/api/app/Shared/Architecture/CrossTenantRoute.php` (PHP attribute)
- Create: `apps/api/app/Http/Middleware/CrossTenantContext.php`
- Modify: `bootstrap/app.php` (alias `cross_tenant`)
- Modify: super-admin controllers (apply `#[CrossTenantRoute]`)
- Modify: `tests/Architecture/TenantScopedExistsRulesTest.php` + `TenantScopedFindCallsTest.php` (route-attribute-aware skip logic)

Super-admin route group at `routes/api.php:53-116` already uses `EnsureSuperAdmin` middleware. The work is:
1. Annotate every super-admin controller method with `#[CrossTenantRoute(reason: "...")]`.
2. Update architecture tests to read controller method attributes via reflection (build route map from Laravel routes; require `cross_tenant` middleware OR `#[CrossTenantRoute]` for skip).
3. Regression test: a tenant user hitting a super-admin route is rejected before the cross-tenant guards run.

Owner: claude. One commit.

### Section 10: Module-gating sweep — Claude (api.module-gating cluster, narrowed)

Per Codex S4: `updateExtras` is NOT exploitable (super-admin only, EnsureSuperAdmin rejects tenant users), and the `CompanyConfigService` cache key IS correctly tenant-scoped (`tenant_config:{$tenant->id}`). The real bug is `ProgressionService` ignoring validated `CompanyContext`:

```
ModuleReadinessController::activate (and CompanyProgressionController, RecommendationController)
  reads X-Company-Id header directly → passes raw header to GrowthAdvisorHttpClient
  GrowthAdvisorHttpClient constructs URL from raw header
```

Even though `CompanyContextMiddleware` validates `X-Company-Id` against user company access, the service ignores the validated context. A future middleware-ordering change could reopen the gap. Fix:
1. Inject `CompanyContext` into `ModuleReadinessController` (and the two siblings).
2. Use `requireCompanyId()` instead of raw header.
3. Verify Growth Advisor response company id matches if the response contains one.

Add regression tests:
- A regular tenant user cannot call `updateExtras` (403). Already true; pin it.
- ProgressionService::activateModule includes the validated company id, not raw header.
- CompanyConfigService cache keys include tenant_id (positive control).

Owner: claude. One commit.

### Section 11: Web TanStack Query keys — Codex (web.tanstack-keys cluster)

**Files:**
- Modify: every `apps/web/src/**/*.ts(x)` file containing `useQuery({` or `useMutation({` whose queryKey doesn't include approved scope.
- Create: `apps/web/src/lib/tenantScopedKey.ts` (helper: `tenantScopedKey(['payment-methods'])` → `['payment-methods', companyId]`).
- Create: `apps/web/tools/audit-tanstack-keys.mjs` (CI gate, TS-AST scanner).
- Modify: `apps/web/package.json` add `"test:arch": "node tools/audit-tanstack-keys.mjs"`.
- Modify: logout flow to call `queryClient.clear()`. Audit super-admin impersonation in/out.
- Test: `apps/web/src/__tests__/tenantSwitchCacheInvalidation.test.tsx` (Vitest).

Approved key factories per Codex T2:
- `tenantScopedKey([...])` (the new helper)
- `currentCompanyId` literal in queryKey
- `companyStore.currentCompanyId`
- `tenantId`
- super-admin namespace prefix `'admin'` / `'super-admin'`

Owner: codex. One commit per ~10 callsites cluster (groups of features).

### Section 12: Web form selectors + stores + localStorage — Codex (web.form-selectors + web.stores-localstorage)

Selectors that fetch lists for foreign-key dropdowns must be tenant-scoped (the queryKey audit covers this; verify per file). Zustand stores' `persist` middleware must clear tenant-scoped data on switch. localStorage must namespace by tenant id and clear on logout.

Cross-vertical localhost conflict (per project memory: IziPOS and Otospex share localhost) compounds under multi-tenant. Document the resolution as part of this cluster.

Owner: codex. Per-feature-cluster commits.

### Section 13: Super-admin frontend — Claude (web.super-admin-frontend)

**Files:**
- Modify: `apps/web/src/features/admin/pages/*` to add a persistent "super-admin mode" UI affordance.
- Modify: super-admin queryKeys to use `'super-admin'` namespace prefix.
- Audit: super-admin pages call only super-admin-only API routes (which use `#[CrossTenantRoute]`).

Owner: claude. One commit.

### Section 14: POS-cluster work BLOCKED on POS orchestrator branch

Per Codex S2 + the consolidation checkpoint: POS-specific tenant-isolation work is NOT done in parallel. Workflow:

1. The tenant-isolation sweep marks `api.pos-stabilization`, `tauri.sqlite-cache`, `tauri.sync-envelope` as `blocked` with reason "POS orchestrator branch ownership".
2. The POS orchestrator does the tenant-isolation work inside their branch (using the Treasury cluster as the established pattern).
3. Either the POS orchestrator branch merges first and the tenant-isolation sweep then resolves these clusters from the merged code, OR the POS orchestrator hands explicit ownership to the sweep with a documented agreement.

This explicitly respects `2026-04-30-pos-consolidation-checkpoint.md`'s "no parallel POS sessions" rule.

### Section 15: Tactical-phase final verification + PR

- [ ] **Step 15.1: Strip `@group sweep-progress` markers** once all non-POS clusters are clean. POS clusters stay in `sweep-progress` until the POS orchestrator finishes.
- [ ] **Step 15.2: Full preflight** across API + web + Tauri.
- [ ] **Step 15.3: Inventory status final report.** `php artisan sweep:inventory:status` shows zero pending, only POS deferred with documented reason.
- [ ] **Step 15.4: PR `fix/tenant-isolation` → `dev`.** Body lists every cluster commit, every cross-agent review verdict, the inventory's final state, and the deferred POS items.
- [ ] **Step 15.5: Hand to user for `dev → main` promotion.**

---

## Strategic phase — DB hardening, NF525, e-invoicing, ERP migration (Sections 16-21)

Owners: human-driven decisions, Claude/Codex execute. Tracked in `docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml` (Codex-authored, 14 items, 6 workstreams). Keep that YAML as-is — it's the right format for this layer.

Sequenced AFTER tactical phase lands.

### Section 16: DB-001 — Database isolation evidence baseline (P0, blocks_certification: true)

Implement the audit command `php artisan security:tenant-isolation:db-audit`. Emits JSON/YAML inventory of: schemas, RLS flags, policies, tenant/company columns, fiscal triggers, FK scope gaps. Output committed to `docs/superpowers/audits/`.

Owner: claude. ~3-5 days.

### Section 17: DB-010 + DB-011 — Composite tenant/company FK guardrails on POS fiscal tables (P0, blocks_certification: true)

Migration to add composite FKs:
- `pos_receipt_payments(receipt_id, tenant_id, company_id) → pos_receipts(id, tenant_id, company_id)`.
- `pos_receipts(terminal_id, tenant_id, company_id) → pos_terminals(id, tenant_id, company_id)`.
- Same for partner/contact/cashier references.
- Backfill `tenant_id`/`company_id` on child rows that only have parent refs today.

Database-level rejection of cross-tenant FK insertion. Belt-and-suspenders to the application-layer scoping.

Owner: codex. ~5-7 days, including data migration testing.

### Section 18: DB-020 — RLS pilot on POS fiscal tables (P1, blocks_certification: false)

PILOT, not full rollout. POS fiscal tables only:
- Define `app.tenant_id`, `app.company_id`, `app.super_admin` session variables.
- Add middleware that sets local transaction settings on every request after auth/company resolution.
- RLS policies on `pos_receipts`, `pos_receipt_payments`, `pos_z_reports`, `pos_grandtotal_events`, `vouchers`, `voucher_ledger` with `USING` and `WITH CHECK`.
- Console/audit escape hatch for explicit super-admin context.
- Tests: direct SQL insert/select/update fails without correct session variables.

This is defense-in-depth, NOT a substitute for explicit Eloquent scoping. The tactical sweep + composite FKs are the load-bearing layers; RLS catches what slips through.

Owner: codex. ~5-7 days.

### Section 19: POS-001 + POS-010 + POS-011 — NF525 certification evidence pack (P0, blocks_certification: true)

Build `php artisan pos:export-nf525-evidence-pack`:
- Schema evidence: migrations, trigger definitions, RLS policies, FK inventory.
- Functional evidence: receipt-chain verification, Z-report verification, grand-total continuity, void/refund compensating receipt flow, reprint logs, training-mode exclusion.
- Fixture evidence: v2/v3 golden hash fixture checks, frontend/backend parity, fixture integrity hashes.
- Operational evidence: backup/restore, export, archive retention, device onboarding, FDE verification, incident response.
- Change-control evidence: CI gates that prevent bare exists / unscoped find / query-key leaks (the architecture tests built in Section 3).

Pin fixture parity in CI (Fixture-01 / Fixture-08 hashes unchanged unless deliberate fiscal schema bump).

Schedule certifier/tax-counsel review of the evidence pack structure before building too much bespoke tooling.

Owner: human + claude/codex. ~2-3 weeks. Hard deadline: before 2026-08-31 if pursuing self-cert, before 2026-09-01 if going accredited (need certifier engagement lead time).

### Section 20: EINV-001 — French e-invoicing track (P1, blocks_certification: false but blocks French B2B revenue)

Separate from NF525 POS:
- Validate `FacturXService` Basic WL XML against EN16931.
- PDF/A-3 embedding validation with external validator.
- PDP/PF integration (submission, status updates, inbound reception, e-reporting).
- Invoice lifecycle states + immutable exchange logs.
- Company legal identifier normalization (SIRET/VAT validation).

Hard deadline: before 2026-09-01 for French B2B customers needing to receive e-invoices.

Owner: codex + human (PDP partner selection is a business decision). ~3-4 weeks.

### Section 21: ERP-001 — DB-per-tenant migration decision + execution (P1, blocks_certification: false)

Strategic decision documented + migration executed. Per `docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md`: 4-6 weeks focused work for the migration itself.

Sequence:
1. Decide DB-per-tenant vs schema-per-tenant vs hybrid (research already done).
2. Split migrations into `database/migrations/` (central) and `database/migrations/tenant/` (per-tenant).
3. Wire `PostgreSQLDatabaseManager` in `tenancy.php`.
4. Update test suite to use Stancl's `TenancyTestKit`.
5. Operational runbook: PgBouncer per-tenant pooling, per-tenant backup, monitoring per tenant, cross-tenant queries via `Tenant::find($id)->run(fn () => ...)`.
6. Cut over before signing the first French/regulated B2B customer.

Architecture tests parameterize on `TENANCY_MODE`:
- `TENANCY_MODE=row_level` (current): enforces tenant/company predicates per Section 3.
- `TENANCY_MODE=multi_db` (post-migration): enforces no tenant-table queries on the central connection. The tactical sweep's helper becomes legacy; explicit `where('tenant_id', ...)` becomes redundant under multi-DB but doesn't break.

Owner: claude + codex + human. ~6-8 weeks including soak.

---

## Out of scope (explicitly rejected with reasons + revisit dates)

| Item | Reason | Revisit |
|---|---|---|
| Frontend feature changes beyond tenant-isolation | Sweep is security/correctness, not UX. | Separate work |
| SKU packaging refinement (verticals.php for "POS-only" SKU) | Module gating system already supports it; refinement is a product decision. | After Tunisian launch |
| Cross-tenant ML/data platform redesign | Pooled row-level is operationally simpler for analytics; DB-per-tenant needs event push or per-tenant CDC. Synerivia integration is push-based today and works for both. | Post-Section 21 |
| Full Stancl multi-DB migration during tactical sweep | Tactical sweep is launch-blocking for Tunisia; migration is 4-6 additional weeks. Sequenced as Section 21. | Section 21 |
| RLS on every ERP table | Defense-in-depth, not required to close current app-layer bugs. POS fiscal pilot in Section 18 is sufficient first step. | Post-Section 18 |

---

## Adversarial-review checkpoint

Before any execution starts:

- [ ] Hand THIS unified plan to Codex for one final adversarial review.
- [ ] Save verdict to `docs/superpowers/reviews/2026-05-02-tenant-isolation-master-plan-codex-review.md`.
- [ ] Required verdict: `APPROVE-AS-PROPOSED` or `APPROVE-WITH-MINOR-EDITS-APPLIED`.
- [ ] If `REQUIRES-DIFFERENT-APPROACH` again, revise this document. Do not start execution on a flawed foundation.

The previous review's findings are all addressed in this version (see the "What changed" table above). The next review should focus on:
1. Whether the tactical/strategic split is clean enough.
2. Whether the YAML schema corrections are sufficient.
3. Whether the POS orchestrator boundary is correctly modeled.
4. Whether the strategic phase deadlines (NF525, e-invoicing) are realistic.
5. Whether anything else is still missing.

---

## Self-review checklist (run before handing to Codex)

- [ ] All API clusters from Codex S1's grep enumerated (treasury, document, inventory, taxation, loyalty, accounting, catalog, contact, compliance, pricing, service, cart, workshop, identity-company, platform-integration, webhooks-incoming, broadcast-channels, scheduled-jobs).
- [ ] POS clusters explicitly blocked on POS orchestrator branch.
- [ ] YAML schema uses canonical cluster ids (`api.treasury` everywhere).
- [ ] Status enum unified (8 states including `under_review` and `needs_recheck`).
- [ ] History entry schema documented inline.
- [ ] Stable callsite identity is AST fingerprint, not file+line.
- [ ] Optimistic concurrency model documented.
- [ ] CI gates: code is source of truth, YAML is metadata.
- [ ] Tauri paths corrected to `apps/pos/src/lib/`.
- [ ] `#[CrossTenantRoute]` attribute replaces `CrossTenantExists` helper.
- [ ] Module-gating cluster narrowed to the actual `ProgressionService` header-trust bug.
- [ ] Cross-agent review enforced (Claude reviews Codex's clusters; Codex reviews Claude's).
- [ ] Treasury hard gate enforced via `sweep:inventory:claim` itself.
- [ ] Cluster ownership encoded in `agents.can_claim`.
- [ ] Mode-switch infrastructure for future multi-DB documented.
- [ ] Strategic phase sequenced after tactical (Sections 16-21).
- [ ] NF525 + e-invoicing deadlines surfaced as hard constraints.
- [ ] Both YAMLs (callsite inventory + certification SOT) integrated as complementary sources.
- [ ] All Codex review findings (S1-S4, Y1-Y4, T1-T3, M1, C1-C3, O1) have an applied fix in the "What changed" table.
- [ ] Out-of-scope items have reasons + revisit dates.
