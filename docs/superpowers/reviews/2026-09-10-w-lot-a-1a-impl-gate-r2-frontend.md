# Gate r2 — W-LOT-A-1a (frontend-conventions-reviewer, 2026-09-10)

**Audited HEAD:** `2fa724c1d` on `lane/w-lot-a-1a`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w-lot-a-1a`. Fix-round diff `04e60530c..2fa724c1d` (10 commits, 37 files; 14 in the web/frontend lens). Whole lane `4373ba2f6..2fa724c1d`. Read-only: no edit, commit, merge, push. Guardrails run by me with Node 20.19.4 unless stated; nothing accepted on report. Playwright **execution** not re-run — laptop at `vm.swapusage used = 9137.38M / 10240.00M`, `PhysMem … 86M unused` (documented swap-exhaustion freeze condition); I substituted a decisive cheap probe (`playwright test --list` under both Node runtimes) instead.

## Verdict
VERDICT: CHANGES-REQUIRED
BLOCKER=1 MAJOR=1 MINOR=6

The one BLOCKER is **not** a regression in the code as written — it is a **push-ordering defect** the r1 register (mine included) missed because both the handback's consumer census and my r1 consumer table evaluated the consumers **at HEAD** instead of the consumers **in the bundle that is deployed while Push 3 is live and Push 5 is not**. Everything r1 actually asked for is closed, and two of my own r1 findings (B-1, B-7) are empirically refuted below.

## Gate r1 closure table (B-1..B-7 + minors)

| r1 item | Claimed resolution | Status | Evidence `path:line` |
|---|---|---|---|
| **B-1** Playwright evidence not from committed sources | Committed env-driven harness, isolated port, CI Node 20; requested command passes at committed line 39:3 | **VERIFIED — and my r1 inference CONTRADICTED** | Harness committed: `apps/web/playwright.config.ts:3-5` (`PLAYWRIGHT_PORT`/`PLAYWRIGHT_BASE_URL`), `:28-31` (`pnpm exec vite --host localhost --port ${port} --strictPort`, `reuseExistingServer: !CI && !PLAYWRIGHT_PORT`). **I reproduced the line-number claim myself**: `pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --list` reports `batch-permissions.spec.ts:39:3` under Node **20.19.4** and `batch-permissions.spec.ts:88:3` under Node **25.2.1** — same committed bytes, `git status --short` empty. CI is Node 20 (`.github/workflows/ci.yml:16` `NODE_VERSION: '20'`; `smoke-test.yml:16`). My r1 claim that the green artefact "belongs to a working-tree revision that was rewritten before commit" is **false**; it was a Node-25 source-map artefact, exactly as the handback states. Retained log `docs/sessions/wlota1a/r1-browser-pre-node20.txt` shows `:39:3 … 1 passed (12.9s)`; see MINOR F-4(r2) |
| **B-2** post-activation poisons `pnpm test:e2e` | Suite skips without opt-in, before side effects | **VERIFIED** | `apps/web/e2e/batch-permissions.spec.ts:82` `test.skip(!process.env.WLOTA1A_POST_ACTIVATION, …)` at **describe-body** level → evaluated before the test body, i.e. before `readFileSync`/`psql`/`POST /companies`. `pnpm test:e2e` = `playwright test` (`apps/web/package.json:27`) on the default config, so the describe now skips; `docs/sessions/wlota1a/r1-browser-post-skipped.txt` shows `:83:3 … 1 skipped`, matching my `--list` output. `playwright.smoke.config.ts` and `playwright.campaign.config.ts` are independent configs, unaffected by the `webServer` rewrite. See MINOR F-6(r2) |
| **B-3** loop never exercised the create route | Uses the real `/inventory/batches/new` | **VERIFIED** | `apps/web/e2e/batch-permissions.spec.ts:51` now iterates `'/inventory/batches/new'`; route exists at `apps/web/src/routes/index.tsx:1218` (`path="batches/new"` → `RequirePermission permission="batches.create"` at `:1222`) and outranks `batches/:uuid` (`:1244`) by React-Router static-segment ranking |
| **B-4** `Array<any>` + unsafe spread | Transformer attribute emits `Array<string>`; warning gone | **VERIFIED** | `apps/api/app/Modules/Identity/Application/DTOs/RoleData.php:20` `#[LiteralTypeScriptType('Array<string>')]`; `packages/shared/types/generated.d.ts:1012` `permissions: Array<string>;`. My scoped ESLint run over all 19 lane web files reports **no** warning at `apps/web/src/features/settings/RolesPage.tsx:159` (`setSelectedPermissions([...role.permissions])`); the remaining RolesPage warnings are the pre-existing `89/105/106/122/132` untyped-`api` ones |
| **B-5** Roles-card padding/radius/elevation drift | `p-4`/`rounded-lg`/hover retained; only base background token | **VERIFIED (conservation-checked)** | `apps/web/src/features/settings/RolesPage.tsx:254-259`: `'rounded-lg border p-4 transition-shadow'` + `semanticColorTokens.surface.base` + unchanged branch. `semanticColorTokens.surface.base` = `'bg-white'` (`apps/web/src/lib/designTokens.ts:137-138`). Expanded to literals and diffed against `git show 4373ba2f6:…/RolesPage.tsx:257` (`'rounded-lg border bg-white p-4 transition-shadow'`) → **identical class set**, and `bg-white` still precedes `colors.primary[50]` in the `cn` argument order so the twMerge outcome for a system/provisioned card is unchanged. No `tokens.card.base` composite, no new `shadow-[var(--elevation-card)]`, no radius change |
| **B-6 / rider R3** `types.ts` still `number` | Totals are strings; false float docs removed; `3.1234` rendered + submitted | **VERIFIED** | `apps/web/src/features/batches/types.ts:38-39` (`Batch`), `:281` (`ExpiredBatchStock.available_quantity`), `:320,:322` (`ExpiredBatch`) all `string`; the false paragraphs are rewritten at `:269-271` and `:288-291`; `ExpiryWriteOffPage.tsx:51` docblock corrected. `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx:160-176` renders `3.1234` twice **and** asserts `lines: [{ batch_id: 'batch-uuid-1', quantity: '3.1234' }]` as a string. `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx:131-132,145` fixture flipped to `'10.0000'`. See MAJOR M-1(r2) on the *other* new render test, and MINORs F-1/F-2(r2) on residual lies in the same interfaces |
| **B-7** FE presents create/update as enforced boundaries the API does not enforce | Ticket + factual qualification (FormRequests already enforce) | **VERIFIED — my r1 premise CONTRADICTED** | `apps/api/app/Modules/BatchExpiry/Presentation/Requests/CreateBatchRequest.php:21-23` `return $this->user()?->can('batches.create') ?? false;`; `UpdateBatchRequest.php:11-13` same for `batches.update`. Both are **pre-lane** (`git show 4373ba2f6:…CreateBatchRequest.php:20-23` identical) and both permissions were already seeded pre-lane (`git show 4373ba2f6:…RolesAndPermissionsSeeder.php:425-426`). So the FE gates `routes/index.tsx:1222,1235` map onto a **real** API check of the **same literal permission names** — no UI overstatement. `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md` records the remaining *middleware* asymmetry honestly and explicitly instructs A-1b not to remove the FormRequest checks. My r1 wording ("the API does not enforce") was wrong and is retracted |
| **minor** provisioned-role copy | `roles.provisionedReadOnly` in en/fr/ar; legacy badge retained | **VERIFIED** | `RolesPage.tsx:276` `t(isReadOnlyRole(role) ? 'roles.provisionedReadOnly' : 'roles.systemRole')`; `apps/web/src/locales/en/common.json:1096` `"Provisioned · read only"`, `fr/common.json:1113` `"Provisionné · lecture seule"`, `ar/common.json:1079` `"مُهيّأ · للقراءة فقط"` — all three inside the same `roles` object, RTL-safe (the `·` is bidi-neutral). Pinned by `RolesPage.test.tsx:108`. The ternary cannot mislabel a legacy system role: a DB CHECK constraint restricts `provisioning_source` to `name = 'general_manager'` (`apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34,46`), and `general_manager ∉ SYSTEM_ROLES` (`RolesPage.tsx:29`). Same `tokens.badge.blue` treatment as the existing badge — no new competing accent (owner "one main element" rule) |
| **minor** modal-state test title | Title/comment name the tampering, not a refetch | **VERIFIED** | `RolesPage.test.tsx:117` `'prevents update submission when the selected modal role object is tampered to be protected'`; `:121` `// Tamper with the selected modal object; this is not a refetch (which would create a new object).` |
| **minor** fingerprint `handle` looks like dead config | Comment added | **VERIFIED** | `apps/web/src/routes/index.tsx:1205` `// Keeps the fingerprint literal in the served bundle for deployment evidence.` |
| **minor / tenancy N-5** untyped fixture, duplicate-key warning | Fixture has `id` and `satisfies Batch` | **VERIFIED** | `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:9-13` `… } satisfies Batch,` with `id: 1`; my run emitted **no** `unique "key" prop` warning (9 passed, clean stderr). Residual: the `satisfies` now cements `product_variant_id` — see the N-4(r2) assessment |

