# Dev Go-Live Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move `dev` from audit-failed to first-tenant-ready, then harden tenant isolation and security for broader multi-tenant launch.

**Architecture:** The remediation is split into release-risk milestones, not broad refactors. First fix launch blockers and restore gates; then fix known tenant-isolation and production security gaps; then schedule architecture and large-file refactors as incremental post-launch work.

**Tech Stack:** Laravel 12, PHP 8.3 platform / PHP 8.4 local runtime, Pest/PHPUnit, PHPStan, Deptrac, pnpm monorepo, Vite, React, Vitest, Playwright, Tauri POS.

**Review status:** Opus adversarial review on 2026-05-13 returned `REQUEST-CHANGES`. This revision applies the required edits: binding sweep-branch decision, stricter first-tenant gates, complete `CrossTenantRoute` inventory, expanded smoke/security checks, migration audit, and clarified Deptrac/CSP strategy.

---

## Scope And Current Baseline

Audited source:

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf`
- Branch: `dev`
- Commit: `70ea2bcc`
- Audit: `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`

Important branch note:

- `/Users/houssamr/Projects/syneriva/apps/erp` was on `feat/tenant-isolation-sweep-execution` during the audit.
- `/Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf` is the local `dev` worktree that was audited.
- Before implementation, confirm which branch is the source of truth and whether `feat/tenant-isolation-sweep-execution` contains work that must be merged or retired.

## Priority Model

| Priority | Meaning | Launch effect |
| --- | --- | --- |
| P0 | Security or release-gate blocker | Blocks first tenant |
| P1 | High-risk isolation/security issue | Blocks broad multi-tenant launch; may be risk-accepted for a narrow single-tenant POS pilot only |
| P2 | Maintainability, hardening, or warning debt | Does not block first tenant unless it touches a P0/P1 path |
| P3 | Long-term architecture cleanup | Post-launch unless cheap or needed by another fix |

## Milestone Estimate

| Milestone | Target | Estimate | Launch gate |
| --- | --- | --- | --- |
| M0 | Branch/release baseline cleanup | 0.5-1 day | Required before any engineering |
| M1 | First-tenant post-pilot readiness | 8-12 focused days; shorter only if dependency and test-suite fixes are trivial | Required before first tenant |
| M2 | Tenant isolation and security hardening | 1-2 focused weeks | Required before broader multi-tenant launch |
| M3 | Architecture boundary enforcement | 3-7 days for a useful baseline; longer for full cleanup | Can start before launch but should not block first tenant |
| M4 | Refactor backlog | Incremental | Post-launch unless a refactor unblocks security/test work |

## Go/No-Go Definitions

### First Tenant Ready

The first tenant can go live only when:

- No tracked secret material remains in the release branch.
- Every secret present in the tracked `.env.bak` has been rotated or formally confirmed non-production and revoked.
- Secret history is purged from shared release history, or every exposed value has written revocation confirmation before first tenant.
- `composer audit --no-interaction` has no critical/high production-impact advisory.
- `pnpm audit --audit-level moderate` has no unresolved production-impact advisory, or each remaining moderate advisory has a written one-line impact assessment and sign-off.
- `pnpm --filter @autoerp/web typecheck` passes.
- `pnpm --filter @autoerp/pos typecheck` passes.
- `pnpm --filter @autoerp/pos test` passes.
- `pnpm --filter @autoerp/web build` passes.
- `pnpm --filter @autoerp/web test` passes with zero unexplained failures and no new skipped tests.
- Backend PHPStan is green, or any remaining sweep-only exception proves the sweep commands are dev/CI-only, unavailable in production, and unreachable from HTTP, queues, scheduler, and production runbooks.
- `COMPOSER_PROCESS_TIMEOUT=0 composer test` completes with zero unexplained failures; every accepted failure has test name, root cause, production-impact analysis, and tech lead or release-owner sign-off.
- Backend smoke tests for auth, company selection, POS terminal activation, receipt sync, fiscal-chain verification, cash-count drawer close, refunds, manager PIN/discount override, Z-report close, receipt printing, offline backlog drain, and backup/restore pass.
- Production CORS, login/password/POS-activation/manager-PIN rate limits, and privileged audit-log checks are verified.
- Pending migrations are inventoried against production, classified for rollback risk, and approved.
- POS runbook operational values and Tunisia legal sign-off are complete.
- Live-terminal smoke passes; failures block first tenant unless the release owner records explicit risk acceptance.
- All `CrossTenantRoute` annotations are enumerated and classified so the first-tenant scope can explicitly avoid or accept known gaps.

### Multi-Tenant Launch Ready

The broader ERP can go live for multiple tenants only when:

- Known tenant-isolation gaps in production controllers are fixed and covered by regression tests.
- Every `CrossTenantRoute` annotation is classified as `fix-now`, `legitimate-platform`, `accept-with-doc`, or `defer`, and every non-platform row has a fix PR or written risk acceptance.
- File upload/download/email/PDF endpoints enforce tenant and company scope.
- CSP/CORS/rate-limit/session/cookie/logging posture is production reviewed.
- Deptrac hard-fails new Domain-to-Application, Domain-to-Presentation, and Domain-to-Infrastructure leakage; other violations have a committed ratcheting baseline.
- Web and backend test suites are green or have explicit, reviewed, non-production-impact exclusions.

---

## M0: Branch And Release Baseline

**Goal:** Establish the exact branch and commit to remediate before changing code.

**Files:**

- Inspect: `.git`, `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`
- May create after human confirmation: `docs/superpowers/reviews/<date>-dev-remediation-opus-review.md`

### Task M0.1: Confirm Source Branch

- [ ] **Step 1: Confirm local worktrees.**

Run:

```bash
git worktree list
```

Expected:

- One worktree on `dev`.
- One worktree on `feat/tenant-isolation-sweep-execution`.
- No unexplained worktree that also claims to be a release branch.

- [ ] **Step 2: Confirm audited `dev` tip.**

Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf
git branch --show-current
git rev-parse --short HEAD
git status --short
```

Expected:

- Branch is `dev`.
- Commit is either `70ea2bcc` or a later reviewed commit.
- Only audit/plan docs are untracked or modified before implementation starts.

