# Frontend Navigation — Sidebar Gating and Route Reconciliation

**Document Version:** 2.0
**Last Updated:** 2026-08-19
**Scope:** how `Sidebar.tsx` decides what a user sees, and how that set reconciles with the generated route manifest.

> **What changed in 2.0 — read this before trusting anything you remember from 1.0.**
> Version 1.0 (2026-01-02) described the sidebar as filtering through a
> `MODULE_NAME_MAP` lookup table that mapped lowercase sidebar keys onto
> PascalCase backend module names, with everything absent from the map treated
> as an always-visible "core module". **That mechanism no longer exists** —
> `MODULE_NAME_MAP` is not present anywhere in `apps/web/src`. Gating is now
> declared per nav item and is **fail-closed on both axes**. Every 1.0 section
> that rested on the map (the module-mapping how-to, the troubleshooting
> recipes, the worked scenarios) has been rewritten against the code.
> Claims from 1.0 that could not be verified against source were **deleted
> rather than carried forward**: the microbenchmark "Performance Metrics", the
> 2026-01-02 "Quality Metrics" scorecard, the fabricated 16-test expected
> output, and the per-vertical "Visible Sidebar Items" checklists (which listed
> a `Finance` group and `Vehicles`/`Services` top-level entries that do not
> match the current navigation tree).

---

## Overview

The sidebar shows a user only the nav items their tenant's enabled modules
**and** their role both allow. Two independent gates are applied to every item.

**The sidebar is not a security boundary.** It decides *visibility*. Access is
enforced on the route (`ModuleGuard` + `RequirePermission`) and again on the
API (`module:<Name>` middleware + permission checks). A nav item that is
correctly hidden but whose route is unguarded is still a bug — see
[vertical-module-gating.md](vertical-module-gating.md) for the both-layers rule.

---

## Architecture

### The two-axis gate

**File:** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

```typescript
const isNavItemVisible = useCallback(
  (module?: BackendModule | BackendModule[], permission?: ModuleKey): boolean => {
    if (module !== undefined) {
      const names = Array.isArray(module) ? module : [module]
      if (!names.some((name) => hasModule(name))) {
        return false
      }
    }
    if (permission !== undefined && !canAccessModule(permission)) {
      return false
    }
    return true
  },
  [hasModule, canAccessModule]
)
```

- **Module axis.** If the item declares `module`, at least ONE of the listed
  backend modules must be in the tenant's `all_enabled_modules`. There is no
  fallback to "visible".
- **Permission axis.** If the item declares `permission`, the user's role must
  grant that `MODULE_PERMISSIONS` key. `canAccessModule` returns `false` for an
  unrecognised key — it fails closed, and `ModuleKey` is a typed union so an
  unknown key is a compile error, not a silent open gate.
- **Omitting an axis skips only that axis.** An item with no `module` is not
  vertical-gated; an item with no `permission` is not role-gated at the nav
  level. Neither omission is a claim about the route, which guards separately.

### Filtering flow

```
buildNavigation(isAutomotiveVertical)   →  the nav tree for this vertical
        │
        ▼
filter top-level groups     isNavItemVisible(group.module, group.permission)
        │
        ▼
filter each group's children  isNavItemVisible(child.module, child.permission)
        │
        ▼
drop groups whose children all filtered away  (children?.length === 0)
        │
        ▼
split by section            main  |  bottom
```

The last step matters: a group that survives its own gate but has every child
gated away is removed, so the sidebar never renders an expandable group that
opens onto nothing.

### How an item declares its gates

```typescript
interface NavChild {
  key: string
  href: string
  icon: React.ComponentType<{ className?: string }>
  labelKey?: string
  module?: BackendModule | BackendModule[]
  permission?: ModuleKey
}

interface NavModule {
  key: string
  icon: React.ComponentType<{ className?: string }>
  href?: string
  labelKey?: string
  children?: NavChild[]
  module?: BackendModule | BackendModule[]
  permission?: ModuleKey
  section?: 'main' | 'bottom'
}
```

