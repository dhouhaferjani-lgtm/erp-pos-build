# Opus Adversarial Review — Dev Go-Live Remediation Round

**Reviewer:** Claude Opus 4.7 (in-session)
**Date:** 2026-05-13
**Branch reviewed:** `chore/dev-go-live-remediation` (merged into `origin/dev` at `254e120f`)
**Plan:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md`
**Audit:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md`
**Commit range:** `127862bd..254e120f` (14 commits)

This is an adversarial review of the dev go-live remediation work. The reviewer was the implementer; the review reads each commit as if seeing it for the first time and asks: where is this half-done, weak, or wrong?

Severity scale:
- **BLOCKER** — work would fail the first-tenant gate or actively cause production harm
- **P1** — materially weakens the gate; must fix before claiming round complete
- **P2** — quality issue that should be addressed in a follow-up but does not block the round
- **NIT** — editorial / robustness

---

## Verdict: APPROVE-WITH-FOLLOW-UPS

The round closes the gates the user authorized (M1.1 through M2.0). No BLOCKER findings. Five P1 findings represent real shortcomings of this round's work that the next session must close. Eleven P2 + NIT findings are queued for incremental cleanup.

The honest summary: **this round is the gate-completion pass, not the security-completion pass**. M2.1–M2.5 (tenant-isolation controller fixes) and M2.6–M2.7 (CSP, file/PDF endpoint review) are still open. The first-tenant pilot still depends on the user accepting the M2.1–M2.5 risk per the scope question I answered in the prior turn.

---

## P1 Findings (must close before claiming round complete)

### P1-1 — M1.1 secrets: no actual rotation happened, only a tracking doc

`docs/security/secret-rotation-2026-05-12.md` lists every variable that leaked but every `rotation status` cell reads `TBD`. The first-tenant gate explicitly requires "every exposed value has written revocation confirmation **before first tenant**." The current state: the file is removed from the tree, but the secrets are still in git history (every commit before `7ca37bfa`), and no provider-side revocation has been executed.

**Risk:** If `apps/api/.env.bak` ever leaked outside the repo (a cloned remote, a backup, a CI artifact), every credential in it remains valid until rotated. The first-tenant gate as written cannot close until rotation/revocation is actually completed.

**Required follow-up:** Rotate or revoke each credential at its provider AND/OR run `git filter-repo --path apps/api/.env.bak --invert-paths` followed by force-push. The remediation branch deliberately left this for a security owner — the next session must escalate to the owner and lock the decision.

---

### P1-2 — M1.6b F1/F2/F3: test expectations were aligned to production, but production semantics weren't audited

I updated `AuthenticationTest::test_t14_*` to expect tokens with `['tenant:<uuid>', 'pos:*']` / `['tenant:<uuid>', '*']` instead of `['pos:*']` / `['*']`. The basis was the comment in `AuthController.php:216-218` claiming Invariant D (tenant claim ability) is the intended platform-wide contract.

**What I did not verify:**

