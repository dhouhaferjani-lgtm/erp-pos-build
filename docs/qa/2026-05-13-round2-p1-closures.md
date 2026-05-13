# Round 2 P1 Closures — 2026-05-13

> Companion ledger to `docs/superpowers/reviews/2026-05-13-dev-remediation-opus-adversarial-review.md`.
> Records what was done (or audited and found already-closed) for each P1 finding from the prior round.

Each entry names the prior-review finding, the action taken in this round, the commit that closes it, and any residual follow-up.

---

## P1-1 — Secret rotation: replace tracking-only with provider-rotation playbook

**Status:** engineering-side closed. Provider-side revocation remains outstanding and is owned by the release owner.

**Action:** Rewrote `docs/security/secret-rotation-2026-05-12.md` (commit `dev-remediation/A.1`) to:

- Lock disposition to **Option B — Revoke at provider** with rationale (Option A's `git filter-repo` cannot retroactively remove bytes from collaborator clones; Option B revokes the values regardless of who still holds the history).
- Acknowledge the scope limit (no production credentials are rotatable from a developer terminal).
- Provide eight per-credential playbooks (APP_KEY, DB_PASSWORD, REDIS_PASSWORD, MAIL_PASSWORD, AWS keys, MEILISEARCH_KEY, Reverb trio, Sentry DSN) with exact commands + verification + side-effects.
- Add an evidence ledger and final sign-off section.

**Residual:** the release owner must execute the playbooks against production and fill the evidence ledger. The first-tenant gate cannot close until each "Critical" row reads `revoked`.

---

## P1-2 — EnforceTokenTenantClaim middleware verification

**Status:** closed.

**Action:** Inspected the middleware at `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php`. It is real (not a stub), implements all five documented branches (matching pass, mismatch 401, grandfathered pass, super-admin marker pass, session-cookie pass), and is registered on every protected route group across the api routes (~20 callsites). Existing test `tests/Feature/Identity/EnforceTokenTenantClaimTest.php` covers all five branches. Commit `dev-remediation/A.2` added an assertion on the mismatch branch that locks the wire contract (`error.code === 'TOKEN_TENANT_MISMATCH'` + message) so a refactor that drops the error code without changing the status code is caught.

**Note on the prior review's framing:** the review asked the middleware to compare the token claim against "the request's resolved tenant from CompanyContext." The implemented invariant compares against `User->tenant_id` (the authenticated user's home tenant). That is the correct layer-D invariant per the master-plan §15 / api.auth-permissions cluster documentation embedded in the middleware. Request-tenant scoping is a separate invariant enforced by CompanyContext middleware downstream and is out of scope for this middleware.

**Residual:** none.

---

## P1-3 — Category 404→422 web UI consumer audit

**Status:** closed.

**Action:** Audited `apps/web/src/features/categories/**` and the global API client at `apps/web/src/lib/api.ts`. Findings:

- The categories form (`apps/web/src/features/categories/CategoriesPage.tsx:67-89`) catches mutation errors with a generic `catch (error: unknown)` block, extracts `error.message`, and displays `t('inventory:categories.messages.createFailed', { error })`. There is no status-code switch.
- The categories API client (`apps/web/src/features/categories/api/categoriesApi.ts`) does not branch on status either.
- The global axios interceptor at `apps/web/src/lib/api.ts:135-182` only special-cases 401 (redirect), 403 (log), 419 (CSRF retry), and 500+ (log server error). 404 and 422 are both passed through to the mutation's onError handler.
- Both error shapes (`{error: {code, message}}` for 422 and `{error: {code, message}}` for 404) are well-formed; the user sees a toast with the backend's message either way. The 422 path now carries the validation error message which is actually more informative than the prior 404 "Not Found" payload.

**Backend check:** `apps/api/tests/Feature/Product/CategoryTest.php:352-370` (test_parent_must_belong_to_same_company) asserts 422 with an explanatory comment.

**Residual:** none. No code change required.

---

## P1-4 — seedAuth test isolation (afterEach resetAuth) — in progress

Open at the time of writing. See `dev-remediation/A.4` once committed.

---

## P1-5 — Rate-limit enforcement feature tests — in progress

Open at the time of writing. See `dev-remediation/A.5` once committed.

---

## Conventions

- This file is append-only within Round 2. If a P1 needs to re-open after closure, add a new section below with the re-open reason and date.
- Each closure entry must name: the action, the commit that lands it, and any residual follow-up.