`BackendModule` and `ModuleKey` are both typed unions, so a typo in either a
module name or a permission key fails `pnpm typecheck` rather than silently
disabling (or opening) the gate.

---

## Current top-level navigation

Fifteen top-level groups, in render order. `module` = the vertical axis;
`permission` = the role axis; blank = that axis is not applied at this level
(children may still declare their own).

| Key | `module` | `permission` | Notes |
|---|---|---|---|
| `dashboard` | — | `dashboard` | leaf |
| `sales` | `Sales` | `sales` | |
| `purchases` | — | `purchases` | |
| `catalog` | `Catalog` | `inventory` | |
| `inventory` | `Inventory` | `inventory` | |
| `pointOfSale` | — | `pos` | |
| `ecommerce` | `Ecommerce` | `inventory` | |
| `customersAndMarketing` | — | — | group gated only by its children |
| `bankingAndPayments` | `Treasury` | — | children carry the role gates |
| `accountingAndReports` | `Accounting` | `accounts` | |
| `automotive` | `Vehicle` \| `Workshop` \| `PlatformIntegration` | — | any-of; the array form |
| `parapharmacy` | `Parapharmacy` | — | |
| `reports` | — | `ownerReports` | leaf |
| `supportAccess` | — | `support-access` | `section: 'bottom'` |
| `settings` | — | `settings` | `section: 'bottom'` |

Vertical-specific children are declared at child level, not by a lookup table.
Examples that exist today: `batches` and `expiryWriteOff` under `BatchExpiry`;
`tables` under `Tables`; `kitchen` under `Menu`; `compositeItems`, `menus` and
`modifierGroups` under `['Menu', 'CompositeItems']`; `loyaltyPrograms` and
`loyaltyMembers` under `Loyalty`.

*(Version 1.0 listed `menu`, `tables` and `batch_expiry` as "future
vertical-specific modules not yet in sidebar". All three are in the sidebar
now; that claim is deleted.)*

---

## Vertical-adaptive structure and labels

Two things adapt to the company vertical, and neither is the module gate.

**1. Where the Services children are mounted.** `buildNavigation` takes an
`isAutomotiveVertical` flag, derived from `AUTOMOTIVE_VERTICALS.has(config?.vertical ?? '')`.
For automotive verticals the Services children (`allServices`,
`serviceCategories`) sit under the `automotive` group; for every other vertical
they sit under the catalog/inventory group. The `automotive` group itself is
then hidden downstream by its own module gate, because none of `Vehicle`,
`Workshop` or `PlatformIntegration` will be enabled.

**2. The label some items render.** `VERTICAL_NAV_KEYS` maps a nav key to a
vertical-flavoured label key; when the catalog vertical is not `generic`, that
item's label resolves through `catalog:vertical.<vertical>.<key>` instead of
the default `navigation.<key>`. It currently covers `compositeItems` and
`modifierGroups` only. The top-level `catalog` group deliberately does **not**
adapt — it is the whole what-you-sell group, not the composite-items entry.

Label resolution order, from `getNavLabel`:

1. an explicit `labelKey` on the item, if present;
2. the vertical-flavoured `catalog:vertical.*` key, if the item is in
   `VERTICAL_NAV_KEYS` and the catalog vertical is not `generic`;
3. `navigation.<key>` in the `common` namespace.

---

## Loading and error states

`hasModule` is derived from the company-config query. Before that query
resolves, and if it errors, the module list is empty, so:

- every item that declares `module` is **hidden** (fail-closed, by design);
- every item that declares no `module` is unaffected by this axis.

This produces a brief window where module-gated groups are absent and then
appear. That is the deliberate trade: a fail-closed gate cannot also be
flicker-free. Do not "fix" it by defaulting `hasModule` to `true`.

