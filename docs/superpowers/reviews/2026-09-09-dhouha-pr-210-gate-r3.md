# Gate r3 — PR #210 "fix(authz): gate supplier-invoice posting behind a dedicated permission (F-W2-14)" — after fix rounds 1+2

- **Gate verdict: MERGE-WITH-FIXES** (spec ✅ / quality APPROVED-WITH-FOLLOW-UPS)
- **Reviewed**: worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-210`, branch `gate/pr-210`, tip `4d7dccbd3`. Merge-base with local `dev` = `fa000edc39c5e2c060748db534ff0a22ed1e31a8`; diff vs base = 36 files, +2158/−59. Round-2 delta (`b5421aef5..4d7dccbd3`) = 13 files, +530/−21.
- **Inputs**: gate r1 `docs/superpowers/reviews/2026-09-05-dhouha-pr-210-gate-r1.md`, gate r2 `docs/superpowers/reviews/2026-09-07-dhouha-pr-210-gate-r2.md`, handback `.worktrees/pr-210/docs/superpowers/reviews/2026-09-07-dhouha-pr-210-fix-round-1-handback.md` (Fix round 2 section @282-384).
- **Scope**: targeted — close-out of the six r2 findings + regression sweep on what round 2 introduced. Not a re-review of the r2-verified substance (route middleware, authorizer arms, policy placement, seeder catalogue), which I spot-re-confirmed rather than re-derived.
- **Reviewer**: tenancy-authz-reviewer. Gate only. Nothing merged, nothing committed. A throwaway base worktree was created in the scratchpad, used for the pre-existing-failure control, and removed (`git worktree list` back to 9 entries). `git status --porcelain` in `.worktrees/pr-210` is **empty**.

---

## Summary

**All six r2 findings are closed at the tip, and I verified each one in the code rather than from the handback.** The MAJOR one — the FE `purchases` role alias sitting in front of the new permission gate — is genuinely gone: the three supplier-invoice routes now carry the same permission their API twin checks, with no `moduleKey`, and the fix is pinned twice (a route-guard test that mounts the real `RequirePermission` with the real hook and store, plus a static assertion against the generated route manifest). Both generators are byte-identical to the committed artefacts on my own re-run, deptrac clears against `dev`'s reconciled baseline on my own measurement, PHPStan/Pint/tsc are clean, and the branch merges onto current local `dev` (`523123de3`) with no conflicts and no overlapping files.

Round 2 introduced **one new defect of its own**, all-MINOR class: to open the Purchases *nav group* for the accountant, it widened the **shared** `MODULE_PERMISSIONS.purchases` key, which is also the **only** guard on seven unrelated purchases routes. An accountant who deep-links `/purchases/orders` now passes the FE gate and lands on a page whose API 403s, where before they were redirected. Two behaviour/deploy notes are also missing from the promotion row. None of it is a security regression — every widened surface still fails closed at the API.

---

## Part 1 — the six r2 findings, re-verified at the tip

### r2 finding 1 [MAJOR] — FE module role-alias in front of the permission gate → **CLOSED**

- `apps/web/src/routes/index.tsx:1049` list → `<RequirePermission permission="documents.view">`; `:1063` create → `permission="supplier-invoices.manage"`; `:1073` detail → `permission="documents.view"`. No `moduleKey` on any of the three.
- Backend twins confirmed in `apps/api/app/Modules/Procurement/Presentation/routes.php`: `:84` `can:documents.view` (index), `:94` `can:documents.view` (show), `:104` `can:supplier-invoices.manage` (store), `:110` match, `:122` post. Group middleware at `:76-81` is `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` — rule 12 satisfied.
- Rule-12 both-layer question re-checked: there is **no** `Purchases` module in `apps/api/config/verticals.php` (grep returns only `PurchaseBonus`, an unrelated extra), and the Procurement group carries no `module:` middleware — so dropping the FE `moduleKey` removes a UI alias with no backend twin, not a gating layer. Correct.
- `RequirePermission` still evaluates `moduleKey` first (`apps/web/src/features/auth/components/RequirePermission.tsx:51-53`) then `permission` (`:56-58`) — the short-circuit that caused the finding is unchanged, which is why removing the key (rather than reordering) was the right fix.
- **Nav half.** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:186-195` gates `quoteRequests`/`purchaseOrders`/`goodsReceipts` on `nav.purchaseOrders`/`nav.purchaseQuoteRequests` and `supplierInvoices` on `nav.supplierInvoices`; those keys are defined at `apps/web/src/hooks/usePermissions.ts:149-151` as unions of the real backend permission with the legacy alias (`nav.supplierInvoices` deliberately alias-free).
- **Tests are real.** `apps/web/src/features/purchases/supplier-invoices/supplierInvoiceRouteGuards.test.tsx` mounts the actual `RequirePermission` with the actual `usePermissions` + `useAuthStore` (`:46-58`), covers **deny** paths — default operator denied create `:104-107`, cashier denied create `:109-112`, signed-out denied read `:87-89` — and pins the wiring against the generated manifest `:114-135`. `Sidebar.test.tsx:856-903` adds accountant-sees / accountant-does-not-see / cashier-shut-out. The r2-flagged page-level test is retitled to say it covers the page body only (`SupplierInvoiceListPage.test.tsx:337-342`).
- **Manifest matches**: `scripts/factory/manifests/routes-web.yaml:702-713` — all three `module_gate: null`, permissions `documents.view` / `documents.view` / `supplier-invoices.manage`.