## Required verifications (items 1–13)

| # | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | All r1 frontend items + 3 minors closed as claimed | **VERIFIED** | Table above; every cited line opened and its pinning test run |
| 2 | B-1 harness committed, env-driven, CI Node 20; command runs the spec at `39:3`; fixtures only in the pre-activation half; post-activation skips before side effects | **VERIFIED (execution not re-run)** | `playwright.config.ts:3-5,28-31`; `--list` under Node 20 → `39:3`, under Node 25 → `88:3`; `ci.yml:16` Node 20. Pre-activation half uses `page.route('**/api/v1/**')` only (`spec:20-36`) and needs **no** API — `vite.config.ts:19-27` proxies `/api`→`:8010` but the route interceptor short-circuits it, so the project is self-contained. Post-activation half has **zero** mocked responses (`spec:106-197` real `request.*` + `psql`) and skips at `:82` before `readFileSync` at `:91`. `test:e2e` no longer poisoned (B-2). **Not re-run**: swap 9137/10240 MB used, 86 MB unused physical — launching Vite + Chromium is the documented freeze condition; the "1 passed" is **claimed, not re-run**, but its source-line provenance is now reproducible from committed bytes on my machine |
| 3 | B-3 real `/inventory/batches/new` route | **VERIFIED** | `spec:51` ↔ `routes/index.tsx:1218` |
| 4 | No `Array<any>`; no unsafe spread; typecheck clean | **VERIFIED** | `generated.d.ts:1012`; `RoleData.php:20`; **`pnpm typecheck` clean (27.3s)**, **`pnpm typecheck:e2e` clean**; scoped ESLint `✖ 58 problems (0 errors, 58 warnings)` over all 19 lane web files, no unsafe-spread at `RolesPage.tsx:159` |
| 5 | B-5 card conservation; tokens on touched lines | **VERIFIED** | See B-5 row. Whole-lane grep of `+` lines in `apps/web/src` for hardcoded Tailwind colours (`bg-/text-/border-…-\d{2,3}`, `-white`, `-black`) → **zero hits**; for interpolated variants/opacity on tokens (`hover:${…}`, `${…}/50`) → **zero hits**; for `colorClasses` → **zero hits**. `pnpm audit:design-system` → `802 acknowledged, 0 new, 0 stale` |
| 6 | B-6/R3 strings; N-4(r2) / I-2(r2) assessed | **VERIFIED for B-6; the two r2 items CONFIRMED** | See B-6 row and the assessment section. Consumers: `formatQuantity` accepts `string \| number` (`apps/web/src/lib/decimal.ts:206-213`, Big-based, no float) |
| 7 | B-7 ticket exists and its qualification is consistent with the FE gates | **VERIFIED** | See B-7 row; both-layer parity table in item 11 |
| 8 | Provisioned badge key in en/fr/ar; legacy badge retained; no hardcoded string on any touched line | **VERIFIED** | Locale lines above; whole-lane grep of `+` lines in non-test `.tsx` for JSX literal text / `title="` / `placeholder="` / `label="` → **zero hits** |
| 9 | i18n + design-system audits 0 new / 0 stale | **VERIFIED (run by me)** | `pnpm audit:keys` → `Gate C … 0` / `0 acknowledged, 0 new, 0 stale`; `pnpm audit:design-system` → `802 acknowledged, 0 new, 0 stale`; `pnpm audit:quantity` → `0 total (0 baselined, 0 new, 0 stale)`; `pnpm audit:i18n:local` → `i18n completeness OK — 55 namespaces … 2816 known gap(s) held at the baseline`. **Baseline honesty:** `git diff --stat 4373ba2f6..2fa724c1d -- apps/web/tools/` is **empty** — no baseline touched, so no `--write-baseline` absorption is possible |
| 10 | TanStack keys | **VERIFIED** | The lane adds/alters **no** `useQuery` key (`git diff 4373ba2f6..2fa724c1d -- apps/web/src \| grep '^+' \| grep -E 'useQuery\|queryKey\|useQueries\|tenantScopedKey'` → empty). All batch reads already use `tenantScopedKey` (`apps/web/src/features/batches/hooks/useBatches.ts:73,88,102,117,136,156`); `RolesPage.tsx:39-53` retains its scoped predicate. `audit:keys` 0/0 |
| 11 | Both-layer gating; manifest matches; Roles gate matches API | **VERIFIED** | Backend: the whole BatchExpiry group carries `'module:BatchExpiry'` (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:13`), reads carry `BatchActionAccess:batches.view` (`:15,16,23,25,27,30,42,45,48`), `batches.delete` (`:27`), `batches.recall` (`:30`), `batches.traceability` (`:39,40`), `can:batches.write-off` (`:21,36`), create/update via FormRequest `can()`. Frontend: `ModuleGuard module="BatchExpiry"` + `RequirePermission` on all five routes (`routes/index.tsx:1208-1209,1221-1222,1234-1235,1247-1248,1261-1262`). `ModuleGuard` fails **closed** on error/unknown key (`apps/web/src/components/guards/ModuleGuard.tsx:64-71`) and `BatchExpiry` is a real key (`apps/api/config/verticals.php:47,60,355`) — no fail-open. Manifest: `scripts/factory/manifests/routes-web.yaml:294-309` = `batches.view / batches.view / batches.update / batches.create`, `:358-361` = `batches.write-off`; **I ran `node scripts/factory/gen-route-manifest.mjs --check` → exit 0** with the generator untouched (`git diff --stat 4373ba2f6..2fa724c1d -- scripts/factory/` = manifest only) — the regeneration is honest, not hand-edited. Roles gate: FE hides edit/delete for `is_provisioned_read_only` (`RolesPage.tsx:285,319`) ↔ API `RoleController.php:241` (update abort on name/permissions) and `:285` (destroy abort) |
| 12 | Push 3 → Push 5 window: served bundle handles string totals | **CONTRADICTED — BLOCKER B-1(r2)** | The deployed (pre-lane) bundle calls `.toFixed()` on the now-string field. Full evidence in the finding |
| 13 | Scope census | **VERIFIED** | Section below; one out-of-register file (`routes-web.yaml`), legitimately generated |

## Assessment of tenancy N-4(r2) and inventory I-1(r2)/I-2(r2) from the frontend-conventions side

**N-4(r2) — `types.ts` in Push 5 while the wire change is Push 3: CONFIRMED, and materially worse than "one-push tolerance".**
The tenancy register is right that the ledger splits them (`docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:205,210` = Push 5 vs `:30` = Push 3) and right that `formatQuantity` is `string | number`-tolerant. But its conclusion — "Runtime-tolerant only because `formatQuantity` accepts `string | number`" — evaluates the **HEAD** consumers. The bundle actually served during that window is the **pre-lane** one, and there `formatQuantity` is not yet in the file. That flips the tolerance verdict from "acceptable, document it" to a hard runtime break → **BLOCKER B-1(r2)**. Its recommendation ("move `types.ts` to Push 3's ledger row if the frontend reviewer agrees") is the right instinct but insufficient: `types.ts` is compile-time only; `BatchListPage.tsx` must move too.

**N-4(r2) — `batch_stock[]` members still `string | number`: CONFIRMED, MINOR.** `apps/web/src/features/batches/types.ts:49-54`. The API emits strings for all four (`BatchResource.php:61-64`, `location_id` a UUID). The union is over-permissive, not unsound, and no consumer relies on the numeric arm (`CreateStockTransferPage.tsx:80` already wraps in `String(...)`). Worth closing while the file is open, because the same file's `ExpiredBatchStock` (`:272-282`) now declares all three quantities as bare `string` — two shapes for one wire object with different types.

**N-4(r2) — `product_variant_id` vs `variant_id`: CONFIRMED, and I add the part the tenancy register missed.** `BatchResource.php:31` emits `'variant_id'`. `types.ts:18` declares `product_variant_id: string | null` (non-optional) and declares **no** `variant_id` — so the field is `undefined` at runtime under a non-nullable declaration, and the real field is invisible to TypeScript. What the tenancy register did not note: **the very same file gets it right for the other interface** — `types.ts:302` `variant_id: string | null` on `ExpiredBatch`, served by the **same** `BatchResource`. So this is a one-surface-per-concept violation *internal to one file*, not merely a backend/frontend drift, and the fix round newly cemented the wrong arm with `satisfies Batch` (`BatchPermissions.test.tsx:9-13`). Convention 11 severity would normally be MAJOR ("hand-rolled FE type shadowing a generated DTO"), but there is **no** generated `Batch` DTO (`grep 'Batch' packages/shared/types/generated.d.ts` finds none) — the hand-rolled type has no counterpart to shadow, and `product_variant_id` predates the lane. I therefore leave it at the tenancy register's MINOR rather than escalating, and confine my new finding to the parts the round itself added (F-1(r2)).

**I-1(r2) — `quantity_decimals` differs by endpoint: CONFIRMED, and it is the reason the display deferral is only half-legitimate.** `BatchResource.php:55-57` hard-falls-back to `4` when `product.unitOfMeasure` is unloaded; `/batches` does not load it. I add the frontend half the inventory register could not see: **`Batch.product` in `types.ts:62-66` does not declare `quantity_decimals` at all** (only `ExpiredBatch.product` does, `:332-333`). So even after I-1(r2)'s backend eager-load lands, `BatchListPage` cannot call `getQuantityDecimals(batch.product)` without a further `types.ts` change — and `getQuantityDecimals` silently returns `DEFAULT_QUANTITY_DECIMALS` for an absent field (`apps/web/src/lib/quantityScale.ts:10-13`), so a naive fix would look right and be wrong. The deferral ticket names neither prerequisite → MINOR F-2(r2).

**I-2(r2) — the new Vitest case pins a lot quantity at CURRENCY precision as correct: CONFIRMED, and I raise it to MAJOR.** The inventory register graded it MINOR. From the frontend-conventions lens it is MAJOR because **the lane touched the exact line**: `git diff 4373ba2f6..2fa724c1d -- …/BatchListPage.tsx` changes `:212` `{totalQuantity.toFixed(decimals)}` → `:219` `{formatQuantity(totalQuantity, decimals)}`. Rule 18/19's "only lines you touch" scope is therefore satisfied, and the round chose to migrate the float away while keeping `useCurrency().decimals` — then added a test that names the wrong behaviour as right. Detail in M-1(r2).

## Findings

### BLOCKER

**B-1(r2) — Push 3 (API string totals) before Push 5 (web bundle) crashes the deployed Batches list page for every BatchExpiry tenant, flag-independent.**

The five-push ledger ships `BatchResource.php` in **Push 3** (`docs/handoff/HANDBACK-WLOTA-1a-2026-09-09.md:30`) and `apps/web/src/features/batches/pages/BatchListPage.tsx` + `apps/web/src/features/batches/types.ts` in **Push 5** (`:205`, `:210`), with Push 4 mandated between them ("Push 4 acceptance precedes every Push 5 source promotion", `:134`). During that window the served bundle is the pre-lane one:

```
git show 4373ba2f6:apps/web/src/features/batches/pages/BatchListPage.tsx
:171   const totalQuantity = batch.available_quantity ?? 0
:212   {totalQuantity.toFixed(decimals)}
```

Push 3 changes `available_quantity` from a JSON **number** to a 4-dp **string** (`apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:45` → `scopedAvailableQuantity(): string` at `:82`, `bcadd`/`bcsub` at `:84-86`), on `GET /batches` (`BatchController.php:158`) among others. `"3.0234"` is non-nullish, so `?? 0` does not rescue it, and `"3.0234".toFixed` is `undefined` → **`TypeError: totalQuantity.toFixed is not a function`** thrown inside the row `map`, i.e. a white-screen / error-boundary on `/inventory/batches` for any tenant with ≥1 batch. This is **independent of `LOT_ACTION_PERMISSIONS_ENFORCE`** — the release note itself says the contract change applies "including when `LOT_ACTION_PERMISSIONS_ENFORCE=false`" (`docs/handoff/RELEASE-NOTES-WLOTA-1a-2026-09-10.md:5-7`).

Why it survived r1 and the fix round: the release note asserts "Consumers must accept strings" (`RELEASE-NOTES…:7`) and the handback's census row reads "BatchListPage | Existing `formatQuantity` accepts strings" (`HANDBACK…:116`) — both true of the **Push-5** file, neither true of the file that is live when Push 3 lands. My own r1 consumer table made the same error and cited `BatchListPage.tsx:178, 219` at HEAD. The only other deployed consumers are safe: pre-lane `ExpiryWriteOffPage` already routed everything through string helpers, `BatchDetailPage.tsx:271-300` reads `level.*` from the untouched `/batches/{uuid}/stock` serialiser, `CreateStockTransferPage.tsx:80` wraps in `String(...)`, and `grep -rn 'total_quantity\|available_quantity' apps/pos/src` is empty. So the blast radius is exactly one screen — the feature's primary surface.

*Minimum correction (verifiable):* move the two string-tolerance web files — `apps/web/src/features/batches/types.ts` and `apps/web/src/features/batches/pages/BatchListPage.tsx` — into **Push 3**'s ledger rows (or an explicit Push 2.5 that precedes Push 3), keeping the permission-gating web changes in Push 5. That slice is **bidirectionally** safe: `formatQuantity` accepts `string | number` (`apps/web/src/lib/decimal.ts:206-213`), so the new render is correct against both the old numeric payload and the new string payload, and `BatchListPage`'s only other lane change (`canCreate &&` at `:91,:139`) is inert while `batches.create` is already held by every role that could see the button pre-lane. Then correct `HANDBACK…:116` and add the window to the release note. Do **not** close this by asserting the whole branch deploys atomically — §11 mandates the staged order, and the handback explicitly says "never auto-deploy the entire branch" (`:134`).

### MAJOR

**M-1(r2) — the fix round added a regression lock on a rule-19 display violation, on a line this lane touched, under a test name that claims the opposite.**

`apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx:35-38`:

```ts
it('renders four-decimal API strings using the existing display precision', () => {
  list()
  expect(screen.getByText('3.123')).toBeInTheDocument()
})
```

The fixture carries `total_quantity: '3.1234'` / `available_quantity: '3.1234'` (`:12-13`) and `useCurrency` is mocked to `{ decimals: 3 }` (`:19`), so the assertion pins the **currency**-precision render of a **lot quantity** — `BatchListPage.tsx:23` `const { decimals } = useCurrency()` feeding `:219` `formatQuantity(totalQuantity, decimals)`. Rule 19 / `docs/architecture/precision-contract.md` "Emission & display" requires `units.decimal_places` via `getQuantityDecimals`; the same lane demonstrates the correct pattern two files away (`ExpiryWriteOffPage.tsx:243` `decimalPlaces={getQuantityDecimals(batch.product)}`). In real EUR/TND tenants `decimals` is 2–3, so a 4-dp pharmacy lot quantity is silently truncated on the list.

Three things make this MAJOR rather than the inventory register's MINOR: (a) **the lane touched the line** — `:212 toFixed(decimals)` → `:219 formatQuantity(totalQuantity, decimals)` — so rule 18/19's "only the lines you touch" scope is met and the correct call was one argument away; (b) the test **name asserts four decimals while the body asserts three**, so a future reader grepping for the display contract will conclude 4-dp fidelity is proven; (c) it converts a silent pre-existing bug into a **test-enforced** one, so the deferral ticket's fix now requires editing a green assertion — the classic way a deferral becomes permanent. `tools/audit-quantity-display.mjs` cannot see it (`total_*`/`available_*` are in the scanner's EXCLUDE set at `:18-20`), which is precisely why the test name matters.

*Minimum correction:* rename the case to what it asserts (e.g. "renders the API string through the current currency-precision path — see ticket …") **and** add a one-line comment naming `docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md`; or, preferably, fix it now — `getQuantityDecimals(batch.product)` with the `types.ts` and eager-load prerequisites from F-2(r2)/I-1(r2), and assert `'3.1234'`.

### MINOR

**F-1(r2) — the round rewrote the docblock immediately above a second false field declaration in the same interface, and added a third impossible fixture value.**
`apps/web/src/features/batches/types.ts:274-275` declares `location_id: number` with `/** Bigint PK from the locations table */`. `inventory_batch_stock.location_id` is `foreignUuid` (`apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php:22`) and `BatchResource.php:62` emits it verbatim — a UUID **string**; the lane's own post-activation spec asserts `batch_stock.map(row => row.location_id)).toEqual([b2.id])` against a UUID (`e2e/batch-permissions.spec.ts:169`). Rider R3's remit was making this exact interface truthful about the wire, and the round rewrote `:269-271` two lines above while leaving `:274-275`. Worse, it added a **new** numeric fixture at `ExpiryWriteOffPage.test.tsx:163` (`location_id: 10`), alongside the two pre-existing ones at `:104,:119`. No runtime consumer compares or arithmetics on the field, so this is documentary — but it is added debt inside the diff. *Fix:* `location_id: string` with a corrected comment on `ExpiredBatchStock`, narrow `Batch.batch_stock[]` (`:49-54`) to strings, and give the three fixtures UUID-shaped values.

**F-2(r2) — the deferral ticket prescribes a fix that cannot be executed as written.**
`docs/superpowers/tickets/2026-09-10-batch-detail-parsefloat-quantities.md:5` says "use the owning unit's decimal places via `getQuantityDecimals` for list quantities". Two prerequisites are unstated: (a) `Batch.product` (`apps/web/src/features/batches/types.ts:62-66`) does not declare `quantity_decimals`, unlike `ExpiredBatch.product` (`:332-333`); (b) `/batches` does not eager-load `product.unitOfMeasure`, so `BatchResource.php:55-57` hard-returns `4` there (inventory I-1(r2)). And `getQuantityDecimals` returns the default silently for an absent field (`apps/web/src/lib/quantityScale.ts:10-13`), so a fix that skips (a) or (b) passes review and ships the wrong number. *Fix:* add both prerequisites to the ticket's Acceptance section.

**F-3(r2) — a third quantity-display surface renders through the deprecated `formatQuantity`, is newly pinned by this round, and is not in the deferral ticket.**
`apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:215,222,229` render on-hand / reserved / available via `formatQuantity` imported from `../../../lib/format` (`:10`), whose own `@deprecated` tag reads "This variant TRIMS trailing zeros and locale-groups — wrong for unit-precision display" (`apps/web/src/lib/format.ts:189-192`). My scoped ESLint run reports `@typescript-eslint/no-deprecated` at exactly `215:26`, `222:26`, `229:26`. The lines are pre-existing (the round touched only the docblock at `:48-51`), but the round's new test asserts their output (`ExpiryWriteOffPage.test.tsx:166` `getAllByText('3.1234')).toHaveLength(2)`), and the deferral ticket names only `BatchDetailPage` and `BatchListPage`. *Fix:* add these three sites to the ticket (they should use `formatQuantity` from `@/lib/decimal` at `getQuantityDecimals(batch.product)`, which `ExpiredBatch.product` already carries).

**F-4(r2) — the retained browser evidence is gitignored and carries no command or SHA header.**
`docs/sessions/wlota1a/r1-browser-pre-node20.txt` is excluded by `.gitignore:64` (`docs/sessions/`) and its first line is a `[WebServer]` warning — no command, no `git rev-parse`, no `PLAYWRIGHT_PORT`. The claim is now *reproducible* from committed sources (I reproduced the `39:3` line resolution myself, and the config is committed), so B-1 is closed on substance; but the artefact alone still cannot be audited, which is exactly what produced the r1 round-trip. *Fix:* have the Push-5 evidence step emit `git rev-parse HEAD`, the full command and `node -v` as the first three lines of the log.

**F-5(r2) — dead null-coalesce left behind by the type flip.**
`apps/web/src/features/batches/pages/BatchListPage.tsx:178` `const totalQuantity = batch.available_quantity ?? 0` — `available_quantity` is now a non-optional `string`, and my ESLint run reports the **new** warning `178:39 Unnecessary conditional, expected left-hand side of '??' operator to be possibly null or undefined`. Harmless, but it is the residue of the very defect in B-1(r2) and reads as defensive tolerance that no longer exists. *Fix:* drop `?? 0`.

**F-6(r2) — the post-activation skip guard is truthiness-based, not value-based.**
`apps/web/e2e/batch-permissions.spec.ts:82` `test.skip(!process.env.WLOTA1A_POST_ACTIVATION, …)` versus `:90` `expect(required('WLOTA1A_POST_ACTIVATION')).toBe('1')`. With `WLOTA1A_POST_ACTIVATION=0` the guard does not fire and the test runs to a failure at `:90`. No side effect occurs before that line (`readFileSync` at `:91` is the first, and it is read-only), so B-2 stands — but a plausible operator typo produces a red instead of a skip. *Fix:* `test.skip(process.env.WLOTA1A_POST_ACTIVATION !== '1', …)`.

## Runs

| Command | Result | Matches handback? |
|---|---|---|
| `pnpm typecheck` | clean, 27.3s | **yes** ("TypeScript … typechecks pass") |
| `pnpm typecheck:e2e` | clean | **yes** |
| `pnpm audit:keys` | `Gate C … : 0` / `0 acknowledged, 0 new, 0 stale` | **yes** |
| `pnpm audit:design-system` | `802` / `802 acknowledged, 0 new, 0 stale` | **yes** |
| `pnpm audit:quantity` | `0 total (0 baselined, 0 new, 0 stale)` | **yes** |
| `pnpm audit:i18n:local` | `i18n completeness OK — 55 namespaces … 2816 known gap(s) held at the baseline` | **yes** |
| `pnpm exec eslint` on all 19 lane web files | `✖ 58 problems (0 errors, 58 warnings)` | **0 errors: yes.** Warning count differs (handback says 32) — my file list is the full lane (19 files incl. the e2e spec, config and all test files); the load-bearing claim, "no `RoleData` unsafe-spread warning", **reproduces** |
| `vitest run src/routes/__tests__/BatchRoutePermissions.test.tsx` | 7 passed | **yes** (routes 7) |
| `vitest run src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | 9 passed, no key warning | **yes** (batch permissions 9) |
| `vitest run src/features/batches/pages/__tests__/BatchSeededPermissionMap.test.ts` | 1 passed | **yes** |
| `vitest run src/features/settings/RolesPage.test.tsx` | 3 passed | **yes** |
| `vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx` | 56 passed | **yes** |
| `vitest run src/hooks/__tests__/usePermissions.moduleAccess.test.tsx` | 12 passed | **yes** |
| **§13 six-file subtotal** | **88 passed** | **yes — reproduces exactly** |
| `vitest run src/features/batches/pages/ExpiryWriteOffPage.test.tsx` | 8 passed | **yes** |
| `vitest run src/features/batches/hooks/__tests__/tenantScope.test.tsx` | 3 passed | **yes** |
| **Targeted web total** | **99 passed** | **yes — the handback's 99 reproduces exactly** |
| `node scripts/factory/gen-route-manifest.mjs --check` | exit 0 | **yes** (generated, not hand-edited; generator untouched) |
| `pnpm exec playwright test e2e/batch-permissions.spec.ts --project=chromium --list` (Node **20.19.4**) | `batch-permissions.spec.ts:39:3` (pre-activation), `:83:3` (post-activation) | **yes — vindicates the handback's Node-version explanation** |
| same, Node **25.2.1** | `:88:3`, `:166:3` on identical committed bytes | **yes — reproduces the r1 discrepancy without any source edit** |
| Playwright **execution** of the pre-activation project | **NOT RUN.** Blocked by `vm.swapusage used = 9137.38M / 10240.00M`, `PhysMem … 86M unused`; Vite + Chromium is the documented swap-exhaustion freeze condition. The project needs no API (page.route intercepts `**/api/v1/**`) and no build, only memory | Evidence graded **claimed, not re-run**; its source-line provenance is independently reproduced above |
| `pnpm lint` (full) | **NOT RUN** — chains a repo-wide ESLint pass + `test:eslint-rules` + `test:tools`; substituted the scoped ESLint run and the four audits individually | n/a |
| Vitest worker pools | all killed after each file; `ps aux \| grep -c '[n]ode (vitest'` → `0` | n/a |

