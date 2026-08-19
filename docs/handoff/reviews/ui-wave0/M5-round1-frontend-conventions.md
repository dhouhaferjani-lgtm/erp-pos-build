# M5 Adversarial Bridge Review — Round 1 (lens: frontend-conventions)

**Scope:** `M5 = T4 (UI-01/UI-02) + T15 (UI-07)` + one authorised manifests-only sync, per
`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:251-305` (T4), `:603-637` (T15), `:226-249` (§2 manifest model),
and the parent M5 ruling recorded verbatim in `docs/handoff/progress/ui-wave0.progress.yaml:155`.
**Range reviewed:** `acf5dc6bd..0937aabc5` — `77257bf88` (T4), `3608a8b1d` (T15), `a6dc879b8` (manifest sync), `0937aabc5` (YAML).
**Worktree:** `.worktrees/ui-wave0`, branch `codex/ui-wave0-2026-08-11`, tip verified `0937aabc5` (matches the expected tip). Read-only; tree clean before and after.
**Lens:** frontend-conventions (design system, gate discipline, i18n, test honesty).

---

## Acceptance criteria — independently verified

### T4 (brief `:251-305`)

| Criterion | Result |
|---|---|
| `canAccessModule` returns **false** on an unrecognised key | ✅ `apps/web/src/hooks/usePermissions.ts:152-160` — `if (!requiredPermissions) return false`, with a comment stating the fail-closed contract. Pre-change source is `return true // No restrictions` (`git show acf5dc6bd:apps/web/src/hooks/usePermissions.ts:129-132`) — the inversion is real, not cosmetic |
| `MODULE_PERMISSIONS` yields a **literal** union | ✅ `usePermissions.ts:29,86` (`as const satisfies Record<string, readonly Permission[]>`), `:94` (`export type ModuleKey = keyof typeof MODULE_PERMISSIONS`). The `Partial<Record<string, …>>` erasure is gone |
| Mandatory pre-step: both helpers widened to `readonly Permission[]` | ✅ `usePermissions.ts:138` (`hasAnyPermission`), `:145` (`hasAllPermissions`) — correct plural name, both widened |
| Row 1 — `goods-receipt.create-standalone` self-mapped | ✅ `usePermissions.ts:83`; the nav item still declares it (`Sidebar.tsx:182`); backend permission = `['admin','manager']` (`permissionsMap.generated.ts:89`) |
| Row 2 — `inventory.view` self-mapped | ✅ `usePermissions.ts:85`; nav item `Sidebar.tsx:215`; backend permission `permissionsMap.generated.ts:118` |
| Row 3 — **both** `parts_catalog` `RequirePermission` wrappers deleted, `ModuleGuard` **kept** | ✅ `routes/index.tsx:1428-1450` — both routes now `ModuleGuard module="PlatformIntegration"` (`:1432`, `:1442`) → `SuspenseWrapper` → page; no `RequirePermission` remains. `grep -n moduleKey= src/routes/index.tsx` → no hits |
| Row 4 — `/crm/companies` swapped to `permission="partners.view"`, **route retained** | ✅ `routes/index.tsx:2752`; the `companies` route and `CrmCompanyListPage` are still mounted (dedup deferred to UI-35) |
| Six call surfaces re-typed to `ModuleKey` | ✅ `RequirePermission.tsx:11`; `Sidebar.tsx:111` (NavChild), `:121` (NavModule), `:425` (`isNavItemVisible`); `useCommandPalette.ts:42`; `PosHubPage.tsx:25` + `InventoryHubPage.tsx:28` (`permissionModule`); direct callers `PosRefundPoliciesPage.tsx:130` and `CashPositionWidget.tsx:128` compile clean on valid keys. `MarketingHubPage`/`FinanceHubPage` no longer exist (`find src -name 'MarketingHubPage*' -o -name 'FinanceHubPage*'` → empty), consistent with the M3 deletions |
| No production surface still accepts a bare `string` | ✅ full grep census of `canAccessModule` / `moduleKey?:` / `permissionModule?:` / nav `permission?:` — the only remaining `permission?: string` are **test-local mock props** (`StockLevelsPage.test.tsx:47`, `CashMovementsRoute.test.tsx:24`), which do not feed the helper |
| `RequirePermission` unknown-key test asserts children **not** rendered | ✅ `features/auth/components/__tests__/RequirePermission.moduleKey.test.tsx:26-40` — `expect(screen.queryByText('guarded-content')).not.toBeInTheDocument()`, plus a positive control (`:42-54`) and a known-key-role-lacks case (`:55-70`) |
| `canAccessModule` unknown-key unit test | ✅ `hooks/__tests__/usePermissions.moduleAccess.test.tsx:38-53` (`parts_catalog` and `totally-unknown-module` → `false` even for `admin`; known key resolves by role) |
| Sidebar role-gating test | ✅ `Sidebar/__tests__/Sidebar.test.tsx:688-720` — `newGoodsReceipt` absent for a role lacking the permission, present for `manager`, with a positive control on the sibling `goodsReceipts` link so the absence is the item's own gate |
| Typecheck green afterwards | ✅ **I ran it:** `pnpm --filter @autoerp/web typecheck` → clean |