### r2 finding 2 [MINOR] — `PartnerType::Both` arm unpinned → **CLOSED, and the test is load-bearing**

`apps/api/tests/Feature/Treasury/SupplierPaymentPermissionTest.php:222-253`: cashier + `PartnerType::Both` partner + no document → `assertCreated()`, then `assertDatabaseHas('repository_movements', direction 'in')` **and** `assertDatabaseMissing(... 'out')` (`:245-252`). That direction pair is what makes the test guard the coupling (`SupplierPaymentAuthorizer.php:33-37` excludes `Both` only because direction is derived from a `SupplierInvoice` allocation, not from the partner) rather than merely restating the current status code.

### r2 finding 3 [MINOR] — cashier over-deny on inbound money from a `supplier` partner → **DOCUMENTED** (behaviour unchanged, as intended)

`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3, the F-W2-14 row, carries the announce line ("a till taking a document-less payment from a partner typed `supplier` … now needs `payments.pay-supplier`; or type the partner `both`"). See new finding N3 for the piece of this behaviour change the row still misses.

### r2 finding 4 [MINOR] — idempotency replay skipped the gate → **CLOSED**

- `store()`: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:415-425` re-takes `assertMayPay(...)` against the **persisted** payment's `partner_id` and `allocatedDocumentIdsOf([$existingPayment])` before `return response()->json(...)` at `:428-430`.
- `storeMultiple()`: `:1514-1520`, same shape over the whole batch.
- Helper `allocatedDocumentIdsOf()` at `:96-106`; both finders eager-load `allocations.document` (`:216`, `:238`), so the relation is populated — I checked, this is not an N+1-or-empty trap.
- The document set is faithful: `storeMultiple()` writes a `PaymentAllocation` for the primary document (`:1782-1787`) and for each excess allocation (`:1964-1967`), so the replay sees the same documents the create path gated on.
- Deny path tested: `SupplierPaymentPermissionTest::test_cashier_cannot_replay_a_managers_supplier_payment_idempotency_key` (manager 201 → cashier replay 403 → manager replay 200 → exactly one row).

### r2 finding 5 [MINOR] — unguarded uuid bind on the attachment path → **CLOSED for `{document}`**

`apps/api/app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php:193-217`: `if (! Str::isUuid($documentId)) { abort(404, ...); }` at `:201-203`, **before** the query at `:207-211`; `use Illuminate\Support\Str;` at `:22`. All four entry points route through `resolveDocument()` (`:46`, `:68`, `:116`, `:137`). Test `DocumentAttachmentApiContractTest::test_malformed_document_id_is_a_404_not_a_database_error` (`:462-484`) covers GET + POST. Caveat: that test runs on sqlite, which never 500'd — the guard is verified structurally, the PG 500 it prevents is still inferred, not executed. See new finding N4 for the sibling param that is still unguarded.