`hasModule` also defensively normalises its input: the API returns
`all_enabled_modules` as an array, but a PHP associative array with
non-sequential keys can serialize as an object, so the context coerces
object-shaped payloads via `Object.values` before the membership test. Without
that, a shape change would throw inside a `useMemo` and white-screen the app.

---

## Adding a vertical-gated nav item

### 1. Enable the module for the vertical (backend)

**File:** `apps/api/config/verticals.php` — the source of truth for which
modules a vertical gets. See [vertical-module-gating.md](vertical-module-gating.md).

### 2. Declare the nav item with its gates

**File:** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

```typescript
{
  key: 'batches',
  href: '/inventory/batches',
  icon: Pill,
  module: 'BatchExpiry',        // vertical axis — any-of if you pass an array
  permission: 'inventory',      // role axis — omit if the nav level is ungated
}
```

There is **no third "add it to the map" step**. The gate is the declaration.

### 3. Guard the route as well

**File:** `apps/web/src/routes/index.tsx`

```tsx
<ModuleGuard module="BatchExpiry">
  <RequirePermission moduleKey="inventory">
    <BatchListPage />
  </RequirePermission>
</ModuleGuard>
```

Hiding the sidebar entry is not access control. `ModuleGuard` redirects to
`/dashboard` (override with `fallback`) when the module is absent.

### 4. Add the label

**File:** `apps/web/src/locales/{en,fr,ar}/common.json`, under
`navigation.<key>` — unless the item carries an explicit `labelKey`.

### 5. Add tests

**File:** `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`

Cover both axes and both directions: visible when the module is enabled and the
role grants the permission; hidden when the module is absent; hidden when the
role lacks the permission. A test that only proves the positive case cannot
detect a fail-open regression.

Note the harness: most of this suite stubs `usePermissions` wholesale, which
cannot express role-level gating. Tests that need real role behaviour delegate
to the actual hook behind the suite's `useRealModuleAccess` flag. If you are
asserting role gating, use that path — the stub will pass regardless.

---

## Testing

```bash
cd apps/web && pnpm vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx
```

The suite currently holds **45** tests across 15 `describe` blocks, including
`Fail-Closed Module Gating (production hardening)` and
`Role gating via the real canAccessModule (T4)`.

*(1.0 quoted "16 tests" and pasted a verbatim expected-output block naming
individual test titles. Both were stale and the pasted block is deleted —
a hardcoded list of test names in prose is a maintenance trap. Run the command
for the current list.)*

---

## Troubleshooting

### A module-gated item is not showing for a tenant that should have it

1. Confirm the module is actually enabled — inspect `all_enabled_modules` on the
   company-config response. The name must match **exactly**; the gate is
   `modules.includes(name)`, case-sensitive.
2. Confirm the item's `module` value spells the backend module name. It is
   typed, so a wrong-but-valid module name compiles; a nonexistent one does not.
3. If the item declares `permission`, confirm the role grants it — the module
   axis passing is not enough.
4. If it is a **child**, confirm its parent group also passes. A hidden parent
   hides the child regardless of the child's own gates.

### An ungated item disappeared

Check whether it is the only surviving child of its group: a group whose
children are all filtered away is dropped entirely.

### The item shows but the API returns 403

The frontend gates passed and the backend rejected. That is the layers
disagreeing, and the backend is right. Check that the route's `module:<Name>`
middleware and the sidebar's `module` value name the same module, and that the
role's permissions match the nav item's `permission` key.

---

## Route reconciliation (Wave 0)

The generated route manifest is the mechanical inventory of what routes exist;
the sidebar is the inventory of what is *reachable by clicking*. Divergence
between them is how orphaned pages happen.

```bash
node scripts/factory/gen-route-manifest.mjs   # regenerate
bash scripts/factory/check-manifest-drift.sh  # CI guard — must exit 0
```

**Manifests:** `scripts/factory/manifests/routes-web.yaml` (265 route records)
and `routes-pos.yaml` (9). The manifest records each route's `component`,
`module_gate` (the `ModuleGuard` module, which wins over a `RequirePermission`
`moduleKey`) and `permission`.