**Red-first topology — verified structurally, not on the executor's word.** I could not replay the red run without mutating the tree (see *Bypasses*), so I verified the pre-change source instead: at `acf5dc6bd`, `canAccessModule` returned `true` for any key outside the map, and `Sidebar.tsx:182/215` already carried the two invalid keys. Every new assertion therefore inverts a behaviour that provably held before the change — unknown key → `true` (so both `false` assertions fail), and `newGoodsReceipt` visible to any role under the real hook (so the absence assertion fails). Red-first is structurally guaranteed for the T4 set; the same holds for T15 (`treasury` → `treasury.view` = `accountant/admin/manager`, `permissionsMap.generated.ts:260`, so the cashier assertion fails pre-change).

### T15 (brief `:603-637`)

| Criterion | Result |
|---|---|
| Exactly **one** line of production code | ✅ `git show 3608a8b1d --stat` → `Sidebar.tsx | 2 +-` (one changed line) + `Sidebar.test.tsx`. Nothing else |
| `Sidebar.tsx:280` reads `permission: 'expenses'` | ✅ verified in the working tree |
| No route-guard change, no `MODULE_PERMISSIONS` change | ✅ the commit touches no other file; `/expenses` route guard remains `permission="expenses.view"`; the map is untouched by `3608a8b1d` |
| Treasury siblings untouched | ✅ `payments`/`repositories`/`instruments` (`Sidebar.tsx:274-276`) still `permission: 'treasury'`; only `:280` changed |
| cashier-sees test | ✅ `Sidebar.test.tsx:735-742` — `/expenses` link renders for `roles: ['cashier']` (`expenses.view` holder, `permissionsMap.generated.ts:81`; not a `treasury.view` holder, `:260`) |
| holds-neither test | ✅ `Sidebar.test.tsx:745-755`, with a positive control that the sidebar rendered |
| Nav/route parity actually achieved | ✅ nav key `expenses` → `['expenses.view']` (`usePermissions.ts`) === route guard `permission="expenses.view"` |

### Manifest sync (parent ruling)

| Criterion | Result |
|---|---|
| `git show a6dc879b8 --stat` = manifests only | ✅ `scripts/factory/manifests/routes-web.yaml | 4 ++--` and nothing else |
| Regen diff contains ONLY the `/crm/companies` gate change | ✅ the two changed lines are `module_gate: partners → null` and `permission: null → partners.view` at `routes-web.yaml:129-133`. **Stronger check:** the manifest delta over the *whole* range `acf5dc6bd..HEAD` is those same two lines, so nothing else was absorbed anywhere in M5 |
| `parts_catalog` deletions produce no delta | ✅ `routes-web.yaml:486-493` still `module_gate: PlatformIntegration`, consistent with the generator's A4 rule (`gen-route-manifest.mjs:12-14`) |
| `routes-pos.yaml` unchanged | ✅ not in any range stat |
| `check-manifest-drift.sh` exits 0 at the tip | ✅ **I ran it:** `EXIT=0`, tree left clean. The script is a real regen-into-tempdir + `diff -ru` (`check-manifest-drift.sh:12-22`), not a no-op |
| Exactly two manifest-touching commits on the branch | ✅ `git log origin/dev..HEAD -- scripts/factory/manifests/` → `a6dc879b8`, `00366d151`. `git log --follow` on `routes-web.yaml` confirms the next-older touch (`200f5506e`) predates the branch |

---

## Standing checks (frontend-conventions)