### r2 finding 6 [MINOR / OWNER CONFIRM] — accountant denied PO revert → **STATED, still owed an owner line**

`apps/api/app/Policies/DocumentPolicy.php:155-166` — company isolation first (`:158`), then `DocumentType::PurchaseOrder => $user->can('purchase-orders.confirm')`, `default => $user->can('documents.update')`. Deny matrix pinned at `apps/api/tests/Feature/Permissions/P2pEntryPointPermissionsTest.php:87-97` (viewer, cashier, operator, **accountant** all denied); grantability pinned by `DocumentRevertEndpointTest::test_directly_granted_purchase_order_confirm_permission_allows_revert`. The deviation is written up in the handback table (`:333-339`) with the exact one-line change if the owner wants it wider. **This is an owner decision, not a code defect.**

---

## Part 2 — new findings introduced by fix round 2

### N1 [MINOR] Widening the SHARED `purchases` module key also unlocks seven unrelated routes whose only guard it is

`apps/web/src/hooks/usePermissions.ts:68` — `purchases: ['purchases.view', 'supplier-invoices.manage']`, and `canAccessModule` is `hasAnyPermission` (`:218-226`). That key is not nav-only: it is the **sole** guard on seven routes in `apps/web/src/routes/index.tsx` —

`:896` `/purchases/quote-requests`, `:906` `/purchases/quote-requests/new`, `:916` `/purchases/quote-requests/groups/:groupId`, `:926` `/purchases/quote-requests/:id`, `:938` `/purchases/orders`, `:958` `/purchases/orders/:id`, `:982` `/purchases/receipts` — all `<RequirePermission moduleKey="purchases">` with no `permission` (confirmed `permission: null` in `scripts/factory/manifests/routes-web.yaml`).

The seeded `accountant` now holds `supplier-invoices.manage` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:829`) but **not** `purchase-orders.view` nor `purchase-quote-requests.view` (`apps/web/src/hooks/permissionsMap.generated.ts:222`, `:227` → `['admin','manager','operator','viewer']`). So an accountant (or anyone granted `supplier-invoices.manage` per the owner ruling) who deep-links `/purchases/orders` now **passes the FE gate and lands on a page whose API returns 403**, where before round 2 they were cleanly redirected to `/dashboard`. `/purchases/quote-requests/new` is a create page in the same set.

Not a security regression — the backend still refuses, no capability is gained — but it is exactly the "reachable route that silently 403s" class, and it is collateral the fix did not need. **Fix (small):** leave `MODULE_PERMISSIONS.purchases` (`:68`) alone and give the Sidebar group its own nav key, e.g. `'nav.purchasesGroup': ['purchases.view','supplier-invoices.manage']`, then `Sidebar.tsx:176` `permission: 'nav.purchasesGroup'`. The child keys added in round 2 already do the rest. No test covers this today; the Sidebar tests only assert nav visibility, not route reachability.

### N2 [MINOR] Stale-tenant window: manager/admin lose the "Supplier invoices" nav entry entirely until the seeder re-runs — and the handback's compensating claim is not true

`nav.supplierInvoices: ['supplier-invoices.manage']` (`usePermissions.ts:151`) has no alias arm, and `supplier-invoices.manage` is in `SERVER_AUTHORITATIVE_PERMISSIONS` (`:34-43`, the entry at `:42`), which returns **false** for a permission the server did not send (`:181-187`). On any already-provisioned tenant where `RolesAndPermissionsSeeder` has not re-run, that permission does not exist, so **manager and admin — who saw the entry before this PR via the `purchases.view` alias — see no Supplier invoices link at all**. The read route still works by URL (`documents.view`), so the page is only *undiscoverable*, not broken, and it fails closed. But the promotion row (`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3, F-W2-14 bullet, last sub-bullet) describes the fail-closed behaviour as "hides the controls instead of showing a button that 403s" — it does not say the whole nav entry disappears for admins until the seeder runs. **Fix:** one clause in that sub-bullet.

