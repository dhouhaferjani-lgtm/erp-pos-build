# Tenant-Isolation + NF525 Foundation — Unified Master Plan

> **Status:** APPROVED FOR EXECUTION — Codex final adversarial review verdict APPROVE-WITH-MINOR-EDITS-APPLIED (`docs/superpowers/reviews/2026-05-02-tenant-isolation-master-plan-codex-review.md`); all 13 listed edits + 7 schema additions are now in this document. Tactical phase begins with Section 1.
>
> **Supersedes:**
> - `docs/superpowers/plans/2026-05-01-tenant-isolation-sweep-phase-b.md` (API-only original Phase B)
> - `docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md` (first attempt at master spec — flawed per Codex review round 1)
>
> **Integrates:**
> - `docs/superpowers/plans/2026-05-02-tenant-isolation-nf525-database-foundation-plan.md` (Codex's strategic foundation plan)
> - `docs/superpowers/reviews/2026-05-02-tenant-isolation-sweep-spec-codex-review.md` (Codex round-1 review — REQUIRES-DIFFERENT-APPROACH)
> - `docs/superpowers/reviews/2026-05-02-tenant-isolation-master-plan-codex-review.md` (Codex round-2 review — APPROVE-WITH-MINOR-EDITS-APPLIED, edits applied)
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

## External constraints (legal + commercial)

**Legal basis updated 2026-05-02 per Codex final review:** the 2026 finance law restored editor self-attestation for NF525 and cancelled the accredited-certificate-only switch. The 2026-08-31 / 2026-09-01 NF525 dates are NO LONGER hard statutory deadlines. Source: economie.gouv.fr NF525 page (revised 2026-02-24). Accredited certification remains a commercial-assurance differentiator, not a legal requirement.

**E-invoicing remains a hard deadline.** Source: impots.gouv.fr e-invoicing pages.

| Constraint | Date | Type | Impact |
|---|---|---|---|
| France e-invoicing — RECEIVE | **2026-09-01** | Legal (hard) | All companies must be able to receive e-invoices. AutoERP must support inbound from a chosen PDP. |
| France e-invoicing — ISSUE (large/ETI) | **2026-09-01** | Legal (hard) | Large/ETI companies must issue + e-report. PDP integration required. |
| France e-invoicing — ISSUE (SME/micro) | **2027** | Legal (hard) | SMEs and micros must issue + e-report from 2027. Lead time matters; PDP partner selection cannot wait. |
| France NF525 self-attestation (editor) | restored | Legal (option) | Editor self-attests inalterability/securisation/conservation/archiving. Evidence pack still required for the attestation, but no third-party certifier mandate. |
| France NF525 accredited certification | optional | Commercial | Faster trust signal for French B2B prospects. Defer until commercial demand justifies the certifier engagement cost. |
| Tunisian POS launch (current target) | TBD | Operational | No NF525 requirement, no PDP requirement. Tactical sweep is sufficient. |

**Implication:** The tactical sweep is launch-blocking for Tunisia. E-invoicing receive (2026-09-01) is the only legally-hard external date and requires PDP partner selection NOW (lead time for onboarding/integration testing). NF525 evidence pack remains valuable but as a self-attestation foundation + commercial assurance, not a regulatory deadline.

---

## What changed since the previous master spec

This plan applies every required-different-approach edit from Codex's review:

| Codex review finding | Applied fix |
|---|---|
| 117 bare `exists:` matches across 12 modules; spec only listed 7 clusters | Cluster list is now generated from inventory, not hand-written. Sections 6 + 7 enumerate all 13 known API clusters: treasury, document, inventory, taxation, loyalty, accounting, catalog, contact, compliance, pricing, service, cart, workshop, identity-company. POS-specific cluster blocked on POS orchestrator branch. |
| YAML cluster names mixed (`treasury` vs `api.treasury`) | Canonical id `cluster_id: api.treasury` everywhere. `display_name` is presentational only. |
| Status values disagreed (cluster=`done`, callsite=`fixed`) | Single status enum: `pending → claimed → in_progress → under_review → fixed → blocked → deferred → needs_recheck`. |
| `history[]` schema undefined | Documented inline (Section 4). |
| Tauri paths wrong (prior desktop-paths corrected) | Sections 13-14 use `apps/pos/src/lib/db.ts`, `apps/pos/src/lib/db/migrations.ts`, `apps/pos/src/lib/sync/syncService.ts`. Rust scanner removed. |
| POS re-fold conflicts with POS orchestrator branch (`2026-04-30-pos-consolidation-checkpoint.md`) | POS-specific tenant-isolation work is BLOCKED on the POS orchestrator branch's merge. Tracked but not parallel. |
| CI gates ambiguous on source of truth (file+line identity, YAML-derived pass/fail) | Code is source of truth. Tests parse code and fail on unsafe patterns. YAML provides ownership/status metadata for failure messages, never derives pass/fail. Stable callsite identity = AST fingerprint, not file+line. |
| `CrossTenantExists::cross()` helper proposed for super-admin context | Replaced with `#[CrossTenantRoute(reason: "...")]` PHP attribute on controller methods + `cross_tenant` middleware alias. Tests inspect route definitions, not paths. |
| Module-gating exploit class overbroad (`updateExtras` is super-admin-only and not exploitable; cache key already correctly tenant-scoped) | Module-gating cluster narrowed to ONE real bug: `ProgressionService` ignores validated `CompanyContext` and trusts raw `X-Company-Id` header. |
| Self-review by same agent isn't adversarial | Different reviewer per cluster: Claude fixes Treasury → Codex reviews; Codex fixes a cluster → Claude reviews. |
| Treasury checkpoint not a hard gate | Hard gate: no worker may claim non-Treasury API clusters until `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` verdict is `APPROVE` and YAML cluster status is `fixed`. |
| Cluster ownership not enforced in `can_claim` | Explicit `can_claim` lists per agent, override requires `--force --reason --approved-by`. |
| PlatformIntegration outbound, Stripe webhook, broadcast channels, scheduled jobs (DailyExpiryCheck crosses tenants) missing | All added as new clusters in Section 6. |
| Stancl multi-DB compatibility claim overstated | Mode-switch infrastructure: `TENANCY_MODE=row_level` (current) vs `TENANCY_MODE=multi_db` (future). Architecture tests parameterize on it. |
| Strategic context (NF525 deadlines, DB hardening, e-invoicing) missing | Sections 18-23 cover the strategic phases, sequenced after tactical. |
| **(Final review R1)** Two more API clusters needed beyond initial 17 | Added `api.console-commands` (Section 14) and `api.auth-permissions` (Section 15). API surface is now 22 clusters. |
| **(Final review R2/R8/N7)** YAML schema's resolve-before-review state ambiguity, hand-edit not implementable, rename-orphan undefined | Section 4 schema v2 splits resolve into `submit` (in_progress → under_review) + `review` (under_review → fixed); only `review` can set fixed; `reviewer_must_differ_from_owner` enforced. CI hand-edit detector via command-event hash chain. Stale-orphan / superseded states added. |
| **(Final review R3)** Residual `apps/desktop/...` reference | Removed; all paths use `apps/pos/...`. |
| **(Final review R4)** `tauri.*` still in Codex's `can_claim` | Removed from `can_claim`; POS orchestrator branch is the only blocker that lifts it. |
| **(Final review R5)** Drift surfacing missing | `sweep:inventory:status --drift` flag; `progress.drift` aggregated counts; final verification fails on `yaml_says_fixed_code_unsafe > 0`. |
| **(Final review R6)** Non-route `@cross-tenant-by-design` lacked grammar | Strict 4-field grammar with mandatory `Reason:` / `Audit-id:` / `Approved-by:` / `Expires:`; expired annotations auto-fail the build. |
| **(Final review R9)** Treasury hard gate didn't verify commit linkage | Cluster's `review_gate.verify_review_commit_linkage: true` flag; `sweep:inventory:claim` parses the review file's commit reference and verifies it matches the cluster's `fix_commit`. |
| **(Final review N1, N2, N3)** Strategic phase ownership vague; sales gate missing | Strategic decision table with 4 DRI-named decisions (NF525 path, PDP partner, tenancy topology, sales gate) + due dates + blocking consequences. Sales gate enforced via `php artisan sweep:strategic:status` flag. |
| **(Final review N4)** Two-YAMLs sync risk | New `linked_cluster` / `blocked_by_cluster` fields on certification SOT items; consistency check command. POS-001 marked `blocked` with proper linkage. |
| **(Final review N5)** Scanner test fixtures missing | Each scanner gets a fixtures directory with positive + negative + edge cases; default-deny for unknown TS queryKey factories. |
| **(Final review P1)** NF525 evidence pack underscoped | Section 21 expanded to ISCA-explicit (inalterability/securisation/conservation/archiving) + date-certainty + customer-facing attestation + version/change-control dossier. |
| **(Final review P2)** E-invoicing single deadline misleading | Section 22 split into 4 gates: RECEIVE (2026-09-01), ISSUE-large/ETI (2026-09-01), ISSUE-SME/micro (2027), PDP integration testing pre-condition. |
| **(Final review P3)** DB-per-tenant cutover strategy missing | Section 23 expanded with rolling per-tenant cutover, rehearsal, 7-day rollback window, dual-write prohibition, ≤30-min maintenance window per tenant, Tunisia-first sequencing. |
| **(Final review legal basis)** NF525 dates were treated as hard statutory deadlines | Updated: 2026 finance law restored editor self-attestation per economie.gouv 2026-02-24. NF525 work is now self-attestation foundation + commercial assurance, not regulatory. E-invoicing remains hard-deadline. |

---

## Pre-flight gate — confirm starting state

Before any execution:

- [ ] **Step 0.1: Branch state.** On `fix/tenant-isolation`, tip equals the commit named in the master-plan adversarial review (`docs/superpowers/reviews/2026-05-02-tenant-isolation-master-plan-codex-review.md`), no uncommitted changes.
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

### Section 4: YAML inventory schema v2 (canonical IDs, review-before-fixed enforced)

**File:** `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` (live, generated and mutated by artisan commands).

> **Note:** the example file at `docs/superpowers/plans/tenant-isolation-sweep-inventory.example.yml` is currently on schema v1 (the prior round's schema). The inline schema in THIS section is authoritative. The example file gets regenerated as the first task of Section 5 (`SweepInventoryGenerateCommand` produces a v2-shaped output).

**Schema v2 (incompatible bump from v1) — corrections per Codex master-plan review (R2, R8, R9, N6, N7):**

```yaml
metadata:
  schema_version: "2"
  spec_version: "2026-05-02-master"
  spec_path: "docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md"
  branch: "fix/tenant-isolation"
  generated_at: "2026-05-02T..."
  generated_by: "claude" | "codex"
  schema_sha256: "..."  # SHA-256 of the schema definition itself (bumps when schema changes)
  yaml_sha256: "..."    # SHA-256 of the full YAML file at write time (used for optimistic concurrency)

agents:
  claude:
    role: "lead, plan, review, complex refactors, reference cluster, security-architecture clusters"
    can_claim:
      - "api.treasury"
      - "api.super-admin-context"
      - "api.module-gating"
      - "api.auth-permissions"           # added per Codex final O2
      - "web.super-admin-frontend"
      - "tactical.foundation.*"          # Sections 1-3 (helper, attribute, CI gates)
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
      - "api.console-commands"           # added per Codex final R1 / O1
      - "web.tanstack-keys"
      - "web.form-selectors"
      - "web.stores-localstorage"
      # NOTE: tauri.* explicitly NOT in Codex's can_claim — POS orchestrator owns
      # those clusters per 2026-04-30-pos-consolidation-checkpoint.md until the
      # orchestrator branch merges or explicitly hands ownership.
    can_review: ["*"]

statuses_enum:
  - "pending"        # detected, not yet claimed
  - "claimed"        # owner set, work not started
  - "in_progress"    # work started
  - "under_review"   # fix landed, waiting for adversarial review by another agent
  - "fixed"          # review approved (DIFFERENT agent than owner) AND code gate passes
  - "blocked"        # cannot proceed; reason + blocked_by/blocked_by_external required
  - "deferred"       # acknowledged but explicitly out of scope; reason required
  - "needs_recheck"  # code AST fingerprint changed at this callsite; manual revisit
  - "stale_orphan"   # symbol moved/renamed; old row preserved for audit, new pending row created

# Status transition rule (enforced by command-event hash schema below):
#   pending → claimed → in_progress → under_review → fixed
#   blocked, deferred, needs_recheck, stale_orphan can branch from any pre-fixed state with a reason.

clusters:
  - id: "api.treasury"               # canonical id; all references use this exact form
    display_name: "Treasury"
    surface: "api"
    owner: null
    required_owner: "claude"          # only this agent can claim, unless --force --approved-by
    status: "pending"
    blocked_by: []                    # other cluster ids (canonical form), all-or-nothing
    blocked_by_external: null         # e.g., "pos_orchestrator_branch" — non-YAML blocker (Codex final review)
    blocks: ["api.document", "api.inventory", "api.taxation", "api.loyalty", "api.accounting", "api.catalog", "api.contact", "api.compliance", "api.pricing", "api.service", "api.cart", "api.workshop", "api.identity-company", "api.platform-integration", "api.webhooks-incoming", "api.broadcast-channels", "api.scheduled-jobs", "api.console-commands", "api.auth-permissions", "web.*", "tauri.*"]
    is_reference: true
    expected_callsite_count: null     # filled by inventory generation
    test_file: "apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php"
    review_gate:                      # added per Codex final review
      required: true
      reviewer_must_differ_from_owner: true
      review_file: "docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md"
      accepted_verdicts: ["APPROVE", "APPROVE-WITH-MINOR-EDITS-APPLIED"]
      verify_review_commit_linkage: true   # review file must reference the fix_commit SHA
    notes: "Reference cluster. HARD GATE: no other API cluster may begin until this review verdict is in accepted_verdicts AND the review_commit_linkage is verified AND YAML cluster status is fixed."

  # Example cluster blocked on POS orchestrator branch:
  - id: "api.pos-stabilization"
    display_name: "POS stabilization"
    surface: "api"
    owner: null
    required_owner: null              # POS orchestrator decides
    status: "blocked"
    blocked_by: ["api.treasury"]
    blocked_by_external: "pos_orchestrator_branch"
    blocked_reason: "POS orchestrator owns all apps/pos and apps/api/app/Modules/POS work per 2026-04-30-pos-consolidation-checkpoint.md. Unblock when POS branch merges OR orchestrator explicitly transfers ownership."
    blocks: []
    is_reference: false
    test_file: "apps/api/tests/Feature/POS/PosStabilizationTenantIsolationTest.php"
    review_gate:
      required: true
      reviewer_must_differ_from_owner: true
      review_file: "docs/superpowers/reviews/2026-05-02-pos-stabilization-cluster-claude-review.md"
      accepted_verdicts: ["APPROVE", "APPROVE-WITH-MINOR-EDITS-APPLIED"]
      verify_review_commit_linkage: true

callsites:
  - id: "api.treasury.001"
    stable_key: "sha256:<ast-fingerprint>"   # see "Stable identity" below; manual rows use "manual:<cluster>:<slug>"
    surface: "api"
    cluster_id: "api.treasury"
    scanner: "php_presentation_exists"        # which scanner detected it (or "manual")
    file: "apps/api/app/Modules/Treasury/Presentation/Requests/RefundPrepaymentRequest.php"
    line: 23                                   # display metadata only — never used as identity
    symbol: "App\\Modules\\Treasury\\Presentation\\Requests\\RefundPrepaymentRequest::rules"
    pattern_type: "bare_exists_validator"
    resource: "payment_methods"
    expected_scope: "tenant_and_company"      # tenant_and_company | tenant_only | company_only | parent_context | cross_tenant
    expected_fix: "Replace 'exists:payment_methods,id' with ScopedExists::tenantAndCompany"
    severity: "high"                           # high | medium | low
    fiscal_path: false
    cross_module: false
    stale_state: "active"                      # active | stale_orphan | superseded — added per Codex final review
    status: "pending"
    owner: null
    claimed_at: null
    review:
      reviewer: null                           # MUST differ from owner — enforced by sweep:inventory:review
      verdict: null                            # only APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCK
      reviewed_at: null
      review_file: null
      review_commit: null                      # commit SHA linked to the review file (verify_review_commit_linkage)
    fix_commit: null
    regression_test: null
    blocked_reason: null
    history:
      - at: "2026-05-02T00:00:00Z"
        actor: "generator"                     # "generator" | "claude" | "codex" | "ci" | "human"
        action: "generate"                     # "generate" | "claim" | "start" | "submit" | "review" | "resolve" | "block" | "unblock" | "regenerate" | "stale_mark"
        command: "sweep:inventory:generate"    # the artisan command that produced this event (or null for manual)
        previous_yaml_sha256: null              # the YAML hash before this mutation
        new_yaml_sha256: "..."                  # the YAML hash after this mutation
        target_ids: ["api.treasury.001"]        # callsites/clusters affected by this event
        from_status: null
        to_status: "pending"
        commit: null                            # the source-tree git commit at event time
        test: null
        review_file: null
        review_commit: null
        note: "initial detection"

progress:
  total_callsites: 0
  total_clusters: 0
  by_status: { pending: 0, claimed: 0, in_progress: 0, under_review: 0, fixed: 0, blocked: 0, deferred: 0, needs_recheck: 0, stale_orphan: 0 }
  by_surface: { api: { total: 0, fixed: 0 }, web: { total: 0, fixed: 0 }, tauri: { total: 0, fixed: 0 } }
  by_owner: { claude: { claimed: 0, fixed: 0 }, codex: { claimed: 0, fixed: 0 }, unassigned: 0 }
  drift: { yaml_says_fixed_code_unsafe: 0, code_safe_yaml_pending: 0 }   # added per Codex final review (R5 drift surfacing)
```

**Stable callsite identity (Codex Y3 + N6 fix):**

```
sha256(surface || scanner || normalized_relative_path || symbol_fqn || ast_node_kind ||
       model_or_table || field_or_method || normalized_argument_name || statement_fingerprint)
```

For manual rows (PlatformIntegration, broadcast channels, etc., where mechanical scan can't detect): `stable_key = "manual:<cluster>:<slug>"` — preserved across regenerations because the prefix isn't reproducible by any scanner.

**Rename/move semantics (Codex N6 fix):**
- AST fingerprint matches → preserve status/owner.
- Fingerprint changed but `relative_path + symbol_fqn + resource` match → mark `needs_recheck`. Human revisits before clearing.
- Symbol renamed (`relative_path` matches but `symbol_fqn` differs) → old row marked `stale_orphan`; new row created `pending`. Old row preserved for audit trail; reviewer decides if old row is `superseded` (rename was the same logical site) or kept as historical.
- Path moved (both `relative_path` and `symbol_fqn` differ) → no merge attempt. Old row stays. New row created.
- Statement fingerprint changed only (e.g., new parameter added) → `needs_recheck` with the diff captured in `history[].note`.

**Optimistic concurrency + command-event audit (Codex Y2 + N7 fix):**

`sweep:inventory:claim|start|submit|review|resolve|block|unblock` follows this sequence:
1. Read inventory file.
2. Compute `previous_yaml_sha256`.
3. Acquire `flock()` exclusive.
4. Re-read; verify hash unchanged (concurrent mutation → abort with retry message).
5. Validate schema (JSON Schema at `apps/api/app/Application/Sweep/InventoryYamlSchema.json`).
6. Apply mutation; append `history[]` entry with:
   - `command`: which artisan command
   - `actor`: who ran it
   - `previous_yaml_sha256` / `new_yaml_sha256`
   - `target_ids`: callsites/clusters affected
   - `commit`: git HEAD at time of event
   - `review_file` / `review_commit` (when applicable)
7. Write to temp file → fsync → atomic rename.
8. Release lock.

**CI hand-edit rejection (Codex N7 fix):** the CI workflow runs `sweep:inventory:verify-history` on every PR touching the YAML. The check:
1. For each `history[]` event added since the PR base: verify `previous_yaml_sha256` chain is unbroken (each event's `new_yaml_sha256` matches the next event's `previous_yaml_sha256`).
2. Verify `command` field is non-null (rejects hand-edits that didn't go through artisan CLI).
3. Verify `target_ids` references exist in the post-mutation YAML.
4. If any check fails, CI fails the PR with a clear message about which event is missing/malformed.

**Workflow state machine (Codex R8 fix — review-before-fixed enforced):**

```
sweep:inventory:claim     pending → claimed
sweep:inventory:start     claimed → in_progress
sweep:inventory:submit    in_progress → under_review (must include fix_commit + regression_test)
sweep:inventory:review    under_review → fixed (requires review_file with APPROVE verdict + reviewer != owner + verify_review_commit_linkage if cluster has it)
                          under_review → in_progress (verdict REQUEST-CHANGES, owner re-works)
                          under_review → blocked (verdict BLOCK)
sweep:inventory:block     any pre-fixed → blocked (with reason)
sweep:inventory:unblock   blocked → previous state (audited)
sweep:inventory:defer     pending|claimed → deferred (with reason + revisit date)
```

The previous workflow's `sweep:inventory:resolve` is REMOVED — replaced by `submit` (owner submits work for review) + `review` (reviewer transitions to fixed). This closes the loop where YAML could mark a callsite `fixed` without an actual cross-agent review.

### Section 5: Inventory generation — five scanners

**Files:**

Commands (one per artisan verb in the workflow state machine from Section 4):
- `apps/api/app/Console/Commands/SweepInventoryGenerateCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryClaimCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryStartCommand.php`         # claimed → in_progress
- `apps/api/app/Console/Commands/SweepInventorySubmitCommand.php`        # in_progress → under_review
- `apps/api/app/Console/Commands/SweepInventoryReviewCommand.php`        # under_review → fixed (or back to in_progress)
- `apps/api/app/Console/Commands/SweepInventoryBlockCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryUnblockCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryDeferCommand.php`
- `apps/api/app/Console/Commands/SweepInventoryStatusCommand.php`        # supports `--drift` flag (Codex R5)
- `apps/api/app/Console/Commands/SweepInventoryVerifyHistoryCommand.php` # CI hand-edit detector (Codex N7)

Service + schema:
- `apps/api/app/Application/Sweep/InventoryService.php` (atomic mutate, optimistic concurrency)
- `apps/api/app/Application/Sweep/InventoryYamlSchema.json` (JSON Schema v2)

Scanners:
- `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php`
- `apps/api/app/Application/Sweep/Scanners/PhpAstFindScanner.php`        # uses nikic/php-parser dev dep
- `apps/api/app/Application/Sweep/Scanners/ManualScanner.php`            # preserves manual rows by `manual:<cluster>:<slug>` keys
- `apps/web/tools/audit-tanstack-keys.mjs`                                # TS-AST queryKey scanner (default-deny)
- `apps/web/tools/audit-pos-local-cache.mjs`                              # POS SQLite cache scanner targeting `apps/pos/src/`

Five scanners, each emitting callsite rows. Test fixtures REQUIRED per Codex N5 (one fixture file per pattern, both positive and negative cases) committed under `apps/api/tests/Application/Sweep/Scanners/Fixtures/` and `apps/web/tools/__fixtures__/`.

1. **php_presentation_exists** — scans Presentation tier files. MUST catch BOTH:
   - inline string rules: `'exists:payment_methods,id'` (single-quoted, double-quoted, with various column names).
   - `Rule::exists()` builder calls without `->where('tenant_id', ...)` AND without `->where('company_id', ...)` chained.
   - Skip files where the controller method has `#[CrossTenantRoute]` attribute (resolved via Reflection) OR where the local AST node has `// @cross-tenant-by-design` annotation matching the strict grammar from Section 3.
   - Fixtures: positive (bare exists), negative (scoped Rule::exists), edge (Rule::exists with `->where()` on non-tenant column → still flagged).

2. **php_ast_find** — `nikic/php-parser` AST traversal (added as dev dep `nikic/php-parser:^5.7`). MUST distinguish:
   - Direct `Model::find($id)` / `Model::findOrFail($id)` → flagged unless preceding `where('tenant_id', ...)` / `where('company_id', ...)` filter is in scope.
   - Chained `Model::query()->where(...)->find($id)` → NOT flagged if the chain includes tenant scope.
   - `$query->find($id)` where `$query` is a builder variable initialized with tenant scope → NOT flagged.
   - `Model::query()->find($id)` without preceding scope → flagged.
   - Fixtures: positive (bare `findOrFail`), negative (scoped builder chain), edge (builder variable extracted into local var, then `->find`).

3. **ts_query_key** — TypeScript compiler API over `apps/web/src/`. **Default-deny** for unknown queryKey factories (Codex T2 + N5):
   - Approved factories: `tenantScopedKey([...])`, `currentCompanyId`, `companyStore.currentCompanyId`, `tenantId` literal, super-admin namespace prefix `'admin'` / `'super-admin'`.
   - Anything else triggers a flag. Developer can add a new factory by registering it in `apps/web/tools/audit-tanstack-keys.config.ts` with a code-review approved-by-name annotation.
   - Fixtures: positive (bare `useQuery({ queryKey: ['x'] })`), negative (scoped `useQuery({ queryKey: tenantScopedKey(['x']) })`), edge (super-admin queryKey with `'admin'` prefix).

4. **pos_sqlite_cache** — TS-AST + SQL DDL parser over `apps/pos/src/lib/db.ts`, `apps/pos/src/lib/db/migrations.ts`, `apps/pos/src/lib/sync/syncService.ts`. Finds:
   - SQL `CREATE TABLE` statements (parsed from string literals in TS) where the table name matches a guarded resource AND the column list lacks `tenant_id` / `company_id`.
   - Sync envelope deserialization handlers that don't validate the envelope's tenant identity against the active auth context.
   - Fixtures: positive (CREATE TABLE products without tenant_id), negative (CREATE TABLE products WITH tenant_id), edge (sync handler that reads envelope but doesn't check tenant).

5. **manual** — for things that can't be mechanically scanned (PlatformIntegration outbound payloads, broadcast channel auth, scheduled jobs that cross tenants, console commands, auth token lifecycle). `stable_key = "manual:<cluster>:<slug>"`. Manual rows are NEVER deleted by regeneration; they only get explicitly removed via `sweep:inventory:defer` or `sweep:inventory:resolve` workflow.

`php artisan sweep:inventory:generate` runs all scanners idempotently. Re-running merges new findings by `stable_key` without resetting state on already-resolved callsites; rename/move rules from Section 4 apply.

Owner: claude. One commit per scanner + fixtures (5-6 commits total, atomic per scanner so each can be reverted independently).

### Section 6: Cluster catalogue (regenerated, not hand-written)

After Section 5 runs, the inventory YAML enumerates clusters. Expected cluster set for the tactical sweep:

**API surface (22 clusters):**

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
| `api.console-commands` | codex | api.treasury | All Artisan commands (compliance, POS, tenant reset, platform enrichment, scheduling, vehicle, workshop, maintenance) — broader than scheduled jobs. Each command must run in a known tenant context. **Added per Codex final R1 / O1.** |
| `api.auth-permissions` | claude | api.treasury | Sanctum token tenant binding, token lifecycle on tenant suspension/deletion, universal `SetPermissionsTeam` middleware coverage on every protected route, Spatie team-id consistency. **Added per Codex final O2.** |
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

**HARD GATE:** No worker may claim non-Treasury API clusters until ALL of:
1. `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` exists.
2. Verdict line 1 is `APPROVE` or `APPROVE-WITH-MINOR-EDITS-APPLIED`.
3. If verdict is `APPROVE-WITH-MINOR-EDITS-APPLIED`, the listed edits have all landed in subsequent commits.
4. YAML cluster status `api.treasury` is `fixed`.
5. `verify_review_commit_linkage`: the review file's "commit reviewed" line names a SHA that exists in the branch history AND matches the cluster's `fix_commit` (or one of them, for multi-commit clusters).

The gate is enforced by `sweep:inventory:claim`, which:
- Reads Treasury cluster status from the YAML.
- Reads the review file path from the cluster's `review_gate.review_file`.
- Parses the verdict line (regex against the documented verdict format).
- If `APPROVE-WITH-MINOR-EDITS-APPLIED`: parses the "Diff to the master plan / cluster" section of the review and verifies each numbered edit has a corresponding history event with `action: "edit_applied"` and a commit SHA.
- Verifies `review_commit_linkage`: the SHA referenced in the review file exists in `git rev-list HEAD` AND is one of the `fix_commit` values stored in cluster callsites.
- Refuses non-Treasury claims with a clear error message naming exactly which check failed.

Workflow (per Section 4 state machine — `submit` and `review` replaced the previous `resolve`):
1. Claude `claim`s `api.treasury` (status: pending → claimed).
2. `start`s the work (claimed → in_progress).
3. Reads every callsite in the cluster.
4. Writes the cluster regression test FIRST (TDD): `apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php`.
5. Verifies RED.
6. Lands fixes per callsite.
7. Verifies GREEN.
8. PHPStan + Pint clean. Commits.
9. `submit`s callsites with `--commit=<sha> --test=<path::name>` (in_progress → under_review). Cannot transition to `fixed` from `submit`.
10. Hands to Codex for **adversarial cluster review** (different agent from the fixer — `reviewer_must_differ_from_owner: true` enforced by `sweep:inventory:review`).
11. Codex writes verdict to `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` referencing the fix commits.
12. Codex (or Claude as the human-driven actor running the artisan CLI on Codex's behalf) calls `sweep:inventory:review --verdict=APPROVE --review-file=<path> --review-commit=<sha>` (under_review → fixed). Only this transition sets `fixed`.

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
8. commit
9. submit callsites with --commit/--test (in_progress → under_review)
10. hand to Claude for adversarial cluster review (cross-agent review)
11. review verdict written to docs/superpowers/reviews/2026-05-02-<cluster>-cluster-claude-review.md
12. Claude (or human-driven actor on Claude's behalf) calls sweep:inventory:review --verdict=APPROVE --review-file=<path> --review-commit=<sha> (under_review → fixed)
```

Cross-agent review is non-negotiable. `sweep:inventory:review` enforces `reviewer_must_differ_from_owner: true` and refuses if the reviewer agent equals the cluster's owner. Codex doesn't review its own cluster fixes.

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
4. **Non-route annotation grammar (Codex R6 fix).** For files that aren't behind a Laravel route — console commands, scheduled jobs, internal services that legitimately operate cross-tenant — the architecture tests accept ONE strict annotation form (and only that form):

   ```php
   /**
    * @cross-tenant-by-design
    * Reason: <one-sentence justification, no line breaks>
    * Audit-id: <ticket or review reference, e.g. CODEX-2026-05-02-treasury>
    * Approved-by: <human reviewer name>
    * Expires: <YYYY-MM-DD or "never">
    */
   ```

   Annotation MUST appear in the docblock of the nearest class or method node (no statement-level skips). The architecture test parses the docblock with the PHP-Parser AST, validates all four fields are present and non-empty, validates the date format, and emits the failure message "missing or malformed @cross-tenant-by-design annotation at <file>:<class-or-method>" if any field is wrong. Annotations that have an `Expires:` date in the past automatically fail the build (forces periodic re-justification).

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

### Section 14: Console-commands cluster — Codex (api.console-commands)

**Files:** every Artisan command under `apps/api/app/Console/Commands/` AND every command under `apps/api/app/Modules/*/Commands/`. Console commands legitimately operate without an HTTP request, so `auth()` and `CompanyContext` aren't middleware-resolved automatically. The cluster verifies:

1. Every command that touches multi-tenant resources EITHER:
   - Accepts a `--tenant=<id>` and `--company=<id>` argument, validates against the tenants/companies tables, and runs the operation under that context (Stancl `Tenant::find($id)->run(fn () => ...)` is the canonical pattern; for shared-DB phase, set `CompanyContext::setCompanyId(...)` explicitly).
   - OR is annotated `@cross-tenant-by-design` per Section 9's grammar (e.g., the `tenant:reset` admin command, which legitimately iterates all tenants).
2. Console commands that schedule background work (`DailyExpiryCheck`, the existing example Codex flagged) iterate explicitly per tenant; they do NOT issue cross-tenant queries.
3. Regression tests: each guarded command has a feature test that asserts cross-tenant inputs are rejected and same-tenant inputs succeed.

Files Codex enumerated (verify against current tree, expand if more found):
- compliance commands, POS chain verification, tenant reset, platform enrichment, scheduling, vehicle, workshop, generated maintenance commands.

Owner: codex. Per-command-cluster commits.

### Section 15: Auth + permissions cluster — Claude (api.auth-permissions)

**Files:**
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php`
- `apps/api/config/permission.php`
- `apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php`
- `apps/api/routes/api.php` (verify every protected route group has `SetPermissionsTeam` in its middleware stack)
- `apps/api/app/Modules/Tenant/Domain/Tenant.php` (suspension/deletion lifecycle)
- New: `apps/api/tests/Architecture/AuthLifecycleTest.php`

The cluster audits the auth and permissions binding layer:

1. **Sanctum token tenant binding.** Tokens issued via `createToken($name, ['*'])` carry no tenant binding today. Audit:
   - When a tenant is suspended, are its users' tokens revoked? Today: no. Add lifecycle hook on `Tenant` model `suspending`/`deleting` events that calls `$user->tokens()->delete()` for every user in the tenant.
   - When a user is moved between tenants (rare but possible), are old tokens revoked? Add same hook on `User::tenant_id` change.
2. **Universal `SetPermissionsTeam` coverage.** Spatie team-id permissions only work if `setPermissionsTeamId($user->tenant_id)` is called on every authenticated request. The middleware exists; verify EVERY protected route group includes it (architecture test scans `routes/api.php` route definitions and asserts the middleware is in every group that doesn't have `#[CrossTenantRoute]`).
3. **Spatie team-id consistency.** When a user's tenant changes (or in any cross-tenant admin operation), the permissions team-id must follow. Audit + add tests.
4. **Token format includes tenant claim.** Even with revocation, a defense-in-depth measure: include the tenant id in the token's abilities array (`createToken($name, ["tenant:$tenantId"])`) and add a middleware that rejects requests where the token's tenant ability doesn't match `auth()->user()->tenant_id`. This catches the corner case where a token was somehow issued cross-tenant.

Regression tests:
- Suspended tenant's users' tokens are revoked at suspension time.
- Deleted tenant's users' tokens are revoked at deletion time.
- Every protected route has `SetPermissionsTeam` (architecture test).
- A token with mismatched tenant claim is rejected (defense-in-depth).

Owner: claude. One commit (or two — lifecycle hooks + architecture test as one, defense-in-depth tenant claim as second).

### Section 16: POS-cluster work BLOCKED on POS orchestrator branch

Per Codex S2 + the consolidation checkpoint: POS-specific tenant-isolation work is NOT done in parallel. Workflow:

1. The tenant-isolation sweep marks `api.pos-stabilization`, `tauri.sqlite-cache`, `tauri.sync-envelope` as `blocked` with `blocked_by_external: pos_orchestrator_branch`.
2. The POS orchestrator does the tenant-isolation work inside their branch (using the Treasury cluster as the established pattern).
3. Either the POS orchestrator branch merges first and the tenant-isolation sweep then resolves these clusters from the merged code, OR the POS orchestrator hands explicit ownership to the sweep with a documented agreement.

This explicitly respects `2026-04-30-pos-consolidation-checkpoint.md`'s "no parallel POS sessions" rule.

### Section 17: Tactical-phase final verification + PR

- [ ] **Step 17.1: Strip `@group sweep-progress` markers** once all non-POS clusters are clean. POS clusters stay in `sweep-progress` until the POS orchestrator finishes.
- [ ] **Step 17.2: Full preflight** across API + web + Tauri.
- [ ] **Step 17.3: Inventory drift check.** `php artisan sweep:inventory:status --drift` (Codex R5 fix). Fails the verification if `drift.yaml_says_fixed_code_unsafe > 0` (code regressions). Warns on `drift.code_safe_yaml_pending > 0` (YAML behind code; surface for resolution).
- [ ] **Step 17.4: Inventory status final report.** `php artisan sweep:inventory:status` shows zero pending, only POS deferred with documented reason.
- [ ] **Step 17.5: All architecture tests green** including `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`, `AuthLifecycleTest`, web `audit-tanstack-keys`, POS `audit-pos-local-cache`, hand-edit detector `SweepInventoryVerifyHistoryCommand`.
- [ ] **Step 17.6: PR `fix/tenant-isolation` → `dev`.** Body lists every cluster commit, every cross-agent review verdict, the inventory's final state, and the deferred POS items.
- [ ] **Step 17.7: Hand to user for `dev → main` promotion.**

---

## Strategic phase — DB hardening, NF525, e-invoicing, ERP migration (Sections 18-23)

Tracked in `docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml` (Codex-authored, 14 items, 6 workstreams). The certification SOT references these section numbers + the tactical YAML's cluster ids via the new `linked_cluster` / `blocked_by_cluster` fields documented in Section 4.

Sequenced AFTER tactical phase lands.

### Strategic decision table (Codex final review N2 fix)

These business/legal decisions cannot be deferred to mid-execution. Each has a DRI, due date, inputs, and blocking consequence.

| Decision | DRI | Due | Inputs | Blocking consequence if undecided |
|---|---|---|---|---|
| NF525 path: self-attestation vs accredited certification | Founder | 2026-06-15 | Codex final review legal context (self-attestation restored 2026-02-24); commercial demand from prospects; certifier engagement cost vs benefit | Section 21 (NF525 evidence pack) cannot scope correctly. Self-att evidence pack is smaller; accredited is larger. |
| PDP partner selection for e-invoicing | Founder + technical lead | 2026-05-31 | impots.gouv approved-platforms list; AutoERP B2B customer pipeline; integration cost; certified Operator de Dématérialisation Partenaire (ODP) vs Plateforme Privée Privilégiée (P3); pricing model | Section 22 (e-invoicing) cannot start integration. PDP onboarding has 4-8 week lead time; sales pipeline blocked for French B2B. |
| Tenancy topology (DB-per-tenant vs schema-per-tenant vs hybrid) | Technical lead | 2026-07-15 | `docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md` peer-group survey; tenant count modeling; engineering capacity for migration; Stancl tooling readiness | Section 23 (ERP migration) cannot start. Affects how all subsequent tenant tables are structured. |
| Sales gate for regulated-country signing | Founder + sales | Ongoing | Section 23 readiness state; PDP integration state; NF525 evidence pack state | Engineering cannot guarantee compliance for French/regulated customers signed before migration completes. |

The decisions are tracked as items in the certification SOT YAML (with status `proposed` until DRI signs, `accepted` after) so the tactical/strategic cadence is auditable.

### Section 18: DB-001 — Database isolation evidence baseline (P0)

Implement the audit command `php artisan security:tenant-isolation:db-audit`. Emits JSON/YAML inventory of: schemas, RLS flags, policies, tenant/company columns, fiscal triggers, FK scope gaps PER TABLE (not aggregated — every table gets a row in the output). Output committed to `docs/superpowers/audits/2026-XX-XX-db-isolation-baseline.yml`.

Owner: claude. ~3-5 days.

### Section 19: DB-010 + DB-011 — Composite tenant/company FK guardrails on POS fiscal tables (P0)

Migration to add composite FKs:
- `pos_receipt_payments(receipt_id, tenant_id, company_id) → pos_receipts(id, tenant_id, company_id)`.
- `pos_receipts(terminal_id, tenant_id, company_id) → pos_terminals(id, tenant_id, company_id)`.
- Same for partner/contact/cashier references.
- Backfill `tenant_id`/`company_id` on child rows that only have parent refs today.

Database-level rejection of cross-tenant FK insertion. Belt-and-suspenders to the application-layer scoping.

Regression fixture per migration: same-tenant insertion succeeds, cross-tenant insertion is rejected at SQL level (error code 23503 or check constraint violation), cross-company-within-tenant is also rejected.

Owner: codex. ~5-7 days, including data migration testing.

### Section 20: DB-020 — RLS pilot on POS fiscal tables (P1)

PILOT, not full rollout. POS fiscal tables only:
- Define `app.tenant_id`, `app.company_id`, `app.super_admin` session variables.
- Add middleware that sets local transaction settings on every request after auth/company resolution. **Queue + console exception (Codex Section 18 confidence note):** queue jobs and console commands set the same variables explicitly via the new tenant-context handlers (Section 14's console-commands cluster gives every guarded command a `--tenant=<id>` argument; queue bootstrappers similarly preserve tenant context per Stancl).
- RLS policies on `pos_receipts`, `pos_receipt_payments`, `pos_z_reports`, `pos_grandtotal_events`, `vouchers`, `voucher_ledger` with `USING` and `WITH CHECK`.
- Console/audit escape hatch for explicit super-admin context: a session-local `app.super_admin = true` bypasses the RLS, settable only by code with the `#[CrossTenantRoute]` attribute or non-route `@cross-tenant-by-design` annotation.
- Tests: direct SQL insert/select/update fails without correct session variables.

This is defense-in-depth, NOT a substitute for explicit Eloquent scoping. The tactical sweep + composite FKs are the load-bearing layers; RLS catches what slips through.

Owner: codex. ~5-7 days.

### Section 21: NF525 certification evidence pack (P1, commercial assurance — Codex final P1 expansion)

Per Codex final review P1: NF525's official ISCA requirements are inalterability, securisation, conservation, and archiving. The 2026 finance law restored editor self-attestation as the legal default; accredited certification is now a commercial/assurance differentiator.

The evidence pack must contain (expanded per Codex P1):

1. **Schema evidence:** migrations, trigger definitions, RLS policies (Section 20), FK inventory (Section 18 audit), version + change-control dossier.
2. **Functional evidence:**
   - Receipt-chain verification (existing v2/v3 fiscal hash chain).
   - Z-report verification.
   - Grand-total continuity (daily / monthly / yearly closings + cross-day chain integrity).
   - Void/refund compensating-receipt flow.
   - Reprint logs.
   - Training-mode exclusion (training receipts must NOT enter the fiscal chain).
3. **Fixture evidence:** v2/v3 golden hash fixture checks (Fixture-01 / Fixture-08 unchanged unless deliberate schema bump), frontend/backend parity hashes, fixture integrity hashes pinned in CI.
4. **Archive evidence (P1 expansion):** explicit archive procedure tests for inalterability over time. Date-certainty evidence (timestamps cannot be backdated; the immutability triggers + the daily/monthly grand-total closings are the cryptographic proofs).
5. **Operational evidence:** backup/restore tested, export tested (FEC for France), archive retention policy (10 years for fiscal records in France), device onboarding runbook, FDE verification on Tauri devices, incident-response runbook.
6. **Change-control evidence:** CI gates that prevent bare exists / unscoped find / query-key leaks (the architecture tests built in Section 3).
7. **Output for self-attestation OR accredited certification:**
   - Self-attestation form: editor signs the ISCA conformance statement; evidence pack is the supporting bundle.
   - Customer-facing compliance attestation (PDF) the customer can present to their tax auditor showing AutoERP's NF525 conformance.
   - If pursuing accredited: the same evidence pack shaped for certifier review (LNE / Infocert).

Pin fixture parity in CI. Schedule certifier/tax-counsel review of the evidence pack structure before building too much bespoke tooling — the certifier may have format preferences that change the output shape.

Owner: human (DRI: founder for cert path) + claude/codex (implementation). ~2-3 weeks for self-attestation evidence; +2-3 weeks if pursuing accredited certification. Drives by NF525 path decision (table above).

### Section 22: French e-invoicing — receive + issue split (P0 for receive, P1 for issue — Codex final P2 expansion)

E-invoicing is split into four sequenced gates per Codex final P2:

1. **RECEIVE (2026-09-01 — legal hard deadline):** all companies must be able to receive e-invoices via an approved PDP. AutoERP must:
   - Implement inbound webhook from PDP (signature validation, idempotency).
   - Map inbound EN16931 XML to AutoERP `documents` (or new `incoming_documents` table) with proper tenant binding.
   - Surface inbound invoices in the customer's accounting UI.
   - Hard deadline: 2026-09-01.
2. **ISSUE — Large/ETI (2026-09-01 — legal hard deadline for those companies):** AutoERP customers in the large/ETI bracket must issue + e-report from this date. Engineering scope:
   - Validate `FacturXService` Basic WL XML against EN16931 schema.
   - PDF/A-3 embedding validation with external validator (e.g., the EN16931 EU validator).
   - PDP submission flow with status polling (NEW / SUBMITTED / ACCEPTED / REJECTED / DELIVERED).
   - Immutable exchange log per invoice.
   - Hard deadline: 2026-09-01 for any AutoERP customer in this bracket.
3. **ISSUE — SME/micro (2027 — legal hard deadline):** SMEs and micros issue + e-report from 2027. Engineering scope: same as above, can land later.
4. **PDP partner selection + integration testing:** pre-condition for both gates above. Decision DRI = Founder, due 2026-05-31 (table above). Integration testing must complete 4-6 weeks before the deadline that gate is hitting; for RECEIVE that means PDP integration done by 2026-07-15.

Source documents:
- `https://www.impots.gouv.fr/professionnel/je-decouvre-la-facturation-electronique`
- `https://www.impots.gouv.fr/facturation-electronique-et-plateformes-partenaires`

Owner: codex + human (PDP partner selection is a business decision; integration is engineering).
- RECEIVE gate: ~2-3 weeks of engineering after PDP partner selected.
- ISSUE-large/ETI gate: ~3-4 weeks of engineering after PDP partner selected.
- ISSUE-SME/micro gate: same engineering, deferred until 2027 ramp.

### Section 23: DB-per-tenant migration decision + execution (P1)

Strategic decision documented + migration executed. Per `docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md`: 4-6 weeks focused work for the migration itself, +1-2 weeks soak.

Sequence:
1. **Decide topology** — DB-per-tenant vs schema-per-tenant vs hybrid. Decision DRI: technical lead, due 2026-07-15 (table above).
2. Split migrations into `database/migrations/` (central) and `database/migrations/tenant/` (per-tenant).
3. Wire `PostgreSQLDatabaseManager` in `tenancy.php`.
4. Update test suite to use Stancl's `TenancyTestKit`.
5. Operational runbook: PgBouncer per-tenant pooling, per-tenant backup, monitoring per tenant, cross-tenant queries via `Tenant::find($id)->run(fn () => ...)`.
6. **Cutover strategy (Codex final P3 expansion):**

   - **Rolling per-tenant cutover.** Each tenant is migrated independently. The migration tooling supports per-tenant export from public schema → import into new tenant DB (or schema), validate row counts + checksums, switch the tenant's connection pointer, validate read/write under new connection, then mark the tenant migrated.
   - **Rehearsal on a sacrificial copy** before any production tenant. Use the latest production backup (anonymized if needed), run the full per-tenant migration on it, measure timing, validate.
   - **Rollback path.** For 7 days post-migration per tenant, the tenant's old data in the public schema is preserved (read-only). If a defect surfaces, the connection pointer rolls back to public schema; new writes since cutover are replayed on the public schema (or the tenant accepts the write loss, documented per tenant).
   - **Dual-write prohibition.** No writes are made to BOTH the old public schema AND the new tenant location at any time. The cutover is atomic per tenant: a single transaction switches the connection pointer.
   - **Maintenance window per tenant: ≤30 minutes.** Communicated to the tenant 7 days in advance. Tunisia tenants migrate first (lowest regulatory pressure if a defect surfaces), French tenants migrate last (most evidence-sensitive).
7. **Sales gate (Codex final N3 fix).** Regulated-country opportunities (France, EU MS with e-invoicing) cannot move to signed/activated status unless one of:
   - Section 23 is complete (migration finished + soak passed) AND Section 22 RECEIVE gate is in place.
   - OR the founder DRI signs a dated exception document acknowledging the customer accepts shared-DB posture for an explicit time window with a migration commitment.
   The sales tooling (Pipedrive / HubSpot / whatever) gets a regulated-country flag that requires this gate to flip a deal stage. Engineering provides the gate state via `php artisan sweep:strategic:status`.

Architecture tests parameterize on `TENANCY_MODE`:
- `TENANCY_MODE=row_level` (current): enforces tenant/company predicates per Section 3.
- `TENANCY_MODE=multi_db` (post-migration): enforces no tenant-table queries on the central connection. The tactical sweep's helper becomes legacy; explicit `where('tenant_id', ...)` becomes redundant under multi-DB but doesn't break.

Owner: claude + codex + human. ~6-8 weeks including soak. Cutover starts after Sections 18-22 land.

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
