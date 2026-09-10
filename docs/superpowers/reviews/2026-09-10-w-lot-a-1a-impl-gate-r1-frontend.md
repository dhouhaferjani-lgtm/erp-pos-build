# Gate r1 — W-LOT-A-1a Task 6 (frontend-conventions-reviewer, 2026-09-10)

**Audited HEAD:** `04e60530c` on `lane/w-lot-a-1a`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`, merge base `4373ba2f6`. Web `node_modules` present in the worktree; nothing installed.

**Guardrails I ran myself (results pasted, nothing accepted on report):**

| Command | Result |
|---|---|
| `pnpm typecheck` | clean, 38.7s |
| `pnpm typecheck:e2e` | clean, 1.2s |
| `pnpm audit:keys` | `Gate C ... : 0` / `0 acknowledged, 0 new, 0 stale` |
| `pnpm audit:design-system` | `802` / `802 acknowledged, **0 new, 0 stale**` |
| `pnpm audit:quantity` | `0 total (0 baselined, 0 new, 0 stale)` |
| `pnpm audit:i18n:local` | `i18n completeness OK — 55 namespaces … 2816 known gap(s) held at the baseline` |
| `pnpm exec eslint <13 touched files>` | `✖ 33 problems (0 errors, 33 warnings)` |
| `vitest src/routes/__tests__/BatchRoutePermissions.test.tsx` | 7 passed |
| `vitest src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | 8 passed |
| `vitest src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | 1 passed |
| `vitest src/features/settings/RolesPage.test.tsx` | 3 passed |
| `vitest src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | 56 passed |
| `vitest src/hooks/__tests__/usePermissions.moduleAccess.test.tsx` | 12 passed |
| **Vitest total** | **87 passed — the handback's count reproduces exactly** |

I did **not** run `pnpm lint` in full (it chains `test:eslint-rules` + `test:tools` on top of a full-repo ESLint pass); I ran ESLint directly on every touched file instead — 0 errors. I did **not** run Playwright: `vm_stat` showed ~67 MB free (4196 free pages × 16 KB); launching Vite + Chromium risks the known swap-exhaustion freeze. The browser evidence is judged from artefacts below, not from a re-run — treat finding B-1 accordingly.

**Baseline honesty / mechanism audit:** `git diff --stat 4373ba2f6..04e60530c -- apps/web/tools/` is **empty**. No baseline was touched, so no `--write-baseline` absorption is possible; the four ratchets ran green against the unmodified baselines. No alias table, no detector-keyword suppression comment, no renamed-equivalent literal in the web diff. Clean.

---

## BLOCKER

None.

---

## MAJOR

### B-1 — The committed pre-activation Playwright spec has never been shown green *as committed*
`apps/web/e2e/batch-permissions.spec.ts:39` (committed) vs the three evidence files
`docs/sessions/wlota1a/browser-first.txt:15`, `browser-next.txt:12`, `ruling-browser-pre.txt:12`.

All three runs report the test at `batch-permissions.spec.ts:85:3` / `:88:3`. At HEAD the test is at `:39:3`; at the earlier commit `2a6ba01ea` it was at `:36:3`. The only inter-commit change to that half was three added imports (`532fe5627`, +3 lines). No committed revision of this file ever placed the test at line 85 or 88 — the verified spec had ~46 more lines above the `describe` than the committed one (the committed fixture block is conspicuously condensed into multi-statement lines). **The green browser evidence therefore belongs to a working-tree revision that was rewritten before commit.**

Compounding it: the run used `--config ../../docs/sessions/wlota1a/playwright.config.ts` (handback:131), a file that `git check-ignore` confirms is excluded by `.gitignore:64`. It sets `baseURL: http://127.0.0.1:5198`, `reuseExistingServer: false`, its own Vite — an honest harness (it genuinely served the worktree bundle, and the `wlota1a-batch-permission-gating-v1` fingerprint assertion at spec:49 is a real anti-wrong-bundle guard), but it is not reproducible from the repo. The committed `apps/web/playwright.config.ts:15,25-29` pins `baseURL: http://localhost:5173` with `reuseExistingServer: !CI`, so the §13 command a reviewer or the orchestrator would actually type can silently reuse **another worktree's** dev server.