Related, and worth correcting for the record: the handback states (`2026-09-07-dhouha-pr-210-fix-round-1-handback.md:302`) that "manager, admin and the alias-only `purchases` role keep every entry (pinned)". For `supplierInvoices` that is false by construction — a role holding only the `purchases` alias has no `supplier-invoices.manage`, so it loses that entry — and no test pins it (`Sidebar.test.tsx:875-889` pins a manager whose session carries the *server* permission, not an alias-only role). No seeded role is affected (`RolesAndPermissionsSeeder.php:585,690,726,766,791,825` = manager/cashier/viewer/technician/operator/accountant, plus admin at `:582`; **there is no `purchases` role in the tenant seeder** — `purchases` exists only as a UI alias role name in `apps/web/src/hooks/uiAliasPermissions.ts:6`), so real-world impact is limited to tenant-authored custom roles named `purchases`.

### N3 [MINOR] A second undocumented behaviour change: a cashier can no longer take a supplier CREDIT-NOTE settlement (money IN)

`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:134-142` — `isSupplierSettlement()` returns true for `SupplierInvoice`, **`SupplierCreditNote`**, `PurchaseOrder`, `PurchaseQuoteRequest`. The docblock (`:118-132`) says the credit note is included deliberately ("the supplier refunds us … the counterparty is a supplier"). Consequence: a cashier allocating a payment to a supplier credit note — money coming **IN** at the till — now needs `payments.pay-supplier` and gets 403. That is the same over-deny class as r2 finding 3, but the promotion row's announce line only covers the *document-less payment to a `supplier`-typed partner* case. **Fix:** extend that line to name supplier credit notes. No test covers the credit-note arm either way.

### N4 [MINOR, pre-existing sibling of the fixed r2 finding 5] `{attachment}` is still bound into a uuid column with no guard

`apps/api/app/Modules/Media/routes.php:34` and `:38` declare `attachments/{attachment}/download` and `DELETE attachments/{attachment}` with no `whereUuid`. `DocumentAttachmentController.php:122` and `:143` pass the raw string to `MediaService`, which does `MediaAttachment::query()->where('id', $attachmentId)` (`apps/api/app/Modules/Media/Application/Services/MediaService.php:194-199`); `media_attachments.id` is `uuid` (`apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:15`). A malformed attachment id therefore still 500s on PostgreSQL while passing under sqlite. Not introduced by this PR and not on the authorization path — but it is the same defect the PR just fixed one parameter over, on the same routes file. **Fix:** `->whereUuid('attachment')` on both routes, or an `Str::isUuid()` guard in the controller.

### N5 [INFO] Replay authorization edge, negligible

The replay arms derive documents from `PaymentAllocation` rows only. A `storeMultiple` batch whose primary allocation amount rounds to zero (`PaymentController.php:1781`, the `bccomp(...) > 0` guard) leaves no allocation row, so such a replay falls back to arm 2 (partner type) — for a `Both`-typed partner it would then be allowed. The replay is read-only, creates nothing, and `cashier` holds `payments.view` anyway. Recorded, not actionable.

### N6 [INFO] `docs/glossary.md` has no "supplier invoice" row

Rule 22 one-surface-per-concept: this PR introduces no new noun, table, unique key or write path (permission names only), so second-of-everything is **N/A** as r2 concluded. But `grep -in "supplier invoice" docs/glossary.md` returns nothing, i.e. the pre-existing noun is not in the glossary. Pre-existing debt, out of this lane's scope; flagged so it lands on the glossary backlog rather than being re-discovered.

---

## Part 3 — commands run and result lines

Swap was 15.1G/16.4G at start and ~14.4G/15.4G at the end; every run was single-file or single-directory. **The full PHPUnit suite was never run; `pnpm lint`/`pnpm test` in full were never run.**