## Scope census

Fix-round files in the frontend lens (14 of 37):

| File | r1 ask it serves | Verdict |
|---|---|---|
| `apps/web/e2e/batch-permissions.spec.ts` | B-2, B-3 | in scope, minimal (2 lines) |
| `apps/web/playwright.config.ts` | B-1 | in scope; env-driven, default behaviour preserved (`reuseExistingServer` still true for a plain local run) |
| `packages/shared/types/generated.d.ts` | B-4 | in scope; one line, transformer-consistent |
| `apps/web/src/features/settings/RolesPage.tsx` | B-5 + provisioned copy | in scope; 2 lines |
| `apps/web/src/features/settings/RolesPage.test.tsx` | badge + modal-state minor | in scope |
| `apps/web/src/features/batches/types.ts` | B-6 / R3 | in scope; residual F-1(r2), F-2(r2) |
| `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx` | B-6 doc | in scope; 1 line |
| `apps/web/src/features/batches/pages/ExpiryWriteOffPage.test.tsx` | B-6 render/submit proof | in scope; residual F-1(r2), F-3(r2) |
| `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx` | B-6 + N-5 fixture | in scope; **M-1(r2)** on the added case |
| `apps/web/src/features/batches/hooks/__tests__/tenantScope.test.tsx` | B-6 consumer fixture | in scope |
| `apps/web/src/locales/{en,fr,ar}/common.json` | provisioned copy | in scope; one key each, all three locales |
| `apps/web/src/routes/index.tsx` | fingerprint minor | in scope; comment only |
| `scripts/factory/manifests/routes-web.yaml` | **outside the r1 register** | **legitimate** — preflight-detected stale generated artefact; 4 `permission: null` → the real permissions; `--check` exit 0; generator byte-identical; values match `routes/index.tsx:1209,1222,1235,1248` and `:1262` |
| `.github/workflows/ci.yml` | manifest/CI raise | outside my lens (single PG `--filter` line, tenancy/inventory-owned) |