*Failure scenario:* the condensed fixture block behaves differently (e.g. a route-fulfil path or a `state` mutation collapsed incorrectly) and the committed spec is red or vacuous; nobody notices because the artefact says "1 passed".
*Minimum correction:* re-run `pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --grep 'pre-activation web gating'` **against HEAD**, attach evidence showing `batch-permissions.spec.ts:39:3`, and commit the harness config (or add an env-driven `baseURL`/`webServer` port to `apps/web/playwright.config.ts`) so §13's command is the command that produced the evidence.

*Also correcting the launch brief:* the handback does **not** claim the post-activation gate passed. `docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:30` states it "was **not run locally**, as required", and :139 says "This run is still owed after actual activation." Commit `532fe5627` ("Complete the post-activation lot isolation browser gate") authored the spec; it did not execute it.

### B-2 — `post-activation isolation` will hard-fail the default `pnpm test:e2e` lane
`apps/web/e2e/batch-permissions.spec.ts:81-88`; `apps/web/playwright.config.ts:5-8`.

`required('WLOTA1A_POST_ACTIVATION')` **throws** when the env var is absent, and there is no `test.skip(...)` guard, no `testMatch` restriction and no `testIgnore` entry. `playwright.config.ts` has `testDir: './e2e'` and ignores only `**/request-hygiene/**` — with the comment "Live-stack evidence harness: registers a tenant and shells out to psql. **Never part of `pnpm test:e2e`**". This new spec shells out to `psql` (`:96`) and registers companies (`:118`) — it is the same category and got no such guard. CI is unaffected (`.github/workflows/smoke-test.yml:53` runs `playwright.smoke.config.ts`, `testDir: ./e2e/smoke`), so this poisons the manual full-e2e lane rather than CI.

*Failure scenario:* the next person to run the repo's e2e lane gets a permanent red that has nothing to do with their change.
*Minimum correction:* follow the repo's own precedent — either add the file to `testIgnore` and give it a dedicated committed config (which also fixes B-1), or open the describe with `test.skip(!process.env.WLOTA1A_POST_ACTIVATION, 'post-activation lane')`.

### B-3 — The browser gate never exercises the create route; the loop tests the detail route twice
`apps/web/e2e/batch-permissions.spec.ts:51`.

```ts
for (const path of [`/inventory/batches/${batch.uuid}`, '/inventory/batches/create', `/inventory/batches/${batch.uuid}/edit`]) {
```
`/inventory/batches/create` **is not a route** — `grep -rn "batches/create" apps/web/src` is empty; the real path is `/inventory/batches/new` (`apps/web/src/routes/index.tsx:1218-1221`). React Router matches `create` against `batches/:uuid` (`routes/index.tsx:1244`), so the iteration silently re-tests the *detail* gate under `batches.view` and passes for the wrong reason. §9.4 lists "direct-route web denial" as what this test proves; the `batches.create` direct-route denial is proven nowhere in the browser lane.

*Minimum correction:* change the literal to `/inventory/batches/new`.

### B-4 — Generated `RoleData.permissions` is `Array<any>` and is spread untyped into component state
`packages/shared/types/generated.d.ts:1035` (`permissions: Array<any>;`); `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php:19` (`public readonly array $permissions` with only a `@param list<string>` docblock, no transformer attribute); consumed at `apps/web/src/features/settings/RolesPage.tsx:159` (`setSelectedPermissions([...role.permissions])`).

I confirm the other reviewers' finding and add the executable evidence: my ESLint run reports a **new** warning at exactly that site — `RolesPage.tsx 159:29 warning Unsafe spread of an 'any' value in an array @typescript-eslint/no-unsafe-assignment` (the other RolesPage warnings at 89/106/132 are pre-existing untyped `api.post`/`api.patch` returns). Rule 3 forbids `any`; §9.2 mandates the generated DTO precisely so the permission list is typed. The transformer emitted `any` because the PHP property carries no TS type — which incidentally *proves* the file was genuinely regenerated rather than hand-edited (property order matches the constructor exactly, and `Array<any>` is what the transformer emits for a bare `array`).

