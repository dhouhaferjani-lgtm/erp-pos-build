# M5 Adversarial Bridge Review — Round 1 — lens: `tenancy-authz`

**Scope:** `M5 = T4 (UI-01/UI-02) + T15 (UI-07) + one authorised manifests-only sync`
**Contract:** `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:251-330` (T4), `:603-636` (T15); `docs/architecture/vertical-module-gating.md`; CLAUDE rule 12.
**Range reviewed:** `acf5dc6bd..0937aabc5` (4 commits: `77257bf88` T4, `3608a8b1d` T15, `a6dc879b8` manifest sync, `0937aabc5` evidence)
**Branch/tip verified at review start:** `codex/ui-wave0-2026-08-11` @ `0937aabc5`, working tree clean.
**Lens:** authorization semantics only. Frontend conventions and general quality are other reviewers' lenses.

---

## 1. What this diff does and does not touch (scoping the lens)

Verified by `git diff --stat acf5dc6bd..0937aabc5`: **12 files, all under `apps/web/src`, `docs/handoff/`, and `scripts/factory/manifests/`.**

- **Zero backend files.** No route middleware, no `can:` guard, no permission catalog change.
- **Zero new permissions.** Therefore **no `RolesAndPermissionsSeeder.php` change is owed and no tenant seeder re-sync is required by this diff.** All four permissions it leans on already exist and are already role-assigned: `partners.view` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:52`, granted `:535,:640,:676,:716,:741,:775`), `goods-receipt.create-standalone` (`:142`, granted `:543`), `inventory.view` (`:171`, granted `:549,:651,:690,:720,:755`), `expenses.view` (`:203`, granted `:554,:647,:686,:751`).
- **Zero tenancy-boundary surface.** No `->connection('central')`, no queued job, no model, no query. The db-per-tenant checklist (central-vs-tenant connection pinning, `SetPermissionsTeam`, `CurrencyScaleResolverInterface` in queue context, `latestOfMany` on UUID PKs, `Str::isUuid` guards) is **not applicable** — I confirmed the diff contains no PHP at all.
- Rule 19 (money/quantity precision): **not applicable** — no money or quantity code in range.

The lens therefore reduces to: *does the fail-closed flip, and the two gate repairs, cost any legitimate principal access, or grant any principal access it should not have?*

---

## 2. T4 — the invalid-key census, reproduced independently

The brief (`:283-295`) claims exactly **4 distinct invalid keys across 5 literal occurrences**, and that a sixth diagnostic is a STOP. I did **not** take the executor's word or the compiler's word for this. I re-derived the census mechanically from the **base commit's** file contents, extracted to a scratch dir, by enumerating every literal reaching each of the six call surfaces and subtracting the base map's key set:

```
BASE map key count: 42
INVALID KEYS (base):
  goods-receipt.create-standalone  ->  Sidebar.tsx:182
  inventory.view                   ->  Sidebar.tsx:215
  parts_catalog                    ->  routes/index.tsx:1428, routes/index.tsx:1440
  partners                         ->  routes/index.tsx:2747
