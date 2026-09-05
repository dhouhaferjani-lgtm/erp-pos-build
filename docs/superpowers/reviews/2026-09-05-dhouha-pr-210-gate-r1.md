# Gate r1 — PR #210 "fix(authz): gate supplier-invoice posting behind a dedicated permission (F-W2-14)"

- **PR**: https://github.com/…/pull/210 · author `dhouhaferjani-lgtm` · head `fix/cashier-supplier-invoice-perms` · base `dev`
- **Head commits**: `89d77eb31` (initial), `313eb4d0d` (review round 2: ingestion committer + FE gating)
- **Merged tree reviewed**: worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-210`, branch `gate/pr-210`, merge commit `b166fe3f4` (= local `dev` `fa000edc3` + PR head)
- **Reviewer**: tenancy-authz-reviewer (adversarial, code-grounded). Gate only — no merge performed, nothing modified.
- **Diff reviewed**: `git diff dev HEAD` → 8 files, +238/−24.

## VERDICT

**CHANGES (owner ruling required) — do NOT merge as-is.**

The security substance is correct and verified green on both engines: the supplier-invoice mutation surface (`store` / `match` / `post`) plus the ingestion-commit back door now require the dedicated `supplier-invoices.manage`, the permission is seeded and granted to admin/manager/accountant, the generated FE map is byte-identical to the generator output, and the cashier DENY path is asserted on the real routes. Three things block a merge:

1. an **owner ruling** on the `operator` regression (the PR itself asks for it),
2. the PR/ledger framing that this **closes F-W2-14** — it closes one of the finding's three measured halves,
3. a **deploy step that must be executed per tenant DB**, otherwise manager/accountant see the Post button and get a 403 (FE falls back to the role map).

---

## Findings

### 1. [MAJOR] F-W2-14 is only one-third closed — do not mark the finding done
`docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:257` defines F-W2-14 as **three** measured holes: *"`cashier` can create, match and POST a supplier invoice, **revert a confirmed PO**, and **pay a supplier**"*, measured as `W2-PERM-6..11` (`04-part2-evidence-run35.md:90` revert=200; `docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:137` "cashier POSTS a supplier invoice (200) **and PAYS a supplier (201, cash movement out)**").

The PR closes only the first half. Verified still open on the merged tree:
- `apps/api/app/Modules/Document/Presentation/routes.php:84-87` — `POST /documents/{document}/revert` is still `->middleware('can:documents.update')`, and cashier holds `documents.update` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:678`). A cashier can still un-confirm a **purchase order** (`DocumentPostingService.php:530-535` routes `PurchaseOrder` to `revertPurchaseOrder`).
- `apps/api/app/Modules/Treasury/Presentation/routes.php:189-191` — `POST /payments` is `can:payments.create`, and cashier holds `payments.create` (`RolesAndPermissionsSeeder.php:689`). A cashier can still pay a supplier.

**Why it matters**: if this PR lands as "F-W2-14 fixed", two browser-proven P1 holes silently drop off the ledger.
**Fix**: keep F-W2-14 OPEN with two residual sub-items (PO revert, supplier payment) explicitly tracked, or extend this lane to cover them. Re-word the PR title/body to "supplier-invoice mutation surface" and update the wave-2 ledger row accordingly.
**Good news (verified, not a hole)**: `revert` on a supplier invoice is refused — `DocumentPostingService.php:534` `default => throw new \DomainException('DOCUMENT_REVERT_NOT_SUPPORTED')`; and `supplier_invoice` is **not** in the auto-save allow-list (`AutoSaveDraftRequest.php:96-103`), so `POST /documents/auto-save` is not a second door either.

### 2. [MAJOR] Rollout: on any tenant DB not re-seeded, manager/accountant SEE the Post button and get a 403
`supplier-invoices.manage` is a new permission (`RolesAndPermissionsSeeder.php:158`). Under db-per-tenant it does not exist in any already-provisioned tenant DB until the seeder re-runs there.

The FE does **not** fail closed in that window: `apps/web/src/hooks/usePermissions.ts:128-145` — `hasPermission` returns the server-permission answer only when it is TRUE (`:129`), and `supplier-invoices.manage` is not in `SERVER_AUTHORITATIVE_PERMISSIONS` (`:10-18`), so it falls through to the **role map** (`permissionsMap.generated.ts:257` → `['accountant','admin','manager']`). A manager on a stale tenant therefore renders the Post/Re-match buttons (`SupplierInvoiceDetailPage.tsx:92,374,390`) and receives a 403 from `POST /supplier-invoices/{id}/post`.