**Backend (`.worktrees/pr-210/apps/api`, sqlite, `CACHE_STORE=array ./vendor/bin/phpunit`)**
```
tests/Feature/Treasury/SupplierPaymentPermissionTest.php
  OK (7 tests, 23 assertions)

tests/Feature/Modules/Media/DocumentAttachmentApiContractTest.php + tests/Feature/Permissions/P2pEntryPointPermissionsTest.php
  OK, but there were issues!  Tests: 22, Assertions: 129, PHPUnit Deprecations: 14   (0 failures)

tests/Feature/Document/DocumentRevertEndpointTest.php + tests/Feature/DocumentIngestion/CommitSupplierInvoiceTest.php
  OK (14 tests, 56 assertions)

tests/Feature/Procurement/SupplierInvoiceApiTest.php
  Tests: 66, Assertions: 346, Failures: 3
    1) test_post_invoice_first_delivered_posts_zero_ppv_gl_legs                      (422, expected 200)
    2) test_pending_link_receipts_then_post_consumes_receipt_and_clears_gr_ir        (422, expected 200)
    3) test_post_invoice_first_supplier_invoice_requires_approval_permission_when…   (422, expected 200)
```
**Those 3 are PRE-EXISTING — measured, not assumed.** I built a throwaway worktree at the merge-base `fa000edc3` (vendor APFS-cloned from the main checkout, never symlinked), ran the same three filters there, and got the identical three failures:
```
(base-210/apps/api) phpunit tests/Feature/Procurement/SupplierInvoiceApiTest.php --filter '<the 3>'
  Tests: 3, Assertions: 14, Failures: 3     # same 422s on unmodified dev
```
The scratch worktree has been removed.

**Frontend (`.worktrees/pr-210/apps/web`)**
```
$ ./node_modules/.bin/vitest run src/features/purchases/supplier-invoices
  Test Files 5 passed (5)   Tests 73 passed (73)
$ ./node_modules/.bin/vitest run src/hooks/__tests__/usePermissions.supplierInvoiceGates.test.tsx \
      src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx
  Test Files 2 passed (2)   Tests 57 passed (57)
$ ./node_modules/.bin/tsc --noEmit            exit 0
$ ./node_modules/.bin/eslint src/routes/index.tsx src/hooks/usePermissions.ts \
      src/components/organisms/Sidebar/Sidebar.tsx src/features/purchases/supplier-invoices
  ✖ 65 problems (0 errors, 65 warnings)       # all pre-existing rule classes; the one hit in
                                              # usePermissions.ts:224 is the pre-existing UI-01
                                              # fail-closed guard, untouched by this PR
```
Note: an earlier run with `--pool=forks --poolOptions.forks.singleFork` produced 27 failures. That is an artefact of forcing a non-default pool (shared module state across files in one process), not a PR defect — the repo's configured pool is green. Worth someone's attention as harness fragility, but it is not this lane's.

**Generators — byte-identity re-derived by me, not trusted**
```
$ node scripts/factory/gen-route-manifest.mjs        -> wrote routes-web.yaml (271 routes) + routes-pos.yaml (9)
$ git status --porcelain scripts/factory/manifests/  -> (empty)
$ (apps/api) CACHE_STORE=array php artisan permissions:export-frontend-map -> Exported …
$ git status --porcelain                             -> (empty)          # whole worktree clean
$ node --test scripts/factory/gen-route-manifest.test.mjs   -> pass 10, fail 0
```

**Static analysis / style**
```
$ ./vendor/bin/phpstan analyse app/Modules/Media/Presentation/Controllers/DocumentAttachmentController.php \
    app/Modules/Treasury/Presentation/Controllers/PaymentController.php \
    app/Modules/Treasury/Application/Services/SupplierPaymentAuthorizer.php app/Policies/DocumentPolicy.php \
    --memory-limit=1G --no-progress
  [OK] No errors        (level 8)
$ ./vendor/bin/pint --test <4 touched PHP files>    {"result":"pass"}
```

**Deptrac — measured on this branch, compared to `dev`'s baseline**
```
$ (apps/api) php tools/deptrac-ratchet.php
  computed: ModuleApplication on ModuleInfrastructure 68 · ModuleDomain on ModuleApplication 53 ·
            ModuleInfrastructure on ModulePresentation 1 · SharedContracts on ModuleApplication 18 ·
            SharedContracts on ModuleDomain 37 · SharedDomain on ModuleDomain 4 ·
            SharedInfrastructure on ModuleDomain 2 · TOTAL 183
  RESULT: FAIL   (against the BRANCH's stale baseline copy: 54 / 36)

$ git show dev:apps/api/deptrac.baseline.json   -> 68 / 53 / 1 / 18 / 37 / 4 / 2, total 183
```
Category-for-category identical to `dev`'s reconciled baseline, and the branch does not modify `deptrac.baseline.json` (`git diff --stat <base>..HEAD -- apps/api/deptrac.baseline.json` → empty), so the ratchet turns green the moment the branch sits on `dev`. Round 2 adds no cross-layer import at all (only `Illuminate\Support\Str`), so this is unchanged from r2 — but I re-measured rather than citing it.