distinct invalid keys: 4   total literal occurrences: 5
```

This matches the brief exactly (42 keys, 4 keys, 5 occurrences) and matches the executor's reported line numbers `1428/1440/2747` — which differ from the brief's `1431/1443/2777` only by the M3 deletions' line shift, as the executor stated. **There is no sixth site.** Census independently CONFIRMED.

**Post-change closure, verified two ways:**
1. **Exhaustive grep at tip.** Every `moduleKey=`/`moduleKey:` literal (`routes/index.tsx` — inventory, vehicles, services, treasury, reports, settings, pos, contacts, sales, purchases; `useCommandPalette.ts:61-86`), every `permissionModule:` literal (`PosHubPage.tsx:34-62`, `InventoryHubPage.tsx:38-121`), every Sidebar nav `permission:` literal (40 distinct values), and both direct callers (`PosRefundPoliciesPage.tsx:130` → `'settings'`, `CashPositionWidget.tsx:128` → `'treasury'`) are present in `MODULE_PERMISSIONS` (`usePermissions.ts:29-86`).
2. **`pnpm typecheck` in `apps/web` is clean** (I ran it; `tsc --noEmit`, no output). Since `canAccessModule(moduleKey: ModuleKey)` (`usePermissions.ts:152`) now takes a literal union, a green typecheck is machine proof that no production call site passes an unmapped key. The only `as ModuleKey` casts in the repo are in the two new test files, deliberately, to reach the runtime branch.

**Conclusion: no legitimate key was missed. No live feature is silently hidden from every role by the fail-closed flip.**

---

## 3. The decisive check — nav↔route role parity, before vs after, for all seven real roles

The real risk of a fail-open→fail-closed flip is not a compile error; it is a **live feature going dark for a role that can legitimately use the route**. Grep cannot answer that. So I modelled it.

**Role universe (verified, not assumed).** `apps/web/src/hooks/permissionsMap.generated.ts` yields exactly seven roles: `accountant, admin, cashier, manager, operator, technician, viewer`. Note that `apps/web/src/hooks/uiAliasPermissions.ts:4-13` references `sales`, `purchases`, `inventory`, `treasury`, `user` — **these are UI-alias fictions, not backend roles**, and cannot appear in a real tenant's `user.roles`. This matters for §6 P3-1.

I built a sweep that parses the 78 Sidebar nav children, resolves each `href` against the generated route manifest, expands both sides to role sets through `MODULE_PERMISSIONS` + `PERMISSIONS` + `UI_ALIAS_PERMISSIONS`, and — critically — **runs twice: once over the base commit's files under fail-OPEN semantics, once over the tip under fail-CLOSED semantics** — then diffs the two. Output:

```
NAV VISIBILITY CHANGED /purchases/receipts/new
   before:[accountant|admin|cashier|manager|operator|technician|viewer] after:[admin|manager]
   LOST:[accountant,cashier,operator,technician,viewer] GAINED:[] routeAllows:[admin|manager]
NAV VISIBILITY CHANGED /inventory/stock-by-location
   before:[accountant|admin|cashier|manager|operator|technician|viewer] after:[admin|cashier|manager|operator|technician|viewer]
   LOST:[accountant] GAINED:[] routeAllows:[admin|cashier|manager|operator|technician|viewer]
NAV VISIBILITY CHANGED /expenses
   before:[accountant|admin|manager] after:[accountant|admin|cashier|manager|operator|viewer]
   LOST:[] GAINED:[cashier,operator,viewer] routeAllows:[accountant|admin|cashier|manager|operator|viewer]