**Exact deploy steps owed** (verified in code, not from memory):
- **Staging** — covered automatically IF the flag is on: `apps/api/docker/entrypoint.sh:153-161` runs `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` when `SYNC_PERMISSIONS_ON_BOOT="true"`, followed unconditionally by `php artisan permission:cache-reset` (`entrypoint.sh:176`). The staging Dokploy app is documented as `SYNC_PERMISSIONS_ON_BOOT=true` (`docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:35`, verified there on an existing tenant). **Confirm the env var is still set on the API service before promoting** — it is set in **no** in-repo compose file (`apps/api/.env.example:178` ships `false`).
- **Production / local / any env with the flag false** — owed manual step, per environment:
  ```
  php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'
  php artisan permission:cache-reset
  ```
  (`tenants:run` always exits 0 — gate on the printed output, not `$?`.)
- Seeding is idempotent (`Permission::firstOrCreate` `:39`, `Role::firstOrCreate` + `syncPermissions` `:551-552`) — but `syncPermissions` **resets built-in roles to the canonical set**, so any tenant-customised built-in role is reverted. That is the standing product caveat in `entrypoint.sh:150-152`, not new here.

### 3. [MAJOR / OWNER RULING] `operator` silently loses supplier-invoice create/match/post
`operator` holds `documents.update` (`RolesAndPermissionsSeeder.php:780`) but is not granted `supplier-invoices.manage`; the PR pins this with `test_operator_cannot_post_supplier_invoice_under_secure_default` (`SupplierInvoiceApiTest.php:2038-2057`). The PR body explicitly flags this as an owner decision ("OWNER DECISION — please confirm before merge").

Reviewer note that should inform the ruling: the operator's ability was **API-only**. The FE never exposed supplier invoices to operators — every purchases route is `RequirePermission moduleKey="purchases"` (`apps/web/src/routes/index.tsx:1034,1054`), `MODULE_PERMISSIONS.purchases = ['purchases.view']` (`usePermissions.ts:32`), and `purchases.view` is a UI **alias** limited to `['admin','purchases','manager']` (`apps/web/src/hooks/uiAliasPermissions.ts:6`). So the secure default removes a capability no operator could reach through the product UI. I have no authority to rule; **a human must**.

