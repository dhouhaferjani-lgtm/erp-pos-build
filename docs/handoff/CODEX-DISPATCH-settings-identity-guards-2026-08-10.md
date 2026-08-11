# Codex A→Z dispatch — company fiscal-identity guards (2026-08-10)

**Model/effort (owner directive):** Codex workhorse mode, HIGH effort. Implement end-to-end,
TDD red-first, hand back for reviewer gates.
**Owner ruling (2026-08-10, direct):** GUARDS NOW, SCOPING FOLLOW-UP — this lane ships the
guards; company-scoping of `tax_configurations` is explicitly OUT (its own reviewed lane later).
**Source finding:** `docs/superpowers/tickets/2026-08-10-country-code-mutable-authz-authority.md`
(gate F-1, tenancy-authz-reviewer, branch codex/accounting-gaps-cghi). Read it first — it has the
full escalation scenario and the file:line map.

## Why

`company.country_code` is runtime-mutable by any holder of `settings.update`
(`UpdateCompanySettingsRequest.php:41` — `sometimes|nullable|string|size:2|alpha`, no exists, no
immutability; `CompanySettingsController.php:113` and the `address.country` overwrite at `:150`).
Since the CGHI merge, country_code is an **authorization authority** (stamp-duty capability,
timbre path into signed fiscal payloads) on top of what it always was: the driver of COA seeding,
tax seeding, GL mapping, and monetary scale. `currency` has the same character (drives
CurrencyScale). Fiscal identity must not be self-service-mutable.

## In scope — three pieces, one branch `fix/company-identity-guards` off current `origin/dev` (≥ `7d85232cc`)

### 1. Immutability of fiscal-identity fields post-provisioning
- `country_code` and `currency` on Company: reject ANY change via the tenant settings surface
  once the company is provisioned. Kill BOTH write paths: the direct field
  (`CompanySettingsController.php:113`) and the `address.country` overwrite (`:150`).
  Typed validation error, translated en+fr (backend i18n convention: `lang/{en,fr}/` — see
  `lang/en/taxation.php` from the CGHI lane for the pattern).
- Survey for OTHER writers of these two columns before implementing (imports, seeders acting on
  live tenants, admin endpoints). Seeders provisioning NEW companies are legitimate and out of
  scope; anything mutating an EXISTING company's identity is in scope. Enumerate what you find in
  the report even if legitimate.
- The correction path for genuine mistakes (typo before go-live) is the super-admin
  support-access flow (impersonation lane, merged `c42a202ae`) — this lane does NOT build a
  correction UI; it documents the procedure in the report and leaves the support flow as-is.
  (If impersonation sessions route through the same tenant controller, the guard applies to them
  too — that is CORRECT for v1; note it.)
- Decision point you must NOT decide alone: if you find a legitimate in-product flow that changes
  country/currency post-provisioning (e.g. an onboarding wizard step that re-submits settings),
  make the guard tolerate idempotent same-value writes (`country_code` unchanged ⇒ pass) rather
  than refusing, and record the flow in the report.

### 2. Fiscal-settings permission split
- New permission `settings.fiscal.update` (naming: match the existing `settings.*` family in
  `RolesAndPermissionsSeeder`). Identity fields (country_code, currency, tax-identity fields —
  enumerate what `CompanySettingsController` exposes: tax id / matricule / RC etc.) require it;
  cosmetic settings keep `settings.update`.
- Seed to owner/admin-tier roles only (mirror how the seeder tiers existing sensitive
  permissions). ⚠️ Deploy note in the report: permission additions require reseed +
  `permission:cache-reset` (tenant-blind cache).
- Both layers: backend route/controller enforcement AND FE gating (RequirePermission /
  hasPermission on the settings form fields) per the both-layers house rule.

### 3. Audit + confirm on identity-field changes
- Any accepted change to a fiscal-identity or tax-identity field writes an `audit_events` entry
  with old/new values (follow the existing AuditService::record pattern —
  `TreasuryReceiptBridge` shows the call shape; here you are in HTTP context, simpler).
- FE: explicit confirmation step on those fields (type-to-confirm or modal confirm, match an
  existing destructive-action pattern in apps/web rather than inventing one), en+fr strings.

## Out of scope (do NOT touch)
- `tax_configurations` company-scoping (follow-up lane, owner-ruled).
- MFA (own lane), impersonation internals, the stamp-capability code from CGHI.
- Widening `code` varchar(20) or anything else from `2026-08-10-tax-config-brownfield-minor-gaps.md`.

## House rules (binding)
TDD red-first per piece; tests BY PATH only (full PHPUnit suite FORBIDDEN — crashes the machine);
never `git stash` (repo-global across worktrees); rule 19 money discipline; strict types;
constructor injection only; en+fr for every user-facing string; revert-replay each fix commit;
a real PostgreSQL run before any green claim (local PG 5432; Docker 5433 broken); dedicated
worktree off origin/dev — Codex CLI cannot write to `apps/erp.*` worktrees, so run via Codex
desktop or a fresh worktree under a writable path.

## Quality gates (orchestrator-side, after handback)
1. **tenancy-authz-reviewer** — primary gate (permission split, guard bypass hunting, both-layer
   gating, FE fail-closed).
2. **fiscal-pos-reviewer** — light pass: confirms no sealed-bytes/fiscal-path impact and that the
   immutability guard cannot brick a legitimate fiscal flow.
3. Standard evidence contract: per-piece red-first proof, revert-replay record, SQLite+PG counts,
   pint/phpstan clean on touched files, FE vitest+typecheck+eslint. Fix rounds ≤5, scoped
   re-reviews.

## Deliverable
Branch `fix/company-identity-guards` (NOT merged, NOT pushed), report
`docs/sessions/codex-settings-identity-guards-report.md` with per-piece: files, tests + commands +
output, decisions, the other-writers survey, the deploy note (reseed + cache-reset), and concerns.
Orchestrator merges only after gates pass. Update ticket
`2026-08-10-country-code-mutable-authz-authority.md` status to reference this lane (guards) and
the remaining follow-up (scoping).