changed nav items: 3
```

Reading this against the two failure modes I was hunting:

- **BLACKOUT** (a role that *can* use the route loses the nav entry): **ZERO.** In both LOST cases, every lost role is absent from `routeAllows` — i.e. each lost role was *already* being bounced by the route guard and was seeing a nav item that could only lead to `/dashboard`. `/purchases/receipts/new` route = `goods-receipt.create-standalone` (manifest `:666-669`) = admin/manager, exactly the surviving nav set. `/inventory/stock-by-location` route = `inventory.view` (manifest `:414-417`) — accountant lacks it, and accountant is exactly the one role dropped.
- **GHOST NAV** (a role newly sees an item whose route denies it): **ZERO.** T15's three gained roles (cashier, operator, viewer) all hold `expenses.view`, which is precisely the `/expenses` route guard (`routes/index.tsx:1677`, manifest `:162-165`).
- **All three items land at exact nav↔route parity.** `/expenses` no longer appears in the mismatch set at all after the change.

**Every other nav item in the sidebar is byte-identical in visibility.** The fail-closed flip changed the effective role sets of exactly three nav entries, and all three moved *toward* the route's own truth. This is the strongest evidence I can produce for T4/T15 and it is the core of my ACCEPT.

T15's explicit acceptance criterion "a role holding neither still does not see it — the fix must not widen the gate to everyone" (`:626`) is satisfied: `technician` holds neither `expenses.view` nor `treasury.view` and is absent from the after-set.

---

## 4. Row-by-row verification of the four resolutions

| Row | Contract requirement (`:279-282`) | Verified |
|---|---|---|
| 1 `goods-receipt.create-standalone` | self-map, tightens nav for non-admin/manager | ✅ `usePermissions.ts:83`. Backend permission real (`permissionsMap.generated.ts:89` → `['admin','manager']`; seeder `:142`). Narrowing is toward route truth (§3). |
| 2 `inventory.view` | self-map | ✅ `usePermissions.ts:85`; `permissionsMap.generated.ts:118`; seeder `:171`. |
| 3 `parts_catalog` ×2 | delete the `RequirePermission` wrapper, **keep** `ModuleGuard` | ✅ `routes/index.tsx:1423-1451`: both routes retain `<ModuleGuard module="PlatformIntegration">`. **I checked the base commit for a second gate that the deletion might have taken with it: `git show acf5dc6bd:apps/web/src/routes/index.tsx` lines 1423-1447 show the removed wrappers carried `moduleKey="parts_catalog"` and NOTHING else — no `permission`, no `permissions`.** Nothing that mattered was removed. I also confirmed `parts_catalog` exists nowhere in `RolesAndPermissionsSeeder.php` or `apps/api/routes/` — the key was genuinely dead, so under fail-closed it would have locked out every role. See §6 P2-1 for the residual. |
| 4 `partners` → `permission="partners.view"` | replace, do **not** delete the route | ✅ `routes/index.tsx:2745-2757`; route intact. |

**Row 4, the stranding question I was asked to answer.** `partners.view` (`permissionsMap.generated.ts:149`) is held by **all seven real roles**. The pre-change gate was `moduleKey="partners"` → unmapped → fail-OPEN → also all roles. **The audience is therefore not narrowed by a single role, and no role is stranded.** I specifically checked the inverse: is there a role holding the redirect target's permission but not `partners.view`? `CompanyListPage.tsx:1-11` is confirmed a pure redirect to `/sales/customers`, whose guard is `moduleKey="sales"` → `sales.view` → `['admin','sales','manager']` → real roles `{admin, manager}`. Both hold `partners.view`. **No mismatch.** (The reverse direction — cashier passes `partners.view`, is redirected, then bounced from `/sales/customers` to `/dashboard` — is real but **pre-existing and bit-for-bit unchanged** by this diff, since the old gate admitted cashier too. Recorded as P3-2; UI-35/Wave 4 owns it.)

**Module gating vs the SoT.** `ModuleGuard module="PlatformIntegration"` is legitimate: `PlatformIntegration` is a declared `default_modules` entry for all six Otospex verticals (`apps/api/config/verticals.php` — mechanic, body_shop, parts_retailer, car_glass, tire_shop, service_station) and appears in no IziPOS vertical. Gating parts-catalog on it is correct and vertical-consistent.

**Cross-vertical fail-closed safety.** I checked that no key valid in one vertical fails closed in the other: `MODULE_PERMISSIONS` is a role→permission map with **no vertical dimension at all**, and vertical gating runs on the orthogonal `hasModule()` axis (`Sidebar.tsx:427-431`, `ModuleGuard`). `Treasury` — the module gating the `/expenses` nav group — is a `default_modules` entry in **all twelve** verticals in `config/verticals.php`, so T15's fix is not silently dead in any vertical. No IziPOS/Otospex asymmetry is introduced.

---

## 5. Manifest sync

`bash scripts/factory/check-manifest-drift.sh` → **I ran it: exit 0, no diff output.** The regenerated `/crm/companies` row (`routes-web.yaml:130-133`: `module_gate: null`, `permission: partners.view`) matches the route code at `routes/index.tsx:2745-2757`. The parts-catalog rows are correctly unchanged (`:486-493`, `module_gate: PlatformIntegration`) because `ModuleGuard` outranks `moduleKey` in the generator (`gen-route-manifest.mjs:12-13`), so deleting the dead wrapper produced no delta — consistent with the parent ruling's "any other entry changing in the regen is a STOP". The diff to the manifest is exactly two lines. Ruling honoured.

---

## 6. Findings

### P1 — none.

No cross-tenant leak, no auth bypass, no privilege escalation, no silent 403 on a prod path, no feature blackout. The three findings below are P2/P3 and none blocks M5.

---

**[P2-1] CONFIRMED — `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:24-95` — the parts-catalog API has no module gate and no permission gate; after row 3 the feature's only role gating anywhere is now nothing at all.**

The group is `Route::prefix('api/v1/platform')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])` — rule-12 compliant on the middleware pattern, but I read every route in the group (`:26-92`) and **not one carries `can:` and the group carries no `module:PlatformIntegration`**. So any authenticated user of any role, in a tenant with or without the `PlatformIntegration` module, can call the entire catalog-browse surface.

*Why it matters.* CLAUDE rule 12 requires vertical-exclusive routes to be gated on **both** layers. `PlatformIntegration` is Otospex-exclusive per `config/verticals.php`, and it is gated on the frontend only (`ModuleGuard`, client-side, trivially bypassable) — the backend `RequireModule` half is missing. Separately, `/parts-catalog` now has **zero** role gating on either layer.

*Disposition — not an executor defect and not an M5 blocker.* The contract explicitly mandated the deletion (`:281`), the wrapper it deleted was already fail-open at runtime, and the backend gap predates the branch entirely (no backend file is in range). Effective posture is unchanged. But the diff removes the last textual marker of a role gate on that page, so the next reader will reasonably assume `ModuleGuard` is sufficient. *Fix (separate ticket, backend):* add `module:PlatformIntegration` to the group and a `can:` guard on the catalog reads.

---

**[P3-1] CONFIRMED — `Sidebar.test.tsx:695,747` and `usePermissions.moduleAccess.test.tsx:69` — every DENY-path assertion uses `roles: ['purchases']`, which is not a role that can exist in a tenant.**

`purchases` appears only in `uiAliasPermissions.ts:8` (a UI grouping fiction whose own header says "NOT backend authorization"). The seven real roles come from `RolesAndPermissionsSeeder.php` via `permissionsMap.generated.ts`: accountant, admin, cashier, manager, operator, technician, viewer. So the three deny assertions prove the gate denies a **principal that never occurs in production**, which is close to tautological — a fictitious role holds almost nothing by construction. The ALLOW paths are fine (they use real `manager` and `cashier`).

I understand why: `technician` (the real role holding neither `expenses.view` nor `treasury.view`) also lacks `purchases.view`, so the Purchases group would not render and the positive control would collapse. That is a legitimate harness constraint, not laziness. But the consequence stands: **the suite does not lock in the real-role matrix.** I verified that matrix myself in §3, so this is a durability gap, not a correctness risk. *Fix:* use a real role for at least one deny case (e.g. `cashier` for `goods-receipt.create-standalone`, which is a real production narrowing), or assert on `MODULE_PERMISSIONS`-derived role sets directly.

---

**[P3-2] CONFIRMED, pre-existing and unchanged — `Sidebar.tsx:262` vs `routes/index.tsx:2745` vs `CompanyListPage.tsx:1-11` — the `/crm/companies` nav item leads a cashier to `/dashboard`.**

Nav gate `permission: 'contacts'` → `contacts.view` = {admin, cashier, manager}; the route now admits all seven; the page redirects to `/sales/customers` whose guard admits {admin, manager}. A cashier clicking "Companies" is silently navigated to `/dashboard` (`RequirePermission.tsx:75-78` redirects rather than rendering `PermissionDenied` for any path other than `/dashboard`). Identical before and after this diff. UI-35/Wave 4 owns the dedup; recorded so the parent does not mistake it for M5 fallout.

---

**[P3-3] CONFIRMED — no automated nav↔route parity guard exists; UI-01 and UI-07 are the same defect class and will recur.**

Both findings this milestone fixes are instances of "a nav item's role gate disagrees with its route's role gate". The repo has drift guards for the manifest (`check-manifest-drift.sh`), the permission map (`preflight.sh:147-152`, `permission-map-drift-guard.test.mjs`), TanStack keys, and quantity display — but nothing compares Sidebar gates to route gates. The ~20-line sweep I wrote for §3 does exactly this against the already-committed manifest and would have caught both. *Recommendation for the parent:* consider it as a Wave-4 hardening ticket. Not in M5 scope (rule 4).

---

**[P3-4] CONFIRMED, by design and not introduced here — `usePermissions.ts:115-133` — these gates are advisory; a negative server answer is ignored for all four permissions in play.**

`hasPermission` returns true on a server grant, returns false only if the permission is in the `SERVER_AUTHORITATIVE_PERMISSIONS` allowlist (`:10-18`), and otherwise **falls through to the build-time static role map**. None of `partners.view`, `goods-receipt.create-standalone`, `inventory.view`, `expenses.view` is in that allowlist. So for a tenant whose DB role grants diverge from the shipped snapshot (`// Source hash: sha256:73a4c4aa…`), the UI can show and the route can open while the API 403s. Mitigations already exist and I verified them: all four permissions are seeded and role-assigned (§1), and a CI/preflight drift guard keeps the snapshot honest. Flagged only because it bounds what T4/T15 can be claimed to enforce: **the backend `can:` guards remain the real authorization, and this diff neither weakens nor strengthens them.**