- [ ] **Step 3: Compare `dev` and the tenant-isolation branch.**

Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp-pos-stabperf
git log --oneline --left-right --cherry-pick dev...feat/tenant-isolation-sweep-execution
git diff --stat dev...feat/tenant-isolation-sweep-execution
```

Expected:

- A short list of branch-only commits.
- A written binding decision recorded in `docs/superpowers/reviews/2026-05-13-sweep-branch-disposition.md`: merge, cherry-pick, keep separate, or archive.
- The decision names the exact branch and commit that remediation starts from.
- The decision states whether the first tenant is single-company or multi-company.
- The decision states whether production deploys from a tagged release, `dev` head, or a release branch.
- The decision states whether document email is enabled for the first tenant.
- The decision states whether PDF generation/download and document attachments are exposed for the first tenant.
- No M1/M2 implementation may begin until this decision exists.

- [ ] **Step 4: Apply the sweep-branch decision.**

If the decision is merge:

```bash
git switch dev
git pull --ff-only
git merge --no-ff feat/tenant-isolation-sweep-execution
```

If the decision is cherry-pick:

```bash
git switch dev
git pull --ff-only
git cherry-pick <listed-commit-sha-1> <listed-commit-sha-2>
```

If the decision is archive:

```bash
git branch --contains feat/tenant-isolation-sweep-execution
git log --oneline dev..feat/tenant-isolation-sweep-execution
```

Expected:

- The selected base contains every tenant-isolation fix intended to ship.
- Duplicative M2 work is removed from this plan or re-scoped against the selected base before M1 starts.
- Phase numbering from the sweep branch is not reused by remediation commits.

- [ ] **Step 5: Run post-decision baseline health checks.**

Run on the selected base after merge/cherry-pick/archive decision has been applied:

```bash
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/pos typecheck
pnpm --filter @autoerp/web test
pnpm --filter @autoerp/pos test
cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=1G
cd apps/api && COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected:

- Any new failure introduced by the branch decision is fixed or documented before M1 begins.
- Failure documentation includes command, failing test/error, root cause, production impact, owner, and sign-off role.

- [ ] **Step 6: Create a remediation branch from the agreed source.**

Run after source-of-truth confirmation:

```bash
git switch dev
git pull --ff-only
git switch -c codex/dev-go-live-remediation
```

Expected:

- New branch `codex/dev-go-live-remediation`.
- Clean working tree except the committed audit/plan docs if they are intentionally included.
- Branch starts from the post-decision base, not automatically from `dev@70ea2bcc`.

### Task M0.2: Opus Plan Review Gate

- [ ] **Step 1: Hand this plan to Opus before implementation.**

Ask Opus to review:

- Whether M1 is sufficient for first-tenant readiness.
- Whether any P1 tenant-isolation item must move into M1.
- Whether dependency audit thresholds are acceptable.
- Whether any test failures can be risk-accepted for a narrow POS-first launch.

- [ ] **Step 2: Apply review edits to this plan before coding.**

Expected:

- Review file exists under `docs/superpowers/reviews/`.
- Verdict is `APPROVE`, `APPROVE-WITH-MINOR-EDITS`, or `REQUEST-CHANGES`.
- If `REQUEST-CHANGES`, revise this plan and resubmit.

---

## M1: First-Tenant Post-Pilot Readiness

**Goal:** Remove P0 blockers and restore enough release confidence for one controlled tenant.

### Task M1.1: Remove Tracked Secret Material

**Priority:** P0  
**Files:**

- Remove from git: `apps/api/.env.bak`
- Inspect: `.gitignore`, `apps/api/.env.example`, `apps/api/config/*.php`, `apps/api/config/cors.php`
- Document: `docs/security/secret-rotation-2026-05-12.md`

- [ ] **Step 1: Prove the file is tracked.**

Run:

```bash
git ls-files apps/api/.env.bak
```

Expected:

- Output includes `apps/api/.env.bak`.

- [ ] **Step 2: Remove the tracked file without touching local ignored env files.**

Run:

```bash
git rm apps/api/.env.bak
```

Expected:

- `git status --short` shows `D apps/api/.env.bak`.
- `apps/api/.env` remains ignored and unmodified.

- [ ] **Step 3: Add or verify ignore coverage.**

Inspect `.gitignore` and `apps/api/.gitignore`.

Expected ignore patterns:

```gitignore
.env
.env.*
!.env.example
```

If a tracked production template is needed, use an explicit non-secret filename such as `.env.production.example`.

- [ ] **Step 4: Create the rotation record.**

Create `docs/security/secret-rotation-2026-05-12.md` with:

- exact secret names found in the tracked file
- rotation owner
- rotation status
- target environment
- verification command or provider screenshot reference

Do not include secret values.

- [ ] **Step 5: Run a secret scan on tracked files.**

Run a broad tracked-file scan:

```bash
git grep -nE '(^|_)(SECRET|TOKEN|PASSWORD|PRIVATE_KEY|API_KEY)=|APP_KEY=|DSN=|BEGIN [A-Z ]*PRIVATE KEY|STRIPE_|AWS_|OPENAI_|MEILISEARCH_|MAIL_PASSWORD=' -- ':!apps/api/.env.example' ':!**/*.example'
```

If available locally, also run a dedicated scanner:

```bash
gitleaks detect --source . --no-git --redact
```

If `gitleaks` is not installed, record that in `docs/security/secret-rotation-2026-05-12.md` and rely on the broad `git grep` scan plus human review.

Expected:

- No tracked secret values.
- Example/template values only where explicitly intended.
- Any finding that is intentionally non-secret is documented with file path and reason.

- [ ] **Step 6: Purge or revoke exposed history.**

Preferred for any production-capable value:

```bash
git filter-repo --path apps/api/.env.bak --invert-paths
```

If history rewrite is not possible because shared branches already depend on the history, the first-tenant gate must instead include revocation confirmation for every value found in `apps/api/.env.bak`.

Expected:

- `apps/api/.env.bak` is absent from current tracked files.
- Either the file is absent from release-branch history, or `docs/security/secret-rotation-2026-05-12.md` marks every exposed value as revoked with owner and evidence reference.
- Anyone with existing clones is notified that local history may still contain revoked secrets.

- [ ] **Step 7: Commit.**

Run:

```bash
git add .gitignore apps/api/.gitignore docs/security/secret-rotation-2026-05-12.md
git commit -m "dev-remediation/M1.1: Remove tracked secret backup"
```

Expected:

- Commit succeeds.
- PR body states whether history was purged or revocation-confirmed.

### Task M1.2: Fix Composer Security Audit

**Priority:** P0  
**Files:**

- Modify: `apps/api/composer.json`
- Modify: `apps/api/composer.lock`
- Inspect: Scramble service provider/config/routes if present
- Inspect: `apps/api/config/scramble.php`

- [ ] **Step 1: Reproduce the audit.**

Run:

```bash
cd apps/api
composer audit --no-interaction
```

Expected:

- Fails on `dedoc/scramble` until fixed.

- [ ] **Step 2: Decide whether Scramble is needed in production.**

Inspect:

```bash
composer show dedoc/scramble
rg -n "Scramble|scramble" config app routes tests composer.json
```

Expected:

- If Scramble is only needed for development docs, move it to `require-dev` if not already and ensure production install uses `composer install --no-dev`.
- If Scramble is used in deployed environments, upgrade to a non-vulnerable version and protect the documentation route behind auth or disable it in production.

- [ ] **Step 3: Upgrade or remove the vulnerable package.**

Preferred command if Scramble remains:

```bash
composer require dedoc/scramble:^0.13.22 --dev --with-all-dependencies
```

If `0.13.22` is not available or does not clear the advisory, use the first non-vulnerable version reported by `composer audit`. The version number is not accepted by itself; the advisory must be gone after Step 5.

- [ ] **Step 4: Replace abandoned Deptrac package.**

Run:

```bash
composer remove qossmic/deptrac --dev
composer require deptrac/deptrac --dev --with-all-dependencies
```

Expected:

- `./vendor/bin/deptrac --version` works.
- Existing `deptrac.yaml` can still be parsed or has minimal syntax updates.

- [ ] **Step 5: Verify.**

Run:

```bash
composer audit --no-interaction
composer validate --strict
```

Expected:

- No critical/high production-impact Composer advisory.
- Composer validation passes or only reports accepted warning text documented in the PR.

- [ ] **Step 6: Commit.**

Run:

```bash
git add composer.json composer.lock
git commit -m "dev-remediation/M1.2: Patch Composer security advisories"
```

### Task M1.3: Fix JavaScript Security Audit

**Priority:** P0  
**Files:**

- Modify: `package.json`
- Modify: `pnpm-lock.yaml`
- Inspect: `apps/web/package.json`, `apps/pos/package.json`, `packages/*/package.json`

- [ ] **Step 1: Reproduce advisory list.**

Run:

```bash
pnpm audit --audit-level moderate
```

Expected:

- Fails until vulnerable dependency graph is updated.

- [ ] **Step 2: Update direct dependencies first.**

Run targeted upgrades for direct packages reported by audit, starting with:

```bash
pnpm up react-router @vitejs/plugin-react vite rollup axios --latest -r
```

Expected:

- Lockfile updates.
- Any major-version change is reviewed against app code before commit.

- [ ] **Step 3: Update transitive-only advisories.**

Run:

```bash
pnpm update --latest -r
pnpm dedupe
```

Expected:

- `pnpm audit --audit-level moderate` has no unresolved production-impact advisory, or every remaining moderate advisory has a one-line production-impact assessment and sign-off in `docs/security/frontend-advisory-assessment-2026-05-12.md`.
- Advisory sign-off must come from the security owner or tech lead, not only the implementer.

- [ ] **Step 4: Run frontend gates.**

Run:

```bash
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/pos typecheck
pnpm --filter @autoerp/pos test
pnpm --filter @autoerp/web build
```

Expected:

- All pass.
- Build may still warn about chunks; that is M4 unless a dependency upgrade creates a runtime issue.

- [ ] **Step 5: Commit.**

Run:

```bash
git add package.json pnpm-lock.yaml apps/*/package.json packages/*/package.json
git commit -m "dev-remediation/M1.3: Patch frontend dependency advisories"
```

### Task M1.4: Restore PHPStan And Backend Sweep Inventory Tests

**Priority:** P0  
**Files:**