**Mergeability onto current local `dev` (`523123de3`)**
```
$ git merge-tree --write-tree --messages gate/pr-210 dev
  7a05c216299abc269980dd8b00f6e6b40d467af1        exit 0, no conflict messages
$ git diff --name-only <base>..dev  ∩  PR-touched files   -> EMPTY
```
`dev` has moved 295 files since the merge-base but touches **none** of this PR's files (including `RolesAndPermissionsSeeder.php`, `routes/index.tsx`, `usePermissions.ts`, `Sidebar.tsx`, `PaymentController.php`, `DocumentPolicy.php`, and both generated artefacts). Clean fast merge; no regeneration needed after merge. Nothing was merged.

---

## What I could NOT verify

- **No browser/Playwright run.** N1 (accountant deep-links `/purchases/orders`) is derived from the route table + the generated permission map, not clicked. Nobody has driven this tree as an accountant, a granted operator or a cashier.
- **PostgreSQL leg not run** in r3. The 3 `SupplierInvoiceApiTest` failures were controlled on **sqlite** against the merge base; r1's PG evidence stands for what it covered. The PG 500 that the `Str::isUuid` guard prevents (and the one N4 still leaves open) remain code-grounded inferences.
- **No deploy.** The promotion row's `SYNC_PERMISSIONS_ON_BOOT` claim rests on `apps/api/docker/entrypoint.sh`, not on a run.
- **Whole `pnpm lint` / full PHPUnit / full vitest not run** (laptop swap constraint + standing rule). A regression outside the files listed above is unmeasured.

---

## Merge recommendation

**MERGE-WITH-FIXES.** Every r2 finding is closed in code with real deny-path tests; the branch merges clean onto `dev`, generators are byte-identical, deptrac clears, static analysis is green, and the three red backend tests are proven pre-existing on the merge base. Land it. Before F-W2-14 is reported closed: fix **N1** (split the nav key off the shared `purchases` module key — the only newly-introduced defect, ~5 lines), add the **N2** and **N3** clauses to the promotion row, and get the owner's line on r2 finding 6 (accountant denied PO revert by default). **N4** should be ticketed, not held for.

## Owner-facing summary

The supplier-invoice screens are now reachable by exactly the people the API authorises: the seeded accountant, and any role or individual user you grant `supplier-invoices.manage` to from Settings → Roles — proven at the route guard, not just at the API. Three things need your attention: (1) the fix opened the Purchases nav group by widening a key that also guards the purchase-orders and quote-request pages, so an accountant who types those URLs now reaches a page that errors instead of being redirected — cosmetic, fails closed, worth a 5-line follow-up; (2) on every tenant where the roles/permissions seeder has not re-run, even admins lose the "Supplier invoices" sidebar link until it does — the deploy step is already in the promotion checklist, this is one more reason not to skip it; (3) a till can no longer settle a supplier credit note without the new supplier-payment permission, which is deliberate but not yet in the release notes. Still owed from last round: your confirmation that accountants should **not** be able to un-confirm a purchase order by default.

---

# r4 delta — after the N1–N4 fix round (`4d7dccbd3..c88d6412f`)

- **Delta verdict: MERGE** (one MINOR nav follow-up recorded below, not a blocker; one of its two halves is a miss of my own r3 pass).
- **Scope**: `git diff 4d7dccbd3..c88d6412f` **only** — 5 files, +150/−18. Commits `8cde803fa` (N1), `90d41dc58` (N2/N3), `c88d6412f` (N4). Worktree `git status --porcelain` **empty** after my runs.

## (a) N1 — shared module key restored, group split onto its own nav key → **CLOSED, verified at the route guard**