---

**[P3-5] CONFIRMED — `usePermissions.ts:86` — the `satisfies` guard is one-directional.**

`as const satisfies Record<string, readonly Permission[]>` constrains **values** to real permissions but leaves the **key** space free-form. A typo'd map key (`'expensez': ['expenses.view']`) still compiles and immediately becomes a valid `ModuleKey`, so call sites using it also compile. The union protects call sites from keys absent from the map — which is what T4 asked for and delivers — but it cannot protect the map from a bogus key. Worth a one-line comment so a future maintainer does not over-trust the guard.

---

## 7. Bypasses attempted (and their results)

| Attack on the change | Result |
|---|---|
| Find a module key reachable at runtime but absent from the map (would now deny a live feature) | **Failed** — exhaustive grep over all six surfaces + green `tsc --noEmit`; only casts are in tests |
| Find a role that loses a nav item it can actually use (blackout) | **Failed** — before/after sweep over all 78 nav items × 7 roles: zero blackouts |
| Find a role that newly sees an item its route denies (ghost nav / silent bounce) | **Failed** — zero; all three changed items land at exact parity |
| Find a permission removed with the deleted `parts_catalog` wrapper | **Failed** — base commit shows `moduleKey` was the wrapper's only prop |
| Find a role holding the `/crm/companies` redirect target but not `partners.view` (stranding) | **Failed** — `partners.view` covers all seven real roles; target covers {admin, manager} ⊂ that |
| Find a vertical where a mapped key fails closed (IziPOS vs Otospex) | **Failed** — the map has no vertical dimension; `Treasury` is a default module in all 12 verticals |
| Find a new `can:`/permission needing a seeder entry (silent-403 trap) | **Failed** — the diff adds no backend guard and no permission; all four exist and are role-assigned |
| Find a hidden manifest delta smuggled into the sync commit | **Failed** — drift check exits 0; diff is two lines on `/crm/companies` |
| Find a tenancy-boundary violation (central vs tenant connection, queue context) | **N/A** — zero PHP in range |