*Minimum correction:* add `#[LiteralTypeScriptType('Array<string>')]` to the `$permissions` parameter (precedent: `apps/api/app/Modules/Product/Application/DTOs/ParapharmacyProductMetadataData.php:60`) and re-run `php artisan typescript:transform`. Do not hand-edit `generated.d.ts`.

### B-5 — Conservation break: every Roles-page card silently changes padding, radius and elevation
`apps/web/src/features/settings/RolesPage.tsx:254-255`.

```tsx
'rounded-lg border p-4 transition-shadow',
tokens.card.base,
```
`tokens.card.base` = `'rounded-[var(--radius-card)] border border-gray-200 bg-white p-6 shadow-[var(--elevation-card)]'` (`apps/web/src/lib/designTokens.ts:1002`). `cn` is `twMerge(clsx(...))` (`apps/web/src/lib/utils.ts:4-6`), so within each conflict group the **later** class wins:

- padding `p-4` → **`p-6`** (16px → 24px on every role card),
- radius `rounded-lg` → **`rounded-[var(--radius-card)]`** (8px at `index.css:143`, but **14px** under the Otospex theme override at `index.css:394`),
- a **new** `shadow-[var(--elevation-card)]` on every card where there was none.

(Background and border colour *are* preserved — `colors.primary[50]` and `borderColors.primary` come after in the `cn` args.) This is exactly the class of change §9.2 forbids: "Existing legacy system-role presentation remains unchanged." It also adds an unrequested elevation accent to a screen the owner's signal-overload ruling governs. It is invisible to typecheck, ESLint and every Vitest assertion, and `audit:design-system` reports 0 new because it is a token→token move.

*Minimum correction:* revert to the literal-preserving form — keep `'rounded-lg border bg-white p-4 transition-shadow'` (or swap only the background for a bg token), and do not pull in the whole `tokens.card.base` composite on a `p-4` card.

### B-6 — `features/batches/types.ts` still declares the wire fields as `number` after the backend flipped them to 4-dp strings
`apps/web/src/features/batches/types.ts:38-39`, `:281`, `:291-295` (doc comment), `:322-324` — against `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:40-41,63-86` (`scopedTotalQuantity()`/`scopedAvailableQuantity()` return `bcadd(...)` strings).

I confirm the finding and add: **the doc comments are now actively false**, not just the types — `types.ts:291-295` asserts "`total_quantity` and `available_quantity` at the batch level are PHP float aggregates … and therefore arrive as JSON numbers, not strings", and `types.ts:270-272` says the same for `available_quantity`. Under the ADOPT ruling those paragraphs must be rewritten, or the next reader will re-introduce numeric arithmetic on them. Full consumer impact in the table below. Note that `BatchResource.php:60` also changed `batch_stock[].available_quantity` from the computed column to `bcsub(...)` — that one is **string→string**, and `types.ts:53` already says `string | number`, so no consumer is affected there.

*Minimum correction:* flip `types.ts:38-39,281,322-324` to `string`, rewrite the `:270-272` and `:291-295` comments to state the 4-dp string contract, then re-run `pnpm typecheck` (it currently passes only because the declared type is a lie).

### B-7 — The web presents `batches.create` / `batches.update` as enforced boundaries the API does not enforce
`apps/web/src/routes/index.tsx:1221` (`permission="batches.create"`), `:1234` (`permission="batches.update"`) against `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:24,26,31,32` — `POST /batches`, `PATCH /batches/{uuid}`, `POST /batches/{uuid}/transfer`, `POST /batches/{uuid}/write-off` carry **no** `can:` and **no** `BatchActionAccess` middleware, only `module:BatchExpiry`.