### 4. [MINOR] "Both-layer gating" is only done on the DETAIL page — the CREATE surface is still gated on a role alias
- `apps/web/src/routes/index.tsx:1041-1050` — `supplier-invoices/new` is gated on `permission="purchases.create"`, a UI alias (`uiAliasPermissions.ts:7` → `['admin','purchases','manager']`), not on the backend gate.
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceListPage.tsx:196-201` — the "New supplier invoice" `<Link>` carries **no** permission condition at all.

Consequence of the PR's own change: **accountant** now legitimately holds `supplier-invoices.manage` and can `POST /supplier-invoices` (proven by `test_accountant_can_store_supplier_invoice`, green), yet the FE create route denies accountants. Not a security hole (UI is stricter than the API) and pre-existing, but it contradicts the PR's "both-layer gating (rule 12)" claim.
**Fix**: gate the `supplier-invoices/new` route and the list "New" link on `supplier-invoices.manage`, and drop/keep `purchases.create` as a module alias only.

### 5. [MINOR] Residual `documents.update` write surface on supplier-invoice documents: attachments
`apps/api/app/Modules/Media/routes.php:30-32` (`POST documents/{document}/attachments`, `can:documents.update`) and `:38-40` (`DELETE …/{attachment}`, `can:documents.update`) are type-agnostic, so a cashier can still attach to / delete attachments from a supplier-invoice document. Low blast radius (no GL/stock effect) but it belongs in the F-W2-14 residual list rather than being discovered later.

### 6. [MINOR] Stale published contract doc
`docs/superpowers/coordination/2026-06-26-supplier-invoice-api-contract.md:30,33,36` still documents `POST /supplier-invoices`, `…/match`, `…/post` as `can:documents.update`. The PR did not update it. Update those three lines to `can:supplier-invoices.manage`.

### 7. [MINOR] The permission catalogue test was not extended
`apps/api/tests/Feature/Permissions/P2pEntryPointPermissionsTest.php:18-23` is the existing home for the supplier-invoice entry-point permission matrix (`test_viewer_cashier_and_operator_do_not_get_entry_point_permissions` `:63-71`). `supplier-invoices.manage` was not added to that list; the deny matrix now lives only in `SupplierInvoiceApiTest`. Add the string to `$permissions` — the new deny assertion then comes free for viewer/cashier/operator.

### 8. [MINOR] The PR's verification claim about the 3 pre-existing failures is inaccurate (substance is fine)
PR body: *"the 3 failures are **pre-existing PG-only tests** that fail on the local sqlite runner"*. Measured: those three fail on **PostgreSQL too**, and PG surfaces **two more** (fixture bug: `locations.code` is `varchar(20)` and the test inserts `SI-APPROVAL-MISSING-WH` / `SI-LINK-NOT-PENDING-WH`, 22 chars). I verified the same 5 failures on **unmodified `dev`** (`fa000edc3`) — so the substance ("not caused by this PR") holds, but the wording should be corrected, and the 2 truncation failures deserve their own ticket.

### 9. [INFO] Rule 22 (second-of-everything) — not applicable, stated explicitly
The diff adds no table, no unique key and touches no catalogue entity, so `TenantOnlyUniqueOnCatalogueTablesRatchetTest` is untouched. Permissions are Spatie **team-scoped by tenant, not company** (`apps/api/config/permission.php:99` `'team_foreign_key' => 'tenant_id'`; `SetPermissionsTeam.php:28` `setPermissionsTeamId($user->tenant_id)`), so a second company inside the same tenant resolves the gate identically — a second-company authz test would add no signal. No finding.

### 10. [INFO] Verified correct, for the record
- Route group keeps rule 12: `apps/api/app/Modules/Procurement/Presentation/routes.php:76-81` = `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`. No `module:` gate, per the documented rationale at `:20-23` (`Procurement` is not a `ModuleName`; FE `moduleKey="purchases"` is a permission group, not a vertical module) — consistent with `dev`.
- Gate is **middleware**, not an inline check, on all three mutation routes (`:104`, `:109`, `:122`); the finer `create-pending` / `create-standalone` checks still layer in the controller (`SupplierInvoiceController.php:239-258`).
- Ingestion back door closed: `SupplierInvoiceCommitter.php:50` `Gate::forUser($actor)->authorize('supplier-invoices.manage')` for the receipt-mapped branch; the pending branch keeps `create-pending` (`:43`).
- Permission name matches exactly across layers — seeder `:158`, routes `:104/109/122`, committer `:50`, generated map `permissionsMap.generated.ts:257`. No typo is possible on the FE: `hasPermission` takes the generated `Permission` union and `tsc --noEmit` is clean.
- Grants: manager `:579`, accountant `:813`, admin via `Permission::all()` (`:552`). Cashier/viewer/operator/technician not granted. The permission IS granted to at least one role (no "seeded but ungranted → 403 for everyone" trap).
- No money/quantity/float, no i18n strings, no new Tailwind colors, no TanStack query keys, no UUID-column binds, no `latestOfMany`, no queue/job code. Rules 19/11/18/14 not engaged.

---

## Test runs (verbatim counts)

Private PG DB `autoerp_test_g210` on `127.0.0.1:5433` (created and dropped by this gate). Never ran the full suite.

**Permission-map regeneration (orchestrator's conflict resolution) — VERIFIED CLEAN**
```
$ cd <wt>/apps/api && CACHE_STORE=array php artisan permissions:export-frontend-map
Exported frontend permission map to …/apps/web/src/hooks/permissionsMap.generated.ts.
$ cd <wt> && git status --porcelain
$ git diff --stat -- apps/web/src/hooks/permissionsMap.generated.ts
(empty — committed map == generator output, working tree clean)
```

**sqlite — `./vendor/bin/phpunit`**
```
tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php
  OK (6 tests, 36 assertions)

tests/Feature/Procurement/SupplierInvoiceApiTest.php
  Tests: 65, Assertions: 340, Failures: 3.
  1) test_post_invoice_first_delivered_posts_zero_ppv_gl_legs — 422 != 200 (:930)
  2) test_pending_link_receipts_then_post_consumes_receipt_and_clears_gr_ir — 422 != 200 (:1280)
  3) test_post_invoice_first_supplier_invoice_requires_approval_permission_when_policy_requires_it — 422 != 200 (:1357)

tests/Feature/Permissions/P2pEntryPointPermissionsTest.php + tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
  OK (7 tests, 43 assertions)