**Deleted in Wave 0** on owner rulings, and absent from the regenerated
manifest — do not re-add them, and do not treat any of them as a missing entry:

| Route | Disposition |
|---|---|
| `/pos/shifts` | deleted; `/pos/shift-history` is canonical |
| `/marketing` | deleted as a pure duplicate of the `customersAndMarketing` sidebar group |
| `/finance` (index only) | deleted **outright, no redirect** — the `finance` parent and every `/finance/*` child remain |
| `/settings/chart-of-accounts` | duplicate mount removed; `/finance/chart-of-accounts` is canonical |

A typed or bookmarked `/finance` or `/marketing` now falls through the `path="*"`
catch-all to `/dashboard`. That consequence was accepted by the ruling.

### Orphan candidates that remain

The component-graph-aware re-census
([`17-listing-census-recensus.md`](../sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md),
finding `CX-4`) derives reachability from the route tree plus navigation
references in production source. At the wave's pinned base
`d682b38ec9761a917b9716428091a482745795f6` it left 22 candidates after manual
call-flow review. Four of them are the Wave 0 deletions above. Still present in
the manifest and still without an inbound UI reference:

- `/growth`, `/growth/modules`
- `/scheduling/capacity`
- `/treasury/payment-methods`, `/treasury/sales-withholding-tracking`
- `/inventory/delivery-notes/consolidate` (`CX-1`)
- `/finance/lane-separation` (claimed by the DN-consolidation lane)
- the four `/settings/compliance/*` pages (`UI-09` cluster)

These are **candidates, not rulings**. Regenerate before acting on the list —
the report's method section documents its own limits (it does not resolve
object-map element access or paths returned from local pure functions, so three
action/form routes remain unresolved rather than confirmed orphaned).

There is still **no automated route↔nav coverage check** (`UI-39`). Nothing in
CI would catch the next orphan, so this reconciliation is manual and must be
redone when routes change.

---

## Related documentation

- [`vertical-module-gating.md`](vertical-module-gating.md) — the vertical/module model and the both-layers gating rule; `config/verticals.php` is the source of truth
- [`frontend-contexts.md`](frontend-contexts.md) — `CompanyConfigContext` and `ProductConfigContext`
- [`frontend-security.md`](frontend-security.md) — route guards
- [`security.md`](security.md) — backend `RequireModule` middleware
- [`../api/company-config.md`](../api/company-config.md) — the company-config API
- [`../conventions/02-NAVIGATION-ROUTING.md`](../conventions/02-NAVIGATION-ROUTING.md) — adding a page to the dashboard
- [`../conventions/03-AUTHORIZATION.md`](../conventions/03-AUTHORIZATION.md) — the permission system
- **Audit of record:** [`../sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md`](../sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md) — `docs/sessions/` is gitignored, so the executive report is a session artefact and may be absent from a fresh clone; the re-census linked above is tracked (force-added)

---

## Files

**Implementation**
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx` — the nav tree, `isNavItemVisible`, `buildNavigation`, `AUTOMOTIVE_VERTICALS`, `VERTICAL_NAV_KEYS`, `getNavLabel`
- `apps/web/src/contexts/CompanyConfigContext.tsx` — provides `hasModule()`
- `apps/web/src/components/guards/ModuleGuard.tsx` — route-level vertical guard
- `apps/web/src/features/auth/components/RequirePermission.tsx` — route-level permission guard (re-exported from `components/auth/RequirePermission.tsx`)
- `apps/api/config/verticals.php` — module-to-vertical configuration

**Tests**
- `apps/web/src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx`

**Tooling**
- `scripts/factory/gen-route-manifest.mjs` + `scripts/factory/manifests/`
- `scripts/factory/check-manifest-drift.sh`

---

*Document Version: 2.0*
*Last Updated: 2026-08-19*