- `apps/web/src/hooks/usePermissions.ts:67` is now `purchases: ['purchases.view']` — **byte-identical to the merge-base value** (`git show fa000edc3:apps/web/src/hooks/usePermissions.ts:32` → `purchases: ['purchases.view'],`). The widening is fully reverted, not merely narrowed.
- New nav-only key at `usePermissions.ts:162` — `'nav.purchasesGroup': ['purchases.view', 'supplier-invoices.manage']`, declared inside the same `as const satisfies Record<string, readonly Permission[]>` block, so it is a `ModuleKey` and type-checked at the call site.
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:187` — the group is now `permission: 'nav.purchasesGroup'` (was `'purchases'`).
- **Verified at the route guard, not just the hook** (as asked). `apps/web/src/routes/index.tsx` is **not touched by this delta** (`git diff --name-only` confirms), so the seven routes still read `<RequirePermission moduleKey="purchases">` at `:896`, `:906`, `:916`, `:926`, `:938`, `:958`, `:982`. `RequirePermission.tsx:51-53` resolves that through `canAccessModule` (`usePermissions.ts:218-226` → `hasAnyPermission(MODULE_PERMISSIONS['purchases'])`), which is now `['purchases.view']` only. `purchases.view` is a UI alias for `['admin','purchases','manager']` (`apps/web/src/hooks/uiAliasPermissions.ts:6`) and is absent from `permissionsMap.generated.ts`, so an accountant holding `supplier-invoices.manage` + `documents.view` resolves it **false** and is redirected to `/dashboard` exactly as before the PR. The r3 N1 exposure is gone.
- The three supplier-invoice routes are untouched and still gate on the real permissions (`routes/index.tsx:1049`, `:1063`, `:1073`); the route-manifest generator re-run leaves the tree clean.

## (b) The new test asserts the exact scenario → **YES, adequate**

`apps/web/src/hooks/__tests__/usePermissions.moduleAccess.test.tsx` (existing UI-01 file, +48) gains a `describe` block "Purchases group nav key is split from the shared purchases module key (gate r3 N1)" with three cases: `MODULE_PERMISSIONS.purchases` `toEqual(['purchases.view'])`; `nav.purchasesGroup` `toEqual(['purchases.view','supplier-invoices.manage'])`; and the behavioural one — an accountant seeded with `['documents.view','supplier-invoices.manage']` through the **real** `useAuthStore` + **real** hook gets `canAccessModule('nav.purchasesGroup') === true`, `canAccessModule('nav.supplierInvoices') === true`, **and `canAccessModule('purchases') === false`**. That last assertion is the deny path r3 asked for.
*Coverage caveat (INFO, not a defect):* the chain "those seven routes gate on the shared key" is asserted in a comment, not in code — no test pins `routes/index.tsx`'s `moduleKey="purchases"` for them, so a future edit that gives one of those routes a different key would not fail here. The supplier-invoice routes do have that manifest pin (`supplierInvoiceRouteGuards.test.tsx:114-135`); extending it to the seven would close the loop cheaply.

## (c) Checklist clauses N2/N3 → **accurate, both re-derived**

`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` §3, two new sub-bullets under the F-W2-14 row.
- **N2 clause** correctly states that `nav.supplierInvoices` (`usePermissions.ts:151`) has no alias fallback unlike its `nav.purchaseOrders`/`nav.purchaseQuoteRequests` siblings (`:149-150`), that `supplier-invoices.manage` is server-authoritative (`:34-43`, entry at `:42`; fail-closed branch at `:181-187`), and therefore that **manager and admin lose the whole nav entry** — not just a button — until the seeder + `permission:cache-reset` step above it runs. It also correctly says the route still works by typed URL (`documents.view`). All four claims check out.
- **N3 clause** correctly attributes the supplier-credit-note deny to `DocumentType::isSupplierSettlement()` (`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:134-142`, `SupplierCreditNote` in the `match`), correctly describes it as money-IN being refused, and correctly says it is grantable via `payments.pay-supplier`. No overclaim: it does not assert a test exists (none does).

## (d) N4 ticket → **real lines, all four verified**

`docs/superpowers/tickets/2026-09-09-attachment-route-binding-unguarded-uuid.md`. I opened every citation:
- `apps/api/app/Modules/Media/routes.php:34` / `:38` — `{attachment}` on download/destroy with no `whereUuid`. ✅
- `DocumentAttachmentController.php:122` / `:143` — raw `$attachment` passed to `MediaService`. ✅
- `MediaService.php:150` and `:195` — both are the `->where('id', $attachmentId)` lines. ✅
- `create_media_attachments_table.php:15` — `$table->uuid('id')->primary()`. ✅
- The sibling-fix reference (`resolveDocument()`, `:190-203`) resolves to the guarded helper. ✅
The ticket also correctly notes that the existing sqlite test cannot reproduce the 500 and asks for a PG-lane test — which is the right honesty about what the r2 fix actually proved.

## New in r4 — [MINOR] the Purchases group now shows an accountant two links whose routes redirect them

Consequence of the (correct) N1 restore, plus one case I missed in r3. `Sidebar.tsx:471-472` filters children only by their own `permission`; a child with none is always rendered once the group's gate passes (`:447-459`). Two children carry none:
- `Sidebar.tsx:191` `{ key: 'suppliers', href: '/purchases/suppliers' }` → the route at `routes/index.tsx:854` is `moduleKey="purchases" permission="partners.view"`. The accountant holds `partners.view` (`permissionsMap.generated.ts:153`) but no longer satisfies the narrow `purchases` key → **redirected to `/dashboard`**. This is newly dead as of `8cde803fa`; in the r3 state the widened key made it work. The stale comment at `Sidebar.tsx:189-190` ("`permission="partners.view"` at the route, which every role holding this group already has") is now wrong — it never mentioned the route's `moduleKey`.
- `Sidebar.tsx:200` `{ key: 'returnNotes', href: '/inventory/return-notes' }` → the route at `routes/index.tsx:1300` is `moduleKey="inventory"` = `['inventory.view']`, which the accountant does **not** hold (`permissionsMap.generated.ts:122`). **This one was already dead at the r3 tip and I did not catch it** — it dates from fix round 2, when the group first opened for the accountant.

Fails closed (a redirect, no error, no data), so not a blocker — but it is the mirror image of the class N1 just fixed, and the `Sidebar.test.tsx:856-871` accountant case asserts only that `purchaseOrders`/`quoteRequests`/`goodsReceipts` are hidden, so nothing catches it. **Fix (2 lines + 1 assertion):** `permission: 'purchases'` on the `suppliers` child and `permission: 'inventory.view'` on `returnNotes` (both keys already exist — `usePermissions.ts:67` and the self-mapped `'inventory.view'`), then extend the accountant Sidebar test to assert both are absent.

## r4 commands and result lines

```
$ (apps/web) pnpm vitest run src/hooks src/components/organisms/Sidebar --reporter=dot
  Test Files  18 passed (18)    Tests  150 passed (150)