No evasion mechanism found: no alias table re-exporting `tokens.*`, no suppression comment containing a detector keyword, no renamed-but-equivalent literal, no baseline file touched (`apps/web/tools/` diff empty), no `eslint-disable` added (`git diff … | grep '^+.*eslint-disable'` → empty). The B-5 fix is a genuine literal-conservation revert, not a token→token swap that hides a visual change.

## Tree state after review

`git status --short` empty at `2fa724c1d83098e12d05024e5e51b083d5566ed0`. My two `--list` invocations regenerated `apps/web/playwright-report/index.html` (gitignored, `.gitignore:39`); I removed it and the now-empty directory. `apps/web/test-results/.last-run.json` is the implementer's, mtime 12:34, untouched. No vitest worker pool left running. No file in the worktree edited, committed, merged or pushed.

---

**Register file to commit verbatim:** `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-09-10-w-lot-a-1a-impl-gate-r2-frontend.md`

**Two self-corrections the orchestrator should carry forward:** my gate-r1 findings **B-1** (uncommitted-spec inference) and **B-7** (API-does-not-enforce claim) are both empirically refuted at r2 and should be marked retracted in the r1 register, so A-1b is not scoped against a false premise.

VERDICT: CHANGES-REQUIRED
BLOCKER=1 MAJOR=1 MINOR=6