This is the "gates on BOTH layers" rule (agent rule 12 / `docs/architecture/vertical-module-gating.md`). §14 lists "No Task-1 middleware on create/update/transfer/write-off" as a bullet the **tenancy** reviewer must cite, so I read it as a deliberate slice boundary and I am **not** re-litigating it — but from the frontend lens it must be on the record: after Push 5 the UI hides Create/Edit from a user without `batches.create`/`batches.update`, while any authenticated user with the module can still `POST /api/v1/batches`. That is a UI overstating a system guarantee.

*Minimum correction:* orchestrator/tenancy call — either add `BatchActionAccess::class.':batches.create'` / `':batches.update'` (the middleware's allow-list at `BatchActionAccess.php:19` would need widening; it currently 403s any permission outside the four read/delete/recall names) in this slice, or record the asymmetry explicitly in the A-1b boundary so the FE gate is not mistaken for enforcement.

---

## MINOR

- **`apps/web/src/features/batches/pages/BatchListPage.tsx:23,219`** — the lot quantity renders at **currency** precision: `const { decimals } = useCurrency()` feeding `formatQuantity(totalQuantity, decimals)`. Rule 19 / `precision-contract.md` "Emission & display" requires `units.decimal_places` via `getQuantityDecimals`. The migration `.toFixed(decimals)` → `formatQuantity(...)` is an improvement and is byte-equivalent in output, and `audit:quantity` cannot see it (`available_*` / `total_*` are in the scanner's EXCLUDE set, `tools/audit-quantity-display.mjs:18-20`), so this is a **pre-existing ticket carried onto a touched line**, not a lane defect. Same class, untouched, at `BatchDetailPage.tsx:273,276,279,291,296,301` — `parseFloat(...)` on quantity strings. Both are tickets; neither is introduced by this diff.
- **`apps/web/src/features/settings/RolesPage.tsx:274-277`** — a marker-derived provisioned role renders the badge `t('roles.systemRole')` → "System Role" / "Rôle système" / "دور نظام". `general_manager` is *not* in `SYSTEM_ROLES` (`RolesPage.tsx:29`), so the copy is inaccurate. No new key was added; add `roles.provisionedReadOnly` in `en`/`fr`/`ar` `common.json` when copy is next touched.
- **`apps/web/src/features/settings/RolesPage.test.tsx:339,376-379`** — "prevents update submission when a protected role reaches modal state" passes only because `currentRole` is the **same object reference** that `openEditModal` stored in `editingRole` state; mutating it after the modal opens propagates. In production a refetch yields a new object and `editingRole` keeps the stale snapshot. The guard at `RolesPage.tsx:172` *is* a genuine backstop for the tampered-UI-state case the plan asks for (a forced-open modal holds the protected object), so the assertion is not vacuous — but the *scenario* the title describes is a fixture artefact. Retitle or drive it through a real refetch.
- **`apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:160-161`** — the `state.batch` fixture is an untyped `vi.hoisted` literal missing `id`, which is why the run emits `Each child in a list should have a unique "key" prop. Check the render method of BatchListPage.` (`key={batch.id}` is `undefined`). Convention 11: type it `satisfies Batch` so it cannot drift from the DTO.
- **`apps/web/src/hooks/usePermissions.ts:35-42`** — `serverPermissions?.includes(...)` returns `undefined` when a persisted `autoerp-auth` user object predates the `permissions` field; every batch permission then fail-closes until `/auth/me` refreshes. Same accepted risk as the existing F-W2-14 entries at `:44-52`; noted, not new.
- **`apps/web/src/routes/index.tsx:350,1205,1218,1231,1244`** — `handle={{ featureFingerprint }}` is read by nothing in the app; its sole function is to keep the literal in the served bundle for the browser assertion. Plan-mandated (§9.2), harmless, but worth a one-line comment so it is not deleted as dead config.

---

## §14 frontend citations

| §14 bullet | Verdict | Evidence at HEAD |
|---|---|---|
| Push-5 deployment-ID correlation / incident fallback | **Out of scope** — orchestrator-owned, not faulted | — |
| `RolesPage.tsx` consumes generated `is_provisioned_read_only` | **MET** | `RolesPage.tsx:18` `type Role = App.Modules.Identity.Application.DTOs.RoleData`; the hand-rolled local `interface Role` is deleted (diff `-390,-397`). The field originates at `packages/shared/types/generated.d.ts:1039`, whose eight members and their order match `apps/api/.../DTOs/RoleData.php:16-23` one-for-one — evidence of a real `typescript:transform` run, not a hand edit. No second FE role type (convention 11); the e2e fixture at `e2e/batch-permissions.spec.ts:13` is typed with the same generated symbol. Caveat B-4 on `permissions`. |
| Marker-derived, not name-derived | **MET** | `RolesPage.tsx:20` `const isReadOnlyRole = (role: Role) => role.is_provisioned_read_only` — the only definition; `grep` finds no `=== 'general_manager'` in `apps/web/src`. `SYSTEM_ROLES` (`:29`) still name-derives `super-admin/admin/owner`, which is the pre-existing legacy behaviour §9.2 requires be left alone. Test `RolesPage.test.tsx:367-371` proves an **unmarked** `general_manager` keeps its edit button — that is the anti-name-derivation assertion, and it is production-red-capable. |
| Protected edit/delete affordances absent | **MET** | `RolesPage.tsx:285` gates the whole `<div>` holding both buttons; `:319` hides `roles.clickToEdit`; `:260` neutralises the card `onClick`. Verified against **rendered output** (rule 17), not classes: `RolesPage.test.tsx:364-365` uses `queryByRole('button', { name: 'roles.editRole' \| 'roles.deleteRole' })`, i.e. the accessible name derived from the `title` attribute. Absent, not disabled — consistent with OQ-11. |
| Modal submission has an independent fail-closed backstop | **MET, with MINOR** | `RolesPage.tsx:172` `if (!roleName.trim() \|\| editingRole?.is_provisioned_read_only) return` — independent of `openEditModal`'s guard at `:156`, so a forced-open modal cannot PATCH. Third guard on delete at `:530`. See MINOR on the test's object-aliasing. |
| Generated role type is not hand-authored | **MET** | See row 2. |

## Consumers of the string totals

`BatchResource.php:40-41` now emits `total_quantity` / `available_quantity` as 4-dp **strings** on `GET /batches` (`BatchController.php:158`), `GET /batches/{uuid}` (`:173`), `GET /batches/expiring` (`:290`), `GET /batches/expired` (`:323`), and every mutation response (`:229,:268,:464,:503`).

| file:line | Reads | Runtime under strings | What the ADOPT ruling requires |
|---|---|---|---|
| `apps/web/src/features/batches/types.ts:38-39` | declares `total_quantity: number`, `available_quantity: number` | — | **Change to `string`.** Root of the contract. |
| `apps/web/src/features/batches/types.ts:270-272, 291-295` | doc comments asserting "JSON numbers, not strings" | — | **Rewrite** — currently false and will mislead the next author. |
| `apps/web/src/features/batches/types.ts:322-324` (`ExpiredBatch`) | `total_quantity`/`available_quantity: number` | — | **Change to `string`** — `/batches/expired` serialises through the same `BatchResource`. |
| `apps/web/src/features/batches/types.ts:281` (`ExpiredBatchStock`) | `available_quantity: number` | `BatchResource.php:60` now `bcsub(...)` → string | **Change to `string`.** |
| `apps/web/src/features/batches/pages/BatchListPage.tsx:178, 219` | `batch.available_quantity ?? 0` → `formatQuantity(totalQuantity, decimals)` | **Safe** — `formatQuantity` accepts `string \| number` (`lib/decimal.ts:206-213`); `"2.1234"`→`"2.12"` matches the old `(2.1234).toFixed(2)` | Already migrated. Only the declared type is wrong. Separate MINOR on currency-vs-unit precision. |
| `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:215, 229` | `formatQuantity(batch.total_quantity)` / `(batch.available_quantity)` | **Safe** — default scale 4, identical output | Type flip only. |
| `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:129, 140, 155, 245` | `toQuantityString(batch.available_quantity, QUANTITY_SCALE)` (alias of `formatQuantity`, `:12`) and `bccomp(...)` | **Safe** — string in, string out; `max=` and the over-write-off guard stay correct | Type flip only. This page is the highest-risk consumer and it happens to be already string-safe. |
| `apps/web/src/features/batches/pages/BatchDetailPage.tsx:273, 276, 279, 291, 296, 301` | `parseFloat(level.*)` | **Unaffected** — `stockLevels` comes from `useBatchStock` → `GET /batches/{uuid}/stock` (`BatchController.php:330`), a different serialiser this diff does not touch; `types.ts:60-70` already declares those as strings | Pre-existing `parseFloat` rule-19 ticket, **not a lane defect** — do not charge it to this diff. |
| `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:79-80, 84` | `String(stock.available_quantity)` on `batch.batch_stock[]`, then `bccomp(...)` | **Unaffected** — that field was already a string (computed column) and is still a string (`bcsub`); `types.ts:53` says `string \| number` | No change required. |
| POS (`apps/pos/src/**`) | — | none | `grep -rn "total_quantity\|available_quantity" apps/pos/src` returns **no matches**. No POS consumer exists. |
| `parts-catalog/types/catalog.ts:82-83`, `catalog/api/compositeItemApi.ts:47`, `opening-balances/types/index.ts:235` | same field *names* | **Unaffected** — different endpoints/DTOs | Do not sweep these; they are unrelated surfaces with the same noun. |

## Verified fine

- **Route gating matches §9.2 exactly.** `routes/index.tsx:1208` `batches.view`, `:1221` `batches.create`, `:1234` `batches.update`, `:1247` `batches.view`, `:1261` `batches.write-off` retained — every one still wrapped in `<ModuleGuard module="BatchExpiry">`, so module + permission are both required. `RequirePermission` (`features/auth/components/RequirePermission.tsx:47-63`) evaluates `moduleKey` then `permission` and redirects to `/dashboard` on denial; `BatchRoutePermissions.test.tsx` proves all seven cases including module-disabled denial on all four paths.
- **Exact API/web name parity.** Every FE permission string resolves to the API's own literal: `batches.view` / `batches.delete` / `batches.recall` / `batches.traceability` are the `BatchActionAccess::class.':<name>'` arguments in `apps/api/.../Presentation/routes.php:16,17,22,24,29,36,37,40,43,46`, and `batches.write-off` is the `can:` string at `:20,34`. `BatchActionAccess.php:19` additionally **fail-closes on an unknown permission name** (403 for anything outside its four-name allow-list) — a typo cannot fail open.
- **Generated map provenance is honest.** `permissionsMap.generated.ts:1-3` carries `// This file is generated. Do not edit it by hand.` plus a source hash that moved `a00c4e9d…` → `02b7a451…` in step with the seeder change; `ExportFrontendPermissionsMapCommandTest.php` is in the diff. `BatchSeededPermissionMap.test.ts:9` asserts the nine-row §9.2 matrix verbatim and passes. `technician` is correctly absent from every `batches.*` row (owner ruling: leave it).
- **`SERVER_AUTHORITATIVE_PERMISSIONS` semantics.** `usePermissions.ts:35-42` adds all eight batch names; `:206-210` returns `false` before the role-map fallback is consulted. **Consequence, stated:** for any tenant where the Push-4 delta has not run, `operator`/`viewer` — who previously reached `/inventory/batches` through the old `moduleKey="inventory"` gate — are denied the page and the sidebar entry, and `technician` is denied permanently. The §10 ledger orders **Push 4 (delta apply) before Push 5 (web bundle)**, so on a correctly sequenced fleet there is no regression window; on any tenant provisioned *after* Push 4 without the delta, or any fleet member that skipped it, the Batches page silently disappears for those roles and Expiry Write-Off disappears for anyone whose `/auth/me` omits `batches.write-off`. `usePermissions.moduleAccess.test.tsx:508-512` pins the no-fallback direction with an `admin` role and an empty server list.
- **Sidebar consistent with routes.** `Sidebar.tsx:243` gains `permission: 'batches.view'`, matching `routes/index.tsx:1208`; `:244` already had `batches.write-off`. `MODULE_PERMISSIONS['batches.view']` (`usePermissions.ts:65`) is required because the nav `permission` field is typed `ModuleKey` (`Sidebar.tsx:110,120`) and follows the file's established self-map convention (`:130` `batches.write-off`, `:154` `inventory.view`) — not a concept collision. The parent Inventory group gates on `inventory` (`Sidebar.tsx:233`), which every role holding `batches.view` also has, so no entry is orphaned. Three Sidebar tests cover present/absent/module-off.
- **Rule 18 — tokens only.** No hardcoded Tailwind colour was added anywhere in the diff. `BatchListPage.tsx:94,143` actually *improves* two literals (`text-white` → `${textColors.inverse}`). No interpolated variant prefix or opacity modifier onto a token anywhere in the diff (`hover:${…}` / `${…}/50` — none). `audit:design-system` 0 new, 0 stale.
- **Rule 11 — i18n.** Zero new user-facing strings. Every key the diff renders exists in all three shipped locales: `roles.editRole` / `roles.deleteRole` / `roles.systemRole` at `src/locales/{en,fr,ar}/common.json:{1085,1102,1068}` etc., and `navigation.batches` = `"Batches"` / `"Lots"` / `"الدفعات"`. **No missing key in any locale.**
- **Rule 14 / query keys.** No `useQuery` was added or altered; `audit:keys` reports 0 new, 0 stale. `RolesPage.tsx:39-53` retains its `tenantScopedKey`-shaped `scopedNamespacePredicate`.
- **Convention 11 — glossary.** `docs/glossary.md` adds the **General manager** row with its table (`roles` + `roles.provisioning_source`), its canonical surface (Settings → Users) and its declared synonym ("central manager"), plus a line naming `LotActionPermissionDelta` the **sole** role-definition writer and Settings → Roles read-only. Second-writer question answered in the diff itself.
- **Convention 09 — second-of-everything.** `roles` is not a catalogue table and the diff adds no `unique(['tenant_id', …])`; the migration is `add_provisioning_source_to_roles`. The second-company/second-location dimension is carried by the (unrun) post-activation spec at `e2e/batch-permissions.spec.ts:118-124` (company B, locations B1 + B2) and by the PG lane the other reviewers own. No FE finding.
- **No orphaned or mislinked route.** Every gated route is reachable from the sidebar entry that carries the same permission; no new view is unmounted; no link targets a foreign entity route.
- **No dead controls (OQ-11).** All four affordances are conditionally *absent*, never `disabled`: `BatchListPage.tsx:91,139` (`canCreate &&`), `BatchDetailPage.tsx:77-79`. `BatchPermissions.test.tsx` proves both create links vanish together and that state-based suppression survives when the permission is present.
- No `PageHeader` / raw-form-control / raw-`<table>` / `StickyFormFooter` / picker regressions; no `colorClasses` use; no new hand-rolled FE type beside a generated DTO except the `Array<any>` consequence in B-4.

VERDICT: CHANGES-REQUIRED


## Retractions recorded at gate r2 (orchestrator, 2026-09-10)
- **B-1 RETRACTED**: the `39:3` vs `88:3` discrepancy was a Node 25 vs Node 20 source-map artefact on identical committed bytes (reproduced at r2 with `playwright test --list` under both runtimes); the spec was committed. The committed env-driven harness requested by B-1 was still delivered.
- **B-7 RETRACTED in its premise**: `CreateBatchRequest::authorize()` / `UpdateBatchRequest::authorize()` already enforce `batches.create` / `batches.update` (pre-lane). The FE gates map onto a real API check. Only the *middleware* asymmetry remains (ticket A-1b).