- **Design tokens (rule 18):** no colour/class strings were added or edited anywhere in the range. `pnpm --filter @autoerp/web lint` → **`audit:design-system` 736 acknowledged / 0 new / 0 stale**; `tools/audit-design-system-baseline.json` is **not** in the range stat — no baseline absorption.
- **i18n (rule 11):** no new user-facing text. Both nav items reuse existing `navigation.*` keys; the only added prose is code comments.
- **`tenantScopedKey` (rule 14):** no query keys touched; `audit:keys` Gate C → `0 acknowledged, 0 new, 0 stale`.
- **Money/quantity (rule 19):** not applicable; `audit:quantity` → `0/0/0`.
- **Hidden-not-disabled (OQ-11):** honoured — both nav changes hide/reveal entries; no `disabled` control or coming-soon branch was introduced.
- **Fail-closed gates (the ruling this milestone exists to enforce):** satisfied at both the type layer (`ModuleKey` union) and the runtime layer (`return false`). No `as ModuleKey` cast exists in production code — the only two casts are inside the new tests and are documented as the deliberate way to reach the runtime branch (`RequirePermission.moduleKey.test.tsx:33`, `usePermissions.moduleAccess.test.tsx:44-45`).
- **Nav/route parity of the two narrowed items (F-3):** verified, and it is *better* than the brief required — `newGoodsReceipt` (`Sidebar.tsx:182`) now matches its route guard `permission="goods-receipt.create-standalone"` (`routes/index.tsx:936`), and `stockByLocation` (`Sidebar.tsx:215`) matches `permission="inventory.view"` (`routes/index.tsx:1072`). T4 therefore closes two rule-12 parity gaps rather than opening any.
- **`/crm/companies` blast radius:** `partners.view` is held by all seven backend roles (`permissionsMap.generated.ts:149`), and the only link to that route is the `companies` nav item gated on `contacts` → `contacts.view` = `admin/cashier/manager` (`Sidebar.tsx:262`, `permissionsMap.generated.ts:46`), a strict subset. The gate swap is therefore access-neutral and creates no orphaned/unreachable link — correctly absent from the F-3 user-visible list.
- **Guardrails re-run by me (nothing accepted on report):** `pnpm --filter @autoerp/web lint` → **0 errors** (warnings only, all pre-existing); `pnpm --filter @autoerp/web typecheck` → clean; `pnpm vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx src/hooks/__tests__/usePermissions.moduleAccess.test.tsx src/features/auth/components/__tests__/RequirePermission.moduleKey.test.tsx --maxWorkers=1` → **3 files / 54 tests passed**.

---

## Findings register

**1. P2 — CONFIRMED — `apps/web/src/routes/index.tsx:1428-1450` + `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:24-70` — after T4 row 3, the parts-catalog surface has no role gate on *either* layer; the brief's "the real gate is intact" overstates what `ModuleGuard` provides. REPORT-ONLY: not an M5 regression.**
The deleted `RequirePermission moduleKey="parts_catalog"` wrappers evaluated to `true` for every role pre-change (fail-open), so deleting them changes nobody's access — the executor's resolution is exactly what the brief prescribed and is correct as written. But `ModuleGuard module="PlatformIntegration"` is a **company-module** check, not a permission check, and the backend it fronts carries no matching gate: the `api/v1/platform` group is `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` with **no `module:PlatformIntegration` middleware and no `can:` on any `catalog/*` route** (`routes.php:24`, `:29-70`), which is what `features/parts-catalog/api/partsCatalog.ts:17-58` calls.
*Failure scenario:* any authenticated tenant user — including a tenant without the PlatformIntegration module — can call `/api/v1/platform/catalog/*` directly and read the paid catalog; the FE `ModuleGuard` is client-side only and is now the sole gate on the whole feature. This is precisely the both-layers rule (CLAUDE.md rule 12, `docs/architecture/vertical-module-gating.md`).
*Fix directive:* ticket for a later wave — add `module:PlatformIntegration` middleware (and a `can:` permission) to the catalog route group; do **not** re-add a FE-only wrapper. Out of M5 scope (rule 4); recorded so the parent does not inherit the brief's "gate intact" wording as a closed item.

**2. P3 — CONFIRMED — `docs/handoff/progress/ui-wave0.progress.yaml:189` — the harness note says "the 43 pre-existing Sidebar tests"; the true pre-existing count is 41.**
`Sidebar.test.tsx` holds 41 `it(` blocks at `acf5dc6bd`, 43 after T4 (+2) and 45 after T15 (+2); my run reports 45 for that file. "43" is the post-T4 total, not the pre-existing count.
*Failure scenario:* a later round re-uses the figure as the unmodified-test baseline and mis-attributes two T4 tests as pre-existing coverage.
*Fix directive:* one-word YAML correction (41) at the next YAML touch; no code change.