Orchestrator: session_01AcU81as26G2apodoimmyq7 (reviewer = Claude Opus `frontend-conventions-reviewer` agent).

## Orchestrator rulings (session_01AcU81as26G2apodoimmyq7, 2026-09-10)
- **B-1(r2): ADOPT** — `apps/web/src/features/batches/types.ts` and `apps/web/src/features/batches/pages/BatchListPage.tsx` move to the **Push 3** ledger row (web string-tolerance slice deployed with, or before, the API string totals; bidirectionally safe via `formatQuantity(string | number)`). The permission-gating web changes stay in Push 5. Correct handback `:116` and the release note (name the window). Plan rev 10 §11 gets a rev-11 amendment recording the split.
- **M-1(r2): FIX NOW** (not defer) — `BatchListPage` renders lot quantities at `getQuantityDecimals(batch.product)`; prerequisites: `Batch.product` gains `quantity_decimals` in `types.ts`, and the API eager-loads `product.unitOfMeasure` on `/batches` and the five mutation responses (inventory I-1(r2)); the Vitest case asserts `'3.1234'` and is renamed truthfully. The parseFloat ticket keeps `BatchDetailPage` only, plus the three `ExpiryWriteOffPage` deprecated-`formatQuantity` sites (F-3(r2)).
- **F-1, F-2, F-5, F-6(r2): FIX** in the round. **F-3(r2): TICKET** (add to the deferral ticket). **F-4(r2): FIX** — Push-5 evidence step emits `git rev-parse HEAD`, the command and `node -v` as the first three lines.
- **Retractions carried forward:** gate-r1 frontend B-1 (uncommitted-spec inference) and B-7 (API-does-not-enforce claim) are RETRACTED at r2; the r1 register is annotated, and ticket `2026-09-10-batch-create-update-api-enforcement.md` (A-1b) is scoped to the middleware asymmetry only.