---

## 8. Tests

Ran by path, `--maxWorkers=1`, never the full suite:

```
✓ src/features/auth/components/__tests__/RequirePermission.moduleKey.test.tsx (3 tests)
✓ src/hooks/__tests__/usePermissions.moduleAccess.test.tsx (6 tests)
✓ src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx (45 tests)
Test Files 3 passed (3) | Tests 54 passed (54)
```

Quality: the tests assert real behaviour against the **real** `canAccessModule` and the real auth store (`Sidebar.test.tsx:24-41` delegates to `importOriginal` behind a flag scoped to the new describe block, leaving the 43 pre-existing tests on the stub). DENY paths are exercised, not just ALLOW, and both deny assertions carry an explicit positive control proving the sidebar rendered. No `assertTrue(true)`, no faked payloads, no mocking of the unit under test. The one weakness is the fictitious deny-path role (P3-1).

---

## 9. Disposition

Accept. The lens question was whether flipping `canAccessModule` from fail-open to fail-closed costs any legitimate principal access, and the answer is a verified no: I re-derived the invalid-key census from the base commit independently (4 keys / 5 occurrences / 42 map keys — matching the brief exactly, with no sixth site), confirmed closure by exhaustive grep plus a green `tsc --noEmit` over the new literal union, and then ran a before/after nav↔route parity sweep across all 78 sidebar entries and all seven real roles under each commit's own fail-open/fail-closed semantics. Exactly three nav items change visibility, and all three move toward their route's own gate: zero blackouts, zero ghost-nav, exact parity afterwards. Row 3's deleted wrapper carried no permission beyond the dead key and `ModuleGuard module="PlatformIntegration"` survives on both routes; row 4's `partners.view` covers all seven real roles and strands nobody, the redirect target's audience being a strict subset. The diff contains no PHP, no new `can:` guard and no new permission, so no seeder work is owed and the db-per-tenant checklist is not engaged. The manifest sync is two lines and drift exits 0. The one substantive residual is P2-1 — the parts-catalog backend has neither a module gate nor a permission gate, a genuine rule-12 both-layer gap that this diff makes textually invisible without changing effective posture — which is pre-existing, out of M5's scope, and belongs in a backend ticket rather than blocking this milestone.

**What to fix before merge:** nothing in M5; carry P2-1 (backend `module:PlatformIntegration` + `can:` on the platform catalog routes) into a Wave-4/backend ticket and, if cheap, swap one deny-path test role to a real one (P3-1).

VERDICT: ACCEPT
