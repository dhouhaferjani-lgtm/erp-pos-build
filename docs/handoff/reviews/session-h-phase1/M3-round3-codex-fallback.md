# M3 round-3 adversarial merge-gate — Codex fallback

Range reviewed: `bdc228a18..f45ed7a9b7e47631543761a9d06973fd09142778` (`git diff bdc228a18..HEAD`). Lenses: `tenancy-authz`, `frontend-conventions`. This is the documented Codex fallback after the primary Opus reviewer failed twice and its direct probe reported a session limit.

## Findings and dispositions

1. **Prior P2 — supplier permission composition: CLOSED.** The supplier list now requires both the existing purchases UI alias and canonical partner permission: `<RequirePermission moduleKey="purchases" permission="partners.view">` (`apps/web/src/routes/index.tsx:842-850`). `RequirePermission` evaluates the module leg first and the explicit permission second (`apps/web/src/features/auth/components/RequirePermission.tsx:49-58`), so this is an AND gate. The route test independently denies `partners.view`-only and `purchases.view`-only actors, then proves the both-permission actor opens both list and unchanged purchases-gated detail (`apps/web/src/routes/PartnerRoutes.gates.test.tsx:65-85`). My focused run passed this file 11/11.

2. **Prior P2 — genuine disposable restricted browser login: CLOSED.** The spec creates an email user and exact `contacts.update` role through the real API, removes the cashier bootstrap role, activates the user, authenticates with that user's email/password, and verifies the exact user ID/email and permission set through a real server `/auth/me` response before browser use (`apps/web/e2e/session-h/m3-dead-crm-partner-vehicle-gates.spec.ts:307-398`). The password bootstrap is confined to the E2E spec, refuses any environment other than `local`/`testing` before mutation, initializes the centrally resolved tenant, scopes the lookup by tenant plus exact API-created user ID, changes only the password hash, and always ends tenancy (`:232-253`). No production/test route, auth endpoint, seed, or generated contract was added.

   The browser then fills the normal login form and submits to the real worktree API (`apps/web/e2e/session-h/helpers.ts:156-209`; spec `:216-229,452-498`). There is no auth-specific route handler or fabricated `/auth/me` body in the M3 spec. The established Session-H helper's broad `**/api/v1/**` handler is a transparent cross-port proxy: it uses `route.fetch` against `API_BASE` and fulfills with that returned response (`helpers.ts:160-174`). The in-browser witness performs another live `/auth/me`, checks the same disposable ID/email, requires `contacts.update`, and excludes `partners.update` and `partners.view` (`spec:255-285`). This is not the former owner-token identity projection.

3. **Prior P2 cleanup/revocation requirements: CLOSED.** Teardown removes the temporary role assignment, calls the repository's user-delete API, proves the saved disposable token now returns 401, deletes the temporary role, and fails the suite if any cleanup response is unsuccessful (`spec:400-434`). The user-delete implementation revokes all tokens, makes the user inactive, revokes company memberships, clears POS PIN state, and removes the central identity entry inside its transaction (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:445-488`). Thus no seeded identity is mutated, no active disposable access remains, and the role is removed; the API's intentional inactive audit row is the product's delete semantic, not an uncleaned usable fixture.

4. **No new P1/P2 tenancy or authorization defect found.** The company-code validation realignment uses the bound `CompanyContext` in both create and update (`apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:60-77`; `UpdatePartnerRequest.php:76-95`), matching the database's existing `(company_id, code)` unique constraint (`apps/api/database/migrations/tenant/2025_12_30_195300_fix_multi_company_unique_constraints.php:19-23`). The focused second-company create test passed 1/1 (10 assertions), and the update code matrix passed 3/3 (14 assertions), including same-company rejection, cross-company reuse, and cross-company update denial. Vehicle owner search remains tenant/company-keyed through `PartnerPicker`, every list request adds `type=customer`, and the selected `partner_id` is submitted unchanged (`apps/web/src/components/molecules/pickers/PartnerPicker.tsx:116-151`; `apps/web/src/features/vehicles/VehicleForm.tsx:129-165,218-233`).

5. **Known P3 debt/state: RECORDED, non-blocking.** The authoritative YAML carries the pre-existing vehicle enum drift, partner-code 422-to-201 client-contract realignment, locally unavailable Otospex browser evidence, eight-entry i18n burn-down, and Phase-2 double-context form-state debt (`docs/handoff/progress/session-h-phase1.progress.yaml:80-84`). The implementation evidence also records the RED/GREEN narrative, allowed Otospex skip boundary, browser result and screenshots, consumer coverage, and the same owed-parent items (`docs/handoff/reviews/session-h-phase1/M3-implementation-evidence.md:21-45,49-76,78-84`). The M3 row remaining in `review` with the round-2 verdict is the expected controller-owned pre-round-3 state, not a stale implementation claim.

## Bypasses attempted

1. **Permission short-circuit or OR-composition:** failed; source and independent one-leg tests prove AND-composition.
2. **Owner identity spoof through `/auth/me`:** failed; the M3 spec contains no auth-specific interception, the helper forwards to the real API, and both server-side and in-browser responses assert the exact disposable identity.
3. **Production password/auth backdoor:** failed; the only bootstrap is an inline local/testing-guarded Artisan command in Playwright and no production route was added.
4. **Seed mutation or reusable privilege residue:** failed; only API-created user/role resources are touched, the seeded cashier role is merely assigned then removed from the disposable user, and teardown asserts revocation/cleanup.
5. **Cross-company or cross-tenant code collision/write opening:** failed; validation matches the company DB constraint, controller resolution stays company-scoped, and focused create/update isolation tests passed.
6. **Vehicle picker tenant/filter regression:** failed; focused frontend tests assert no unfiltered `/partners` list request and unchanged selected ID submission. The selected-state label is visible and programmatically named, with localized field-specific clear text.
7. **Residual dead Companies surface or broken preserved routes:** failed; the page/import/nav/locale block are removed, true contact edit remains on `contacts.update`, partner edits use `partners.update`, and legacy `/partners` redirects remain covered.

## Verification performed

- Focused M3 frontend regression: **9 files / 100 tests passed**. Existing `act(...)` and unmatched-route diagnostics were warnings only.
- Partner second-company create: **1 test / 10 assertions passed**.
- Partner update-code matrix: **3 tests / 14 assertions passed**.
- Web TypeScript typecheck: passed.
- ESLint over touched production source: **0 errors** (existing warnings only; E2E files are ignored by the repository ESLint pattern).
- `git diff --check bdc228a18..HEAD`: passed.
- The worktree API/web ports were down during this review, so I did not mutate external state to rerun Playwright. I inspected the committed spec, successful `.last-run.json`, six refreshed screenshots (17:57-17:58), and the falsifiable 4-pass/1-allowed-authentication-skip evidence record at code HEAD `73b9d8ad1`.

No Critical or Important finding remains. The M3 implementation preserves accepted M1/M2 behavior and is ready for the controller's acceptance/state update.

VERDICT: ACCEPT