```

**PostgreSQL — `php artisan test -c phpunit-pgsql.xml`**
```
tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php
  Tests: 6 passed (36 assertions)   Duration 26.65s
  ✓ receipt mapped commit requires supplier invoices manage permission

tests/Feature/Procurement/SupplierInvoiceApiTest.php   [gate worktree b166fe3f4]
  Tests: 5 failed, 60 passed (333 assertions)   Duration 231.28s
  ✓ store requires supplier invoices manage permission
  ✓ post requires supplier invoices manage permission
  ✓ cashier cannot store supplier invoice
  ✓ cashier cannot match supplier invoice
  ✓ cashier cannot post supplier invoice
  ✓ manager can store and post supplier invoice
  ✓ accountant can store supplier invoice
  ✓ operator cannot post supplier invoice under secure default
  ✓ seeded roles grant supplier invoice manage only to authorized roles
  ⨯ post invoice first delivered posts zero ppv gl legs                 (422 != 200)
  ⨯ link receipts requires pending draft invoice                        (PG varchar(20) truncation on locations.code)
  ⨯ pending link receipts then post consumes receipt and clears gr ir   (422 != 200)
  ⨯ post invoice first supplier invoice requires approval permission …  (422 != 200)
  ⨯ post invoice first missing approval permission record returns dom…  (PG varchar(20) truncation on locations.code)

tests/Feature/Procurement/SupplierInvoiceApiTest.php   [BASELINE, unmodified dev fa000edc3]
  Tests: 5 failed, 53 passed (315 assertions)
  ⨯ SAME five test names as above
  => the PR adds 7 net new passing tests and introduces ZERO new failures.

tests/Feature/Permissions/P2pEntryPointPermissionsTest.php + tests/Feature/Console/ExportFrontendPermissionsMapCommandTest.php
  Tests: 7 passed (43 assertions)
```

**Frontend**
```
$ cd <wt>/apps/web && ./node_modules/.bin/vitest run src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx
  Test Files 1 passed (1)   Tests 23 passed (23)

$ ./node_modules/.bin/vitest run tools/__tests__      # includes permission-map-drift-guard.test.mjs
  Test Files 8 passed (8)   Tests 189 passed (189)
```

**Hygiene**
```
$ ./vendor/bin/pint --test <5 changed PHP files>            -> {"result":"pass"}
$ ./vendor/bin/phpstan analyse <3 changed app/db files>     -> [OK] No errors   (level 8, live PG env)
$ ./node_modules/.bin/tsc --noEmit                          -> clean
$ ./node_modules/.bin/eslint <2 changed TSX + generated map> -> 0 errors, 13 pre-existing warnings (none on touched lines 91-92 / 374 / 390)
```

---

## Deploy steps owed (copy into the promotion checklist)

1. Confirm `SYNC_PERMISSIONS_ON_BOOT=true` is still set on the **staging API service** in Dokploy (repo-invisible; `.env.example:178` ships `false`). If yes, the deploy self-heals (`entrypoint.sh:153-161` + `:176`).
2. For every environment where the flag is false (local dev tenants, production when it lands):
   `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'` then `php artisan permission:cache-reset`. Gate on the printed per-tenant output — `tenants:run`/`tenants:seed` exit 0 regardless.
3. Post-deploy smoke on one pre-existing tenant: a manager posts a draft supplier invoice (expect 200) and a cashier hits `POST /api/v1/supplier-invoices` (expect 403).
4. Note in the release notes that `syncPermissions` resets customised built-in roles.

## What I could not verify

- **Staging/production behaviour** — no deploy was performed; the `SYNC_PERMISSIONS_ON_BOOT` claim for existing tenants rests on `entrypoint.sh:153-161` plus the 2026-09-02 handover evidence, not on a run by me.
- **Browser evidence** — no Playwright/manual UI run; the FE gating claim rests on `SupplierInvoiceDetailPage.tsx:92,374,390`, the Vitest case (which mocks `usePermissions`), and `tsc`. Nobody has clicked through as a cashier/manager on this tree.
- **Whether the owner intends `operator` to keep supplier-invoice posting** — policy, not code.
- **The 3 × 422 invoice-first failures' root cause** — out of scope; confirmed identical on unmodified `dev`, not diagnosed.
- **The full test suite** — deliberately not run (laptop constraint + standing rule). Only the files listed above were executed; a regression outside them is unmeasured.