$ pkill -f 'node (vitest'   ;  ps aux | grep -c '[n]ode (vitest'  ->  0
$ (apps/web) pnpm vitest run src/features/purchases/supplier-invoices/supplierInvoiceRouteGuards.test.tsx --reporter=dot
  Test Files  1 passed (1)      Tests  11 passed (11)        # r3's guard pins still hold
$ (apps/web) pnpm typecheck                                   exit 0
$ (repo)     node scripts/factory/gen-route-manifest.mjs
$ git status --porcelain                                      (empty)   # no manifest drift
```
Backend untouched by this delta (`git diff --name-only 4d7dccbd3..c88d6412f` contains no `apps/api/**`), so no PHP run was repeated; the r3 backend evidence stands unchanged.

## r4 verdict

**MERGE.** N1 is closed at the layer that mattered — the shared module key is back to its merge-base value, the group gate lives on its own nav-only key, and the deny is asserted behaviourally with the real hook and store. N2/N3 checklist clauses are accurate; the N4 ticket's citations are all real. The one new item — two ungated nav children pointing at routes the accountant is redirected from — is a MINOR nav-polish follow-up of the same fail-closed class, one half of which was my own r3 miss; land the PR and fix it in the next touch of `Sidebar.tsx`. Still owed from r2: the owner's line confirming accountants should not un-confirm purchase orders by default.