- Inspect/modify: `apps/api/app/Application/Sweep/InventoryService.php`
- Inspect/modify: `apps/api/app/Console/Commands/SweepInventory*.php`
- Inspect/modify: `apps/api/bootstrap/app.php`
- Inspect/modify: `apps/api/composer.json`
- Inspect/modify: `apps/api/tests/Unit/Application/Sweep/InventoryYamlSchemaTest.php`
- Inspect/modify: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`

- [ ] **Step 1: Reproduce PHPStan failure.**

Run:

```bash
cd apps/api
./vendor/bin/phpstan analyse --memory-limit=1G
```

Expected:

- Current failure references missing `Opis\JsonSchema` classes in `InventoryService.php`.

- [ ] **Step 2: Confirm sweep commands are development/CI tooling, not production operations.**

Evidence to record in the PR body:

- `sweep:inventory:*` commands mutate or inspect `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`.
- They scan source code and review metadata.
- They are not used by HTTP routes, queues, schedulers, POS runtime, fiscal-chain operations, backup/restore, or tenant onboarding operations.
- Production operational compliance commands are separate commands such as fiscal-chain verification/export commands.

Run:

```bash
rg -n "sweep:inventory|InventoryService|Application\\\\Sweep" app routes bootstrap config
php artisan list --raw | rg '^sweep:'
```

Expected:

- Usage is limited to console sweep workflow and tests.
- No production route, scheduled job, queue job, or service provider runtime path depends on sweep inventory mutation.

- [ ] **Step 3: Make dev/CI-only command registration explicit.**

Preferred implementation:

- Keep `opis/json-schema` in `require-dev`.
- Add registration-time gating so `sweep:inventory:*` commands are not registered in `APP_ENV=production`.
- Use a service-provider or console-command discovery guard; a runtime guard inside `handle()` is insufficient because the commands would still appear in `artisan list`.
- Add a test or command-list assertion proving production environment does not expose `sweep:inventory:*`.

Acceptable alternative:

- Remove the Opis dependency from `InventoryService` and use an already-installed validator so sweep commands have no dev-only runtime dependency.

Do not install `opis/json-schema` as a production dependency unless the team explicitly decides sweep commands are production operations.

- [ ] **Step 4: Fix the schema validation dependency according to the Step 3 decision.**

If Step 3 keeps sweep commands as dev/CI-only and schema validation remains:

```bash
composer require opis/json-schema --dev --with-all-dependencies
```

Alternative if the code should not require Opis:

- Replace the Opis-specific validator with the validator already used elsewhere in the repo.
- Keep the public behavior of `InventoryService` unchanged.

- [ ] **Step 5: Reproduce failing inventory tests.**

Run:

```bash
php artisan test tests/Unit/Application/Sweep/InventoryYamlSchemaTest.php
```

Expected:

- Fails until the inventory YAML and schema expectations are aligned.

- [ ] **Step 6: Fix schema/data drift.**

Inspect the failing assertions and update either:

- the inventory YAML if code drift created new current truth, or
- the test expectations if the plan changed and the test is stale.

Do not mark unsafe code as fixed through YAML alone.

- [ ] **Step 7: Verify.**

Run:

```bash
php artisan test tests/Unit/Application/Sweep/InventoryYamlSchemaTest.php
./vendor/bin/phpstan analyse --memory-limit=1G
! APP_ENV=production php artisan list --raw | rg '^sweep:'
```

Expected:

- Inventory schema tests pass.
- PHPStan no longer fails on missing classes.
- Sweep commands are unavailable in production environment, unless the plan was explicitly changed to make them production operations with production dependencies.

- [ ] **Step 8: Commit.**

Run:

```bash
git add composer.json composer.lock app/Application/Sweep tests/Unit/Application/Sweep docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
git commit -m "dev-remediation/M1.4: Restore sweep inventory gates"
```

### Task M1.5: Restore Web Test Suite

**Priority:** P0 for release confidence  
**Files:**

- Inspect/modify: `apps/web/src/features/pricing/pricing.test.tsx`
- Inspect/modify: `apps/web/src/features/pricing/*`
- Inspect/modify: `apps/web/src/features/workshop-bundles/pages/BundleDetailPage.authoring.test.tsx`
- Inspect/modify: `apps/web/src/features/workshop-bundles/*`
- Inspect/modify: `apps/web/src/features/workshop-technicians/pages/__tests__/TechnicianDetailPage.authoring.test.tsx`
- Inspect/modify: `apps/web/src/features/workshop-technicians/*`
- Inspect/modify: `apps/web/src/features/workshop-bundles/components/organisms/BundleComponentFormModal.test.tsx`

- [ ] **Step 1: Re-run only failing test files.**

Run:

```bash
pnpm --filter @autoerp/web test -- \
  src/features/pricing/pricing.test.tsx \
  src/features/workshop-bundles/pages/BundleDetailPage.authoring.test.tsx \
  src/features/workshop-technicians/pages/__tests__/TechnicianDetailPage.authoring.test.tsx \
  src/features/workshop-bundles/components/organisms/BundleComponentFormModal.test.tsx
```

Expected:

- Failures match the audit categories: duplicate headings/loading states, bundle detail not loaded, technician profile not found, unit select state.

- [ ] **Step 2: Classify each failure as product regression or stale test.**

For each failing assertion, record:

- visible user behavior expected
- current rendered behavior
- whether the application behavior or test fixture is wrong

Keep this note in the PR body.

- [ ] **Step 3: Fix tests or code with narrow changes.**

Rules:

- If the UI behavior is correct and the test is brittle, update the test to use accessible queries that match current UX.
- If the UI behavior regressed, fix the component/hook/API mock.
- Do not refactor whole feature folders in this milestone.

- [ ] **Step 4: Verify.**

Run:

```bash
pnpm --filter @autoerp/web test
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web build
```

Expected:

- Web tests pass.
- No new `.skip`, `describe.skip`, or `it.skip` is introduced.
- Existing skipped tests are listed in the PR body with ticket/re-enable date, or removed.
- Typecheck and build remain green.

- [ ] **Step 5: Commit.**

Run:

```bash
git add apps/web/src/features/pricing apps/web/src/features/workshop-bundles apps/web/src/features/workshop-technicians
git commit -m "dev-remediation/M1.5: Restore web release tests"
```

### Task M1.6: Backend Test Strategy And Smoke Gate

**Priority:** P0 for first tenant  
**Files:**

- Inspect/modify: `apps/api/composer.json`
- Inspect/modify: `apps/api/phpunit.xml`
- Create: `docs/qa/2026-05-12-first-tenant-smoke.md`

- [ ] **Step 1: Avoid Composer process timeout masking test results.**

Run:

```bash
cd apps/api
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected:

- Completes with zero failures, or produces a failure list that is fully triaged before first tenant.
- Every accepted failure must be recorded in `docs/qa/2026-05-12-backend-test-exceptions.md` with test name, root cause classification, production-impact analysis, tech lead or release-owner sign-off, and re-check date.
- Backend test-failure exception sign-off must come from the tech lead or release owner, not only the implementer.
- Day-of-launch smoke may be narrower, but it does not replace the full-suite evidence.

- [ ] **Step 2: Define release smoke suites.**

The smoke suite must include:

- auth/login/session
- tenant/company selection
- POS terminal activation
- POS receipt sync
- fiscal hash-chain verify-chain across enabled verticals
- cash-count drawer close
- cash and card refund issuance
- manager PIN verification and discount override
- POS Z-report close, sync, and server recompute
- POS-to-server reconciliation after an offline backlog drain
- document PDF/email disabled or scoped for first tenant
- backup/restore runbook command verification

- [ ] **Step 3: Create smoke protocol.**

Create `docs/qa/2026-05-12-first-tenant-smoke.md` with:

- command list
- expected pass/fail
- manual checks
- owner
- evidence path for screenshots/logs

- [ ] **Step 4: Verify first-tenant gate.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/POS
php artisan test tests/Feature/Accounting/AccountingTenantIsolationTest.php
```

Expected:

- POS feature tests pass.
- Accounting tenant isolation tests pass.
- Any failure is fixed or explicitly moved into M2 with a first-tenant risk decision.

- [ ] **Step 5: Commit.**

Run:

```bash
git add composer.json phpunit.xml docs/qa/2026-05-12-first-tenant-smoke.md docs/qa/2026-05-12-backend-test-exceptions.md
git commit -m "dev-remediation/M1.6: Define first tenant smoke gate"
```

### Task M1.7: POS Operations Finalization

**Priority:** P0 operational gate  
**Files:**

- Modify: `docs/pos-operations/install.md`
- Modify: `docs/pos-operations/manual-update.md`
- Modify: `docs/pos-operations/backup.md`
- Modify: `docs/pos-operations/restore.md`
- Modify: `docs/pos-operations/support.md`
- Modify: `docs/pos-operations/chain-break-recovery.md`
- Modify: `docs/pos-operations/walkthrough-rehearsal.md`

- [ ] **Step 1: Replace unresolved runbook markers with production values.**

Fill actual values for:

- release version
- release artifact URL or internal path
- SHA-256 checksum
- support email/contact
- approved remote support tool
- restore drill operator
- Synerivia observer
- target company identifier

- [ ] **Step 2: Run an unresolved-marker scan.**

Run:

```bash
rg -n "T[B]D|T[O]DO|P[L]ACEHOLDER|<[^>]+>" docs/pos-operations
```

Expected:

- No unresolved operational marker in the files used by the first tenant.

- [ ] **Step 3: Run the rehearsal.**

Follow `docs/pos-operations/walkthrough-rehearsal.md`.

Expected:

- Install/update verification passes.
- Backup creates expected archive.
- Restore drill verifies row counts/checksums.
- Chain-break recovery remains escalation-only.

- [ ] **Step 4: Obtain Tunisia legal-pack sign-off or written risk acceptance.**

Record in `docs/pos-operations/walkthrough-rehearsal.md`:

- accountant or legal reviewer name
- VAT rates confirmed
- receipt legal-field list confirmed
- register certification scope confirmed
- date of sign-off

If sign-off is not available before first tenant, record explicit risk acceptance and owner.

- [ ] **Step 5: Run live-terminal smoke on the deployment terminal.**

Use the release artifact and real device profile.

Expected:

- app version and checksum match the runbook
- clock/timezone check passes
- terminal activation works
- cashier sale, refund, discount override, Z-report close, and restore drill all pass
- evidence is recorded for every step
- any failure blocks first tenant unless the release owner records explicit risk acceptance

- [ ] **Step 6: Commit.**

Run:

```bash
git add docs/pos-operations
git commit -m "dev-remediation/M1.7: Finalize POS operating runbooks"
```

### Task M1.8: First-Tenant Production Security Gate

**Priority:** P0 for first tenant  
**Files:**

- Modify: `apps/api/config/cors.php`
- Inspect/modify: auth route definitions and rate limit providers
- Test: `apps/api/tests/Feature/Security/FirstTenantProductionSecurityTest.php`
- Test: `apps/api/tests/Feature/Security/PrivilegedAuditLogTest.php`

- [ ] **Step 1: Add production CORS fail-fast test.**

Test behavior:

- `APP_ENV=production`
- `supports_credentials=true`
- `CORS_ALLOWED_ORIGINS=*`
- Expected: config validation or bootstrap check fails with a clear message.

- [ ] **Step 2: Add or verify first-tenant rate limits.**

Required endpoints/actions:

- login
- password reset
- POS terminal activation
- manager PIN verification

Expected:

- repeated attempts receive HTTP 429
- limits are scoped by IP and principal/terminal where available
- successful attempts do not disable future legitimate use

- [ ] **Step 3: Add privileged audit-log presence tests.**

Required events:

- role assignment
- void/refund
- discount override
- Z-report close

Expected:

- each action emits an audit entry with tenant id, company id, actor id, action, target id, and timestamp
- tests assert presence, not full reporting UI

- [ ] **Step 4: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Security/FirstTenantProductionSecurityTest.php
php artisan test tests/Feature/Security/PrivilegedAuditLogTest.php
./vendor/bin/phpstan analyse app/Http app/Modules/POS app/Modules/Identity config --memory-limit=1G
```

- [ ] **Step 5: Commit.**

Run:

```bash
git add app config tests/Feature/Security
git commit -m "dev-remediation/M1.8: Add first tenant production security gate"
```

### Task M1.9: Migration Audit And Rollback Policy

**Priority:** P0 for first tenant  
**Files:**

- Create: `docs/qa/2026-05-12-migration-audit-and-rollback.md`
- Inspect: `apps/api/database/migrations`
- Inspect: `apps/api/database/tenant`

- [ ] **Step 1: List pending central and tenant migrations against staging/prod clone.**

Run against the target-like database:

```bash
cd apps/api
php artisan migrate:status
php artisan migrate:status --database=tenant
```

- [ ] **Step 2: Classify each pending migration.**

For each migration, record:

- table(s)
- data movement
- lock risk
- reversible or irreversible
- rollback command or restore requirement
- expected runtime on staging-sized data

- [ ] **Step 3: Write rollback policy.**

Minimum policy:

- backup before migration
- restore point owner
- maximum acceptable downtime
- rollback decision deadline
- migrations that cannot be rolled back with `migrate:rollback`

- [ ] **Step 4: Verify on staging/prod clone.**

Run:

```bash
cd apps/api
php artisan migrate --pretend
php artisan migrate --database=tenant --pretend
```

Expected:

- SQL output reviewed.
- Any irreversible migration is explicitly approved before first tenant.

- [ ] **Step 5: Commit.**

Run:

```bash
git add docs/qa/2026-05-12-migration-audit-and-rollback.md
git commit -m "dev-remediation/M1.9: Document migration rollback policy"
```

### Task M1.10: Wire Release Gates Into CI

**Priority:** P0 for repeatable first-tenant readiness  
**Files:**

- Inspect/modify: `.github/workflows/*.yml`
- Inspect/modify: package scripts in `package.json`
- Inspect/modify: `apps/api/composer.json`

- [ ] **Step 1: Inventory current CI workflows.**

Run:

```bash
rg -n "pnpm|composer|phpstan|deptrac|artisan test|vitest|playwright" .github/workflows package.json apps/api/composer.json
```

- [ ] **Step 2: Add first-tenant gates to CI.**

Required PR gates:

- web typecheck
- POS typecheck
- POS tests
- web tests with no new skips
- web build
- `pnpm audit --audit-level moderate` or advisory-assessment artifact check
- `composer audit --no-interaction`
- PHPStan
- production sweep-command invisibility check
- first-tenant security tests
- privileged audit-log tests

- [ ] **Step 3: Add multi-tenant gates to CI or scheduled CI.**

Required before broad launch:

- M2.x tenant-isolation tests
- M2.0 CSV exposure-rule check
- Deptrac ratchet wrapper
- file/PDF endpoint scope review tests as they are created

- [ ] **Step 4: Verify CI commands locally.**

Run the workflow commands locally or with the repository's CI simulator if one exists.

Expected:

- New CI commands match the Final Verification Matrix.
- Any gate intentionally not run on every PR is documented with trigger and owner.

- [ ] **Step 5: Commit.**

Run:

```bash
git add .github/workflows package.json apps/api/composer.json
git commit -m "dev-remediation/M1.10: Wire release gates into CI"
```

---

## M2: Tenant Isolation And Security Hardening

**Goal:** Remove known P1 cross-tenant risks and harden public production surfaces.

### Task M2.0: Enumerate And Classify Every Cross-Tenant Route

**Priority:** P1 before broad launch; P0 inventory before first tenant scope approval  
**Files:**

- Create: `docs/security/cross-tenant-route-inventory-2026-05-12.csv`
- Inspect: `apps/api/app`
- Inspect: `apps/api/routes`

- [ ] **Step 1: Generate raw annotation list.**

Run:

```bash
rg -n "CrossTenantRoute\\(reason" apps/api/app apps/api/routes > /tmp/cross-tenant-route-raw.txt
```

Expected:

- Every annotation appears once with file and line.

- [ ] **Step 2: Create classification CSV.**

CSV columns:

```csv
file,line,class,method,route,reason,classification,first_tenant_exposed,fix_pr_or_acceptance,owner,notes
```

Allowed `classification` values:

- `fix-now`
- `legitimate-platform`
- `accept-with-doc`
- `defer`

- [ ] **Step 3: Classify every annotation.**

Rules:

- `legitimate-platform` requires super-admin/platform-only middleware and a reason.
- `accept-with-doc` requires written first-tenant or multi-tenant risk acceptance.
- `defer` requires proof the route is unreachable in the relevant launch scope.
- Any tenant/operator-facing route defaults to `fix-now`.

- [ ] **Step 4: First-tenant exposure decision.**

For every row, set `first_tenant_exposed` to `yes` or `no`.

Expected:

- No `first_tenant_exposed=yes` row remains unclassified.
- Any `first_tenant_exposed=yes` row that is not `legitimate-platform` has a fix PR or signed risk acceptance before first tenant.

- [ ] **Step 5: Verify.**

Run:

```bash
test "$(tail -n +2 docs/security/cross-tenant-route-inventory-2026-05-12.csv | awk -F, 'NF < 11 {print}' | wc -l | tr -d ' ')" = "0"
test "$(tail -n +2 docs/security/cross-tenant-route-inventory-2026-05-12.csv | awk -F, '$8 == "yes" && $7 != "legitimate-platform" && $9 == "" {print}' | wc -l | tr -d ' ')" = "0"
```

Expected:

- Every CSV row has all required columns populated.
- Every `first_tenant_exposed=yes` row that is not `legitimate-platform` has `fix_pr_or_acceptance` populated.

- [ ] **Step 6: Commit.**

Run:

```bash
git add docs/security/cross-tenant-route-inventory-2026-05-12.csv
git commit -m "dev-remediation/M2.0: Classify cross tenant route annotations"
```

### Task M2.1: Fix Product Image Tenant Scope

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Modules/Product/Presentation/Controllers/ProductImageController.php`
- Test: `apps/api/tests/Feature/Product/ProductImageTenantIsolationTest.php`

- [ ] **Step 1: Write regression tests.**

Test cases:

- Tenant A cannot download Tenant B product image by image UUID.
- Tenant A cannot update/delete/reorder Tenant B product image.
- Mixed-ID request with Tenant A product UUID and Tenant B image UUID is rejected.
- Product image index/list endpoint returns only images belonging to the scoped product.
- Mutation payload cannot override `tenant_id`, `company_id`, or team context.
- Product-image mismatch returns 404 or 403, not silent mutation.
- Foreign and nonexistent IDs use consistent not-found behavior so ID enumeration is not exposed.
- Valid same-tenant product/image flow still works.

- [ ] **Step 2: Implement scoped lookups.**

Implementation rule:

- Resolve product through authenticated tenant/company context.
- Resolve image through the product relationship or a query constrained by product id, tenant id, and company id.
- Remove `CrossTenantRoute` annotations from fixed methods.

- [ ] **Step 3: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Product/ProductImageTenantIsolationTest.php
./vendor/bin/phpstan analyse app/Modules/Product --memory-limit=1G
```

### Task M2.2: Fix Document Email Tenant Scope

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Modules/Communication/Presentation/Controllers/DocumentEmailController.php`
- Test: `apps/api/tests/Feature/Communication/DocumentEmailTenantIsolationTest.php`

- [ ] **Step 1: Write regression tests.**

Test cases:

- Tenant A cannot generate/email Tenant B document PDF.
- Tenant A cannot see whether Tenant B document exists through response timing or status text.
- Mixed valid+foreign IDs in document/email payloads are rejected before PDF or attachment generation.
- Mutation payload cannot override `tenant_id`, `company_id`, or team context.
- List/history endpoints for document email state return only scoped documents.
- Valid same-tenant document email flow still works.

- [ ] **Step 2: Implement scoped document resolution.**

Implementation rule:

- Replace route model binding trust with a scoped query using tenant/company context.
- Keep email sending delegated to the existing application service.
- Do not load attachments or PDF data until after tenant/company scope is proven.

- [ ] **Step 3: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Communication/DocumentEmailTenantIsolationTest.php
./vendor/bin/phpstan analyse app/Modules/Communication app/Modules/Document --memory-limit=1G
```

### Task M2.3: Fix Document Additional Cost Tenant Scope

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php`
- Test: `apps/api/tests/Feature/Document/DocumentAdditionalCostTenantIsolationTest.php`

- [ ] **Step 1: Write regression tests.**

Test cases:

- Tenant A cannot list/add/update/delete additional costs on Tenant B document.
- Tenant A cannot allocate costs using Tenant B document lines.
- Mixed-ID request with Tenant A document UUID and Tenant B additional-cost or line UUID is rejected.
- Additional-cost list endpoint returns only costs belonging to the scoped document.
- Mutation payload cannot override `tenant_id`, `company_id`, or team context.
- Foreign and nonexistent IDs use consistent not-found behavior.
- Valid same-tenant allocation still computes the same totals as before.

- [ ] **Step 2: Implement scoped document and cost lookup.**

Implementation rule:

- Document query must include tenant and company predicates.
- Additional cost query must be constrained to the scoped document.
- Allocation must use only lines belonging to the scoped document.

- [ ] **Step 3: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Document/DocumentAdditionalCostTenantIsolationTest.php
./vendor/bin/phpstan analyse app/Http/Controllers/Api app/Modules/Document --memory-limit=1G
```

### Task M2.4: Fix Role Assignment Tenant Scope

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php`
- Test: `apps/api/tests/Feature/Identity/RoleTenantIsolationTest.php`

- [ ] **Step 1: Write regression tests.**

Test cases:

- Tenant admin cannot assign a role to another tenant's user by UUID.
- Tenant admin cannot remove a role from another tenant's user.
- Tenant admin cannot list another tenant user's roles.
- Tenant admin cannot assign a valid same-tenant user a Tenant B-scoped role by role UUID.
- Mutation payload cannot override `tenant_id`, `company_id`, or Spatie team context.
- Foreign and nonexistent user/role IDs use consistent not-found behavior.
- Platform/super-admin path still works only through an explicitly cross-tenant route if that behavior is required.

- [ ] **Step 2: Implement scoped user resolution.**

Implementation rule:

- Tenant-scoped role operations resolve users through tenant/company membership.
- Super-admin behavior uses a separate method or explicit `CrossTenantRoute` annotation with middleware.

- [ ] **Step 3: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Identity/RoleTenantIsolationTest.php
./vendor/bin/phpstan analyse app/Modules/Identity --memory-limit=1G
```

### Task M2.5: Fix Purchase Hub Cache Tenant Scope

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubOfferController.php`
- Test: `apps/api/tests/Feature/PurchaseHub/PurchaseHubOfferTenantIsolationTest.php`

- [ ] **Step 1: Write regression test.**

Test case:

- Tenant A cached offer response is never returned to Tenant B.
- Tenant switch in the same browser/session does not reuse the previous tenant's cached response.
- Cache expiry/TTL invalidation does not repopulate from another tenant's key.
- List endpoints and detail endpoints use the same tenant/company cache key dimensions.

- [ ] **Step 2: Include tenant/company in cache key.**

Implementation rule:

- Cache key includes tenant id and company id if offers are company-scoped.
- Cache invalidation uses the same scope key.

- [ ] **Step 3: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/PurchaseHub/PurchaseHubOfferTenantIsolationTest.php
./vendor/bin/phpstan analyse app/Modules/PurchaseHub --memory-limit=1G
```

### Task M2.6: Remaining Production Security Headers And Rate Limits

**Priority:** P1  
**Files:**

- Modify: `apps/api/app/Http/Middleware/SecurityHeaders.php`
- Inspect/modify: `apps/api/config/cors.php`
- Inspect/modify: route service providers and route files under `apps/api/routes` and `apps/api/app/Modules/*/routes.php`
- Test: `apps/api/tests/Feature/Security/SecurityHeadersTest.php`
- Test: `apps/api/tests/Feature/Security/RateLimitTest.php`

- [ ] **Step 1: Add tests for production headers per route group.**

Expected headers in production:

- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy`
- `Permissions-Policy`
- `Strict-Transport-Security`
- `Content-Security-Policy`