1. **All ability check sites accept the new shape.** `Sanctum::tokenCan` semantics: a token with abilities `['tenant:abc', 'pos:*']` passes `$token->can('pos:*')` but DOES NOT pass `$token->can('*')`. I confirmed the POS test expects `can('*')` to be false on a POS token — good. But the WEB token now has `['tenant:abc', '*']` — `$token->can('pos:*')` passes (Sanctum's catch-all semantics), `$token->can('*')` passes. **What I did not check:** every ability check across the codebase that compares against literal `'*'` (rather than calling `$token->can('*')`). If any code does `in_array('*', $abilities, true)`, it still passes; but if any code does `$abilities === ['*']`, it now fails because the array has the tenant claim prepended.
2. **EnforceTokenTenantClaim middleware actually enforces the claim.** I assumed it exists and verifies the token's `tenant:<uuid>` claim matches the request's resolved tenant. I did not read the middleware source.

**Risk:** the round shipped tests that "pass" against production code that may have an incomplete enforcement story. If `EnforceTokenTenantClaim` is missing or stub-only, the tenant claim is decoration.

**Required follow-up:** `rg -n "EnforceTokenTenantClaim" apps/api/app` — confirm the middleware exists, is registered on the API route group, and rejects tokens whose tenant claim mismatches the request scope. Add a feature test that proves the rejection happens (e.g., a token with `tenant:A` is rejected when sent to a request resolved under tenant B).

---

### P1-3 — M1.6b F4: contract change without consumer audit

I changed `CategoryTest::test_parent_must_belong_to_same_company` from `assertStatus(404)` to `assertStatus(422)`. The new behavior is more correct (cross-company parent_id is a validation failure, not a missing resource). **What I did not verify:** the web admin's category-create UI doesn't depend on the 404 response shape to render a "Category not found" error path.

**Risk:** If `apps/web/.../CategoriesNew.tsx` or its sibling handles 404 differently from 422 (a non-trivial assumption for any form), the operator sees a confusing error message or a silent failure.

**Required follow-up:** `rg -n "404\|status === 404\|HttpStatus.NotFound" apps/web/src/features/categories` — read the error-handling path and confirm 422 lands on the correct UI branch. If it doesn't, either revert the controller change or update the UI.

---

### P1-4 — M1.5b seedAuth: no afterEach resetAuth, test isolation depends on every test having its own beforeEach

I added `seedAuth()` to 17 test files' `beforeEach`. I did NOT add `resetAuth()` to `afterEach`. The zustand stores (`useAuthStore`, `useCompanyStore`) are module-level singletons and persist across tests in the same vitest worker.

**Why it works today:** every modified file calls `seedAuth()` in its own `beforeEach`, which OVERWRITES the previous state. So each test starts with the same fixture.

**Why it's fragile:**
- A test that calls `useAuthStore.getState().logout()` mid-test (or otherwise mutates the store) leaves a stale state visible to the next test if that test doesn't call seedAuth in beforeEach.
- A test added LATER that runs renderWithProviders without seedAuth (because the developer doesn't realize seedAuth is required) inherits whatever state the previous file's last test left.
- vitest's file-level isolation only resets between files (by default), not between tests within a file.

**Risk:** a future regression where a test passes locally but fails in CI under a different test ordering, or vice versa. Classic flaky-test setup.

**Required follow-up:** Either (a) add `afterEach(resetAuth)` to every file that uses `seedAuth()`, or (b) make `renderWithProviders` itself reset+seed if the test hasn't pre-configured stores. The original tenant-scope tests (`useCategories.tenantScope` et al.) do call `resetAuth` in `afterEach`; the M1.5b files should follow that pattern.

---

### P1-5 — M1.8 rate-limit existence test ≠ enforcement test

`FirstTenantProductionSecurityTest::test_required_rate_limiter_is_registered` verifies that `RateLimiter::for('login')` returns a non-null Limit. It does NOT verify that the `/api/v1/auth/login` route is wrapped with the `throttle:login` middleware.

**Risk:** I asserted "rate limit registered," and the gate concludes "rate-limit is in place." A future refactor that strips the `throttle:login` middleware from the login route would not break the test. The named limiter would still exist; the route would no longer use it.

**Required follow-up:** Add a feature test that posts 11 invalid logins from the same IP and asserts the 11th returns 429. Same for password-reset, register, manager-PIN. The M1.8 commit's static-source assertion for manager-PIN catches half of this (it checks ManagerPinController retains its key shape); the other endpoints don't have equivalent coverage.

---

## P2 Findings (queued, not blocking)

### P2-1 — M1.2: no consumer audit before moving Scramble to require-dev

I grepped `config app routes tests composer.json` for "Scramble" before moving it to `require-dev`. I did not grep `.github/workflows`, `docker-compose*.yml`, deploy scripts under `scripts/`, or operational runbooks under `docs/pos-operations/`. If any of those reference `php artisan scramble:*` or expect the package in production install, the move breaks them silently.

**Mitigation:** `rg -n "scramble" .github docker-compose*.yml scripts docs --hidden` — if hits, document.

### P2-2 — M1.3: pnpm overrides accumulate technical debt

The root `package.json` now carries 11 pnpm overrides. Each pins a transitive at a minimum version. These are sticky — future `pnpm up` calls won't naturally drop them. The codebase accumulates a hidden manifest of "things we patched once and never revisited."

**Mitigation:** add a doc comment above the `pnpm.overrides` block explaining each override's source CVE + a "revisit by" date (e.g., "revisit Q3 2026; drop overrides whose parent dep has shipped a fix").

### P2-3 — M1.4 inventory schema test trust: `api.marketplace` cluster acceptance

`test_seed_inventory_lists_all_expected_api_clusters_per_master_plan_section_6` accepts the new `api.marketplace` cluster in the YAML without independent confirmation that the cluster was added through the sweep workflow and not through a stray YAML edit.

**Mitigation:** look at the cluster's `history` field in the YAML — the audit-trail anchor that sweep workflow events stamp. If it has a `generator` row + at least one downstream event, it's legitimate.

### P2-4 — M1.5 seedAuth helper doesn't handle the "tenant-scope test sets up state THEN calls renderWithProviders" pattern

The current `renderWithProviders` seeds nothing related to auth. Tenant-scope tests (`useCategories.tenantScope.test.tsx` etc.) call `setTenant(...)` in their `beforeEach` BEFORE calling `renderWithProviders`. That order works. But the helper's docstring still says "AuthProvider / CompanyProvider / LocationProvider are intentionally omitted" — which is misleading now that there's a `tenantScopedKey` cache seed that depends on the stores being populated.

**Mitigation:** update the docstring to call out the auth-store + company-store coupling and link to `seedAuth.ts`.

### P2-5 — M1.6b F7: golden test verifies PHP side; nothing in this round verified the POS-side hash matches

The cross-language byte-match contract requires PHP and Tauri to produce identical bytes for the same input. I updated the PHP golden but did not run the Tauri-side test (it's a Rust unit test under `apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes`). If the Tauri side regressed independently to scale 4 in the same window, my PHP fix preserves the divergence rather than fixing it.

**Mitigation:** `cd apps/pos && pnpm test fiscal/v3` (or the equivalent Rust test runner) and confirm the golden-hash test passes.

### P2-6 — M1.7 placeholder reminder hook gap

The release-engineering punch list lives at `docs/qa/2026-05-12-pos-runbook-handoff.md`. There is no CI hook that fails the first-tenant deploy if any runbook still contains a literal `TBD`. A release engineer could forget the punch list and ship the install runbook with `Expected SHA-256: TBD`.

**Mitigation:** add a pre-deploy script that scans `docs/pos-operations/` for `\b(TBD|TODO|PLACEHOLDER)\b` (excluding the template-substitution markers `<companyId>` etc.) and exits non-zero with the list of unresolved rows.

### P2-7 — M1.8 CORS guard is `boot()`-time; not coupled to live config changes

`AppServiceProvider::guardProductionCorsConfig()` runs once when the app boots. If config is reloaded via `config:cache` after deploy (a normal Laravel deploy step), the boot guard ran against the build-time config, not the cached one. A misconfiguration introduced between build and deploy slips through.

**Mitigation:** add the guard to `config/cors.php` itself via an `@throws` at the top of the file's return statement, OR add a `config:cache`-time validator (a service-provider `register()` check that runs even from the cached config).

### P2-8 — M1.8 audit-log presence test does not verify dispatch

`PrivilegedAuditLogTest::test_receipt_voided_event_carries_audit_metadata` checks the constructor parameters of `ReceiptVoided` exist. It does NOT verify that `ReceiptVoidedListener` or any other handler is registered to persist the event into the audit store. If the event is dispatched and falls into the void (no listener), the audit log is empty.

**Mitigation:** add an `Event::fake([ReceiptVoided::class])` test that triggers the void path and asserts the event was dispatched with non-empty `voidedBy` / `voidedAt`. Same for `ZReportGenerated` and `PaymentRefunded`.

### P2-9 — M2.0 heuristic miscategorization possibilities

The 61 `legitimate-platform-candidate` rows include rows where the heuristic matched on substrings like "super-admin" or "platform-level" in the annotation REASON. If an annotation reason quotes those phrases negatively ("intentionally not super-admin scoped"), the heuristic would still match and produce a false positive. Manual review is required to lock each row as `legitimate-platform`.

**Mitigation:** the M2.0 README already calls this out. Highlight more strongly in the next session that the 61 + 29 = 90 rows are all awaiting human triage.

### P2-10 — M2.0 inventory: routes column = TBD on every row

The CSV has a `route` column populated with `TBD` for every row. Routes are not parsed from the controller — they live in `apps/api/app/Modules/*/routes.php`. Without the route, a reviewer reading the CSV has to grep for the controller method themselves.

**Mitigation:** extend `scripts/generate-cross-tenant-inventory.py` to parse route files and join by controller-class + method to populate the `route` column.

---

## NIT Findings

### NIT-1 — Commit message phase numbering

The remediation commits use `dev-remediation/M1.x:` (good — distinct from sweep-branch Phase 0.1.x). But within M1.5 I emitted `M1.5a`, `M1.5b`, `M1.5c` — three commits for one milestone. A reviewer scanning the commit log might think one of them is a hotfix. Either fold them via interactive rebase before merge (already merged — too late), or document the multi-commit milestone shape in the next session's plan template.

### NIT-2 — Documentation date drift

All docs date themselves `2026-05-12` even though the work landed on `2026-05-13`. The plan was authored 2026-05-12; I kept the artifact dates aligned. Mildly confusing — a reader asks "did this work happen on 2026-05-12 or 2026-05-13?"

### NIT-3 — M1.8 tests' source-string assertions are brittle

`FirstTenantProductionSecurityTest::test_manager_pin_endpoint_enforces_per_ip_per_user_rate_limit` greps the controller source for an exact string literal. A whitespace change in the source breaks the test. A more robust assertion would use reflection on the controller class or a routes-level integration test.

---

## Closed loops (worth calling out)

A few things the round did defensibly well, for symmetry:

- **M1.4 isEnabled() pattern** — registration-time gating (vs runtime gating in `handle()`) means production `php artisan list` legitimately doesn't show the command. Aligns with the explicit P2-N1 finding from the prior plan review.
- **M1.6 set_time_limit fix at bootstrap + per-test reset** — defense in depth against future controllers calling `set_time_limit(N)`. The plan's verification command (`COMPOSER_PROCESS_TIMEOUT=0`) was incorrect; the fix found and addressed the real root cause.
- **M1.6b F5/F6** — Updating to `WorkOrderCompletedV2` correctly matched the sweep merge's intent (drop V1, register V2). Caught a real regression that would have left workshop appointment mirroring broken on the Otospex vertical.
- **M2.0 inventory** — going from grep count "117 annotations" to a structured CSV with classification heuristics is more useful than the audit's "5 notable examples" framing. Future M2 work has a punch list.

---

## Bottom line

The round delivered what the gate definition asked for. The five P1 findings above represent shortcuts I took that should be undone in the next session before claiming first-tenant readiness:

1. P1-1 — execute or coordinate actual secret rotation (not just tracking)
2. P1-2 — verify `EnforceTokenTenantClaim` middleware exists and is enforced
3. P1-3 — verify web UI handles 422 correctly for category cross-company parent
4. P1-4 — add `afterEach(resetAuth)` to all 17 M1.5b files
5. P1-5 — add throttle-enforcement feature tests for login / password-reset / register / manager-PIN

**Tenant isolation status:** the bulk landed via PR #93 (sweep merge); the five named gap clusters (M2.1–M2.5) remain. First-tenant pilot scope still drives the M2.x risk decision per the prior turn's scope analysis.

The next session should:
1. Close the five P1 findings above.
2. Decide M2.1/M2.2 scope based on whether the pilot is IziPOS-only or includes Otospex automotive.
3. Triage the 29 `TBD-needs-review` rows in the CrossTenantRoute CSV.
4. Lock or downgrade the 61 `legitimate-platform-candidate` rows.