**3. P3 — CONFIRMED — `Sidebar.test.tsx:699,746`, `usePermissions.moduleAccess.test.tsx:68` — the negative role cases use `purchases`, a UI-alias-only role, so the one real backend role that holds neither `expenses.view` nor `treasury.view` (`technician`) is untested.**
`purchases` exists only in `src/hooks/uiAliasPermissions.ts:6` (`purchases.view`), not in `permissionsMap.generated.ts`; the tests work (and the Purchases group renders) only because of that alias. For T15's "holds neither" criterion the meaningful subject is `technician` — absent from `expenses.view` (`permissionsMap.generated.ts:81`) and from `treasury.view` (`:260`).
*Failure scenario:* a future regeneration that widens `expenses.view`/`treasury.view` across backend roles leaves both negative tests green, because their subject role is invisible to the generated map entirely.
*Fix directive:* add (or switch to) `roles: ['technician']` in the T15 negative case and a real backend role (e.g. `cashier`) in the T4 negative case; keep the alias-role case as the group-visibility control.

**4. P3 — CONFIRMED — `apps/web/src/hooks/uiAliasPermissions.ts:1-20` — the alias layer still grants permissions by ROLE NAME, a parallel path T4's fail-closed contract does not cover. REPORT-ONLY, pre-existing.**
`hasPermission` (`usePermissions.ts:115-132`) falls back to `UI_ALIAS_PERMISSIONS`, whose allow-lists contain role names (`'sales'`, `'purchases'`, `'inventory'`, `'treasury'`, `'user'`) that do not exist in the generated backend map. T4 correctly closes the **module-key** space, but a mistyped or legacy role name still grants through the alias table — the "role-name heuristic standing in for a real permission" pattern.
*Failure scenario:* a tenant role named `treasury` obtains `purchases.view`-class UI access with no backend permission backing it.
*Fix directive:* ticket to reconcile `uiAliasPermissions.ts` against `permissionsMap.generated.ts` in a later wave; explicitly out of M5 scope.

**No P1 findings.**

---

## Bypasses attempted that FAILED

- **A dropped/edited valid key hidden in the `as const` rewrite.** Extracted the key sets from `acf5dc6bd` and `HEAD` and diffed them: 42 → 44 keys, delta is exactly `goods-receipt.create-standalone` and `inventory.view`, **zero removals**; the commit diff shows no value line changed. No key was smuggled out under cover of the declaration rewrite.
- **A call surface still typed `string`.** Full grep census of `canAccessModule`, `moduleKey?:`, `permissionModule?:` and nav `permission?:` across `apps/web/src`. Only test-local mock props remain. Typecheck green confirms every literal at every surface is in the union.
- **A weakened Sidebar harness.** The test-file diff is confined to the import header, the mock block, and two appended `describe` blocks — no pre-existing assertion, role stub, or `mockCanAccessModule` expectation was edited (hunks `@@ -1,6 @@`, `@@ -14,13 @@`, `@@ -658,4 @@`, `@@ -719,5 @@`). The new mock spreads the **real** hook and only swaps `canAccessModule` behind `useRealModuleAccess`, which the 41 legacy tests never set — strictly more fidelity, not less; `resetAuth()` in `afterEach` prevents store bleed.
- **Manifest absorption / `--write-baseline`-style evasion.** No baseline file is in the range stat; the manifest delta over the whole range is the two `/crm/companies` lines; drift check exits 0 at the tip (I ran it); exactly two manifest commits on the branch. Nothing was absorbed and no detector was defeated by indirection.
- **Suppression sweep.** `git diff acf5dc6bd..HEAD -- apps/web | grep '^+.*(eslint-disable|@ts-ignore|@ts-expect-error|as unknown as)'` → no production hits; the only casts are the two documented `as ModuleKey` casts inside the new tests, which are required to reach the runtime fail-closed branch.
- **Independent red-run replay.** Blocked by the read-only mandate (`git stash` is repo-global and forbidden; `git worktree add` mutates repo state). **Substituted** a non-mutating equivalent: verified the pre-change implementation and both invalid key sites directly at `acf5dc6bd`, which makes every new assertion's pre-change failure a matter of construction rather than of the executor's report.

---

## Disposition

Both tasks land exactly as briefed and the fail-closed ruling is enforced at the type layer and the runtime layer simultaneously. All four T4 rows are resolved as tabulated, the six call surfaces are typed, no valid module key was lost, the two narrowed nav items now match their own route guards, T15 is a genuine one-line parity fix with the treasury siblings untouched, and the authorised manifests-only sync is minimal, correct and drift-0 with exactly two manifest commits on the branch. Every guardrail claim was re-run by me rather than accepted. The four findings are one report-only both-layers gap that the brief's wording understates (P2), one YAML count inaccuracy, and two test-fidelity/carry-forward notes — none of which impeach M5's deliverables.

VERDICT: ACCEPT