Expected CSP assertion:

- API routes have the strict API CSP.
- Web routes have the web CSP that allows required first-party assets and enumerated external origins.
- Documentation routes, if enabled outside production, have their own scoped CSP.

- [ ] **Step 2: Implement CSP with route-aware policies.**

Initial API CSP:

```text
default-src 'none'; frame-ancestors 'none'; base-uri 'none'
```

Rules:

- API responses can use the strict `default-src 'none'` policy.
- Web app responses must use a separate policy that permits the app's own JS/CSS/assets and explicitly enumerates any required API, Reverb, font, image, or telemetry origins.
- API documentation routes, if enabled outside production, get their own policy and must not weaken the API default.

- [ ] **Step 3: Verify remaining rate limits exist.**

Review and add limits for:

- document email sending
- API token issuance
- any privileged route left exposed by `docs/security/cross-tenant-route-inventory-2026-05-12.csv`

- [ ] **Step 4: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Security
./vendor/bin/phpstan analyse app/Http config routes --memory-limit=1G
```

### Task M2.7: File And PDF Endpoint Scope Review

**Priority:** P1  
**Files:**

- Inspect/modify: document attachment controllers
- Inspect/modify: product image controllers
- Inspect/modify: PDF generation/download controllers
- Create: `docs/security/file-endpoint-scope-review-2026-05-12.md`

- [ ] **Step 1: Inventory endpoints.**

Run:

```bash
rg -n "download|upload|attachment|pdf|image|Storage::|response\\(\\)->file|streamDownload" apps/api/app apps/api/routes
```

- [ ] **Step 2: For each endpoint, record scope controls.**

Document:

- route
- controller method
- tenant predicate
- company predicate
- storage visibility
- MIME validation
- size limit
- signed URL behavior

- [ ] **Step 3: Fix endpoints missing tenant/company checks.**

Use the same test-first pattern as M2.1-M2.5.

- [ ] **Step 4: Verify.**

Run targeted tests for each touched endpoint, then:

```bash
cd apps/api
./vendor/bin/phpstan analyse app/Modules app/Http --memory-limit=1G
```

---

## M3: Architecture Boundary Enforcement

**Goal:** Make the hexagonal boundary claims enforceable without trying to refactor the whole app before first launch.

### Task M3.1: Update Deptrac To Current Modules

**Priority:** P2 before first tenant, P1 before broad launch  
**Files:**

- Modify: `apps/api/deptrac.yaml`
- Create: `docs/architecture/deptrac-baseline-2026-05-12.md`

- [ ] **Step 1: List current modules.**

Run:

```bash
find apps/api/app/Modules -maxdepth 1 -mindepth 1 -type d -print | sort
```

- [ ] **Step 2: Add missing modules to Deptrac.**

Include at least:

- POS
- Voucher
- Taxation
- Billing
- Marketplace
- PlatformIntegration
- BatchExpiry
- Uom
- Coupon

- [ ] **Step 3: Decide baseline strategy.**

Recommended:

- Do not require zero violations immediately.
- Commit baseline data to `apps/api/deptrac.baseline.json`.
- Create wrapper script `apps/api/tools/deptrac-ratchet.php`.
- Hard-fail any new Domain-to-Application, Domain-to-Presentation, or Domain-to-Infrastructure dependency leakage.
- Ratchet all other violation categories so the total cannot increase.
- Create separate tickets for existing high-risk Domain leakage and cross-module Application coupling.

- [ ] **Step 4: Verify.**

Run:

```bash
cd apps/api
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
```

Expected:

- Wrapper emits the raw Deptrac output plus category counts.
- Wrapper compares against `deptrac.baseline.json`.
- New Domain-layer leakage fails even if the total baseline count does not increase.
- Non-domain categories fail only when their baseline count increases.

### Task M3.2: Remove Service Locator From Domain-Critical Paths

**Priority:** P2, promoted to P1 where it blocks tests/security  
**Files:**

- Modify: `apps/api/app/Modules/Document/Domain/Document.php`
- Inspect/modify: Form Requests using `app(...)`
- Inspect/modify: validation rules using `app(...)`
- Test: document total recalculation tests

- [ ] **Step 1: Inventory service locator usage.**

Run:

```bash
rg -n "\\bapp\\(|App::make|resolve\\(" apps/api/app
```

- [ ] **Step 2: Classify each usage.**

Categories:

- acceptable provider/container factory
- presentation-layer convenience
- application/domain dependency leak
- test-only helper

- [ ] **Step 3: Fix the highest-risk domain case.**

For `Document::recalculateTotals()`:

- Move tax calculation orchestration to an application/domain service with constructor injection.
- Keep the model responsible for state, not container lookup.
- Preserve existing totals behavior with tests.

- [ ] **Step 4: Verify.**

Run:

```bash
cd apps/api
php artisan test tests/Unit tests/Feature/Document
./vendor/bin/phpstan analyse app/Modules/Document --memory-limit=1G
```

---

## M4: Refactor Backlog And Maintainability

**Goal:** Keep launch focused while creating an ordered refactor path.

### Task M4.1: Create Refactor Priority Register

**Priority:** P2/P3  
**Files:**

- Create: `docs/architecture/refactor-priority-register-2026-05-12.md`

- [ ] **Step 1: Record large-file candidates.**

Initial priority order:

1. `apps/web/src/routes/index.tsx`
2. `apps/pos/src/lib/sync/syncService.ts`
3. `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
4. `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
5. `apps/pos/src/pages/HomePage.tsx`
6. `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`
7. `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php`
8. `apps/api/app/Modules/Report/Application/Services/ReportGenerationService.php`
9. `apps/web/src/components/organisms/AdvancedPaymentsModal.tsx`
10. `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php`

- [ ] **Step 2: Assign refactor trigger.**

For each file, record one trigger:

- security fix needs it
- testability blocks work
- active feature churn
- post-launch maintenance only

- [ ] **Step 3: Define first refactor candidate.**

Recommended first candidate after first tenant:

- `apps/web/src/routes/index.tsx` split by feature route modules, because it is large and low domain-risk.

### Task M4.2: Lint Warning Ratchet

**Priority:** P2  
**Files:**

- Modify: `apps/web/eslint.config.js`
- Modify: `apps/pos/eslint.config.js`
- Create: `docs/qa/lint-warning-ratchet-2026-05-12.md`

- [ ] **Step 1: Capture baseline.**

Run:

```bash
pnpm --filter @autoerp/web lint | tee /tmp/web-lint.txt
pnpm --filter @autoerp/pos lint | tee /tmp/pos-lint.txt
```

- [ ] **Step 2: Categorize warnings.**

Categories:

- style/token warning accepted for now
- hooks/React correctness
- TypeScript safety
- accessibility
- dead code

- [ ] **Step 3: Add ratchet.**

Fail CI only if warning count increases, then reduce warning families incrementally.

---

## Final Verification Matrix

Before declaring first-tenant readiness:

```bash
pnpm install --frozen-lockfile
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/pos typecheck
pnpm --filter @autoerp/pos test
pnpm --filter @autoerp/web test
pnpm --filter @autoerp/web build
pnpm audit --audit-level moderate
cd apps/api && composer install
cd apps/api && composer audit --no-interaction
cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=1G
cd apps/api && ! APP_ENV=production php artisan list --raw | rg '^sweep:'
cd apps/api && php artisan test tests/Feature/POS
cd apps/api && php artisan test tests/Feature/Accounting/AccountingTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/Security/FirstTenantProductionSecurityTest.php
cd apps/api && php artisan test tests/Feature/Security/PrivilegedAuditLogTest.php
cd apps/api && COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Before declaring broader multi-tenant readiness:

```bash
cd apps/api && php artisan test tests/Feature/Product/ProductImageTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/Communication/DocumentEmailTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/Document/DocumentAdditionalCostTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/Identity/RoleTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/PurchaseHub/PurchaseHubOfferTenantIsolationTest.php
cd apps/api && php artisan test tests/Feature/Security
cd apps/api && php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
test -s docs/security/cross-tenant-route-inventory-2026-05-12.csv
```

## Opus Review Request

Ask Opus to review this plan with these specific questions:

1. Is M1 sufficient for one controlled first tenant, or must any M2 tenant-isolation fix move into M1?
2. Is the dependency audit threshold acceptable, or should moderate advisories also block first tenant?
3. Is the PHPStan exception path acceptable if sweep commands are explicitly dev/CI-only and unavailable in production, or must full PHPStan be green?
4. Are the proposed tenant-isolation tests enough to prove the known route gaps are closed?
5. Should Deptrac be a hard launch gate for broad multi-tenant go-live or a ratcheted baseline gate?

## Execution Recommendation

Use subagent-driven execution after Opus approval:

- Worker 0: M0 branch/source-of-truth decision; no implementation starts until this is complete.
- Worker 1: M1.1 secrets and first-tenant release baseline.
- Worker 2: M1.2/M1.3 dependency audits.
- Worker 3: M1.4 backend PHPStan/sweep tests.
- Worker 4: M1.5/M1.6 web and backend test restoration.
- Worker 5: M1.8/M1.9 first-tenant security and migration gates.
- Worker 6+: M2 tenant-isolation fixes, one controller cluster per worker after M0 is resolved and M2.0 inventory is complete.

Do not start M4 refactors until M1 is complete and M2 scope is either complete or explicitly scheduled.
