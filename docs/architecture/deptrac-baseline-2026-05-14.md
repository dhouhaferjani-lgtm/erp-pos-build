# Deptrac Architecture Baseline — 2026-05-14

**Task:** M3.1 (dev deferred-backlog session)
**Scope:** Make the hexagonal-architecture boundary claims enforceable in CI without
trying to refactor 59 pre-existing violations before first tenant.

## What changed

| File | Change |
|---|---|
| `apps/api/deptrac.yaml` | Rewritten — glob-based hexagonal layers covering **every** module |
| `apps/api/deptrac.baseline.json` | New — frozen snapshot of the 59 current violations, by category |
| `apps/api/tools/deptrac-ratchet.php` | New — wrapper that turns Deptrac into a ratchet gate |

## Why the config was rewritten, not extended

The previous `deptrac.yaml` enumerated layers per module by hand. It had drifted badly:

- It covered only **12** of the **39** current modules.
- It referenced a `Sales` module that **does not exist** in `app/Modules/`.
- 27 modules — including POS, Voucher, Taxation, Billing, Marketplace,
  PlatformIntegration, BatchExpiry, Uom, Coupon — had **no boundary enforcement at all**.

Hand-maintaining ~39 modules × 4 tiers (~156 named layers + rulesets) is a guaranteed
source of exactly this kind of drift. The rewrite uses **glob-based layers** — one layer
per hexagonal tier, each collecting every module's matching sub-directory:

```
ModuleDomain          app/Modules/[^/]+/Domain/.*
ModuleApplication     app/Modules/[^/]+/Application/.*
ModuleInfrastructure  app/Modules/[^/]+/Infrastructure/.*
ModulePresentation    app/Modules/[^/]+/Presentation/.*
```

plus `SharedDomain` / `SharedContracts` / `SharedApplication` / `SharedInfrastructure`.
This covers every current **and future** module automatically and cannot go stale.

### What it enforces

The hexagonal dependency direction **within the module tree**:

- `ModuleDomain` may depend only on `SharedDomain` + `SharedContracts`. Any reach up into
  Application / Infrastructure / Presentation is **domain leakage**.
- `ModuleApplication` may depend on `ModuleDomain` + the shared kernel.
- `ModuleInfrastructure` may depend on `ModuleDomain` + `ModuleApplication` + shared.
- `ModulePresentation` may depend on every inner tier.
- The shared kernel must not depend on any module tier.

### What it deliberately does NOT enforce

**Cross-module coupling.** Module A's Domain depending on Module B's Domain is a
same-layer dependency and is allowed. Detecting cross-module coupling needs per-module
layers — a much larger effort than this first-tenant baseline. Per the M3 plan, that is a
**separate follow-up ticket**, not part of M3.1.

## The baseline — 59 violations

`deptrac analyse` reports **59** violations as of `41750e66 + this branch`:

| Category | Count | Kind |
|---|---:|---|
| `ModuleDomain on ModuleApplication` | 22 | **DOMAIN LEAKAGE — hard-fail** |
| `ModuleApplication on ModuleInfrastructure` | 21 | ratcheted |
| `SharedContracts on ModuleApplication` | 11 | ratcheted (shared kernel → module, wrong direction) |
| `SharedContracts on ModuleDomain` | 2 | ratcheted (shared kernel → module) |
| `SharedInfrastructure on ModuleDomain` | 2 | ratcheted (shared kernel → module) |
| `ModuleInfrastructure on ModulePresentation` | 1 | ratcheted |
| **Total** | **59** | |

These are **not fixed** in this task — M3.1 is the *baseline + gate*, not the cleanup.
The two largest buckets (`ModuleDomain → ModuleApplication`, 22; and
`ModuleApplication → ModuleInfrastructure`, 21) are the candidates for follow-up
remediation tickets. The 15 `Shared* → Module*` violations are the shared kernel reaching
into specific modules — architecturally backwards and worth a dedicated cleanup.

## The ratchet gate — `tools/deptrac-ratchet.php`

Deptrac alone exits non-zero on *any* violation, which is useless against a 59-violation
legacy baseline. The wrapper turns it into a ratchet:

- Runs Deptrac, groups violations by `LayerFrom on LayerTo` category.
- **Domain leakage** (`ModuleDomain on *`) hard-fails as a **BLOCKER** if its count rises
  above baseline, or if a brand-new domain-leakage category appears. This is the
  invariant that must never regress.
- **Every other category** is ratcheted — it may shrink or hold, never grow.
- The **total** is ratcheted too, so a new category cannot sneak violations in.
- When counts drop, it prints the `--update-baseline` command so the gain is locked in.

### Usage

```bash
cd apps/api

# Gate (CI): exit 0 = within baseline, exit 1 = regression
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json

# Re-baseline after a real improvement (counts dropped)
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json --update-baseline
```

### Wiring into CI

Add the gate command to the backend CI job (alongside PHPStan / Pint / PHPUnit). It is
static-analysis-only — zero runtime risk — and fast (~3–4 s on this codebase).

## Follow-up tickets (not in M3.1 scope)

1. **Remediate `ModuleDomain → ModuleApplication` (22)** — the highest-priority leakage;
   Domain classes orchestrating Application services. T3 of this same session fixes one
   instance (`Document::recalculateTotals()`); the rest need their own pass.
2. **Remediate `ModuleApplication → ModuleInfrastructure` (21)** — Application reaching
   into concrete Infrastructure instead of depending on a port.
3. **Remediate `Shared* → Module*` (15)** — the shared kernel must not know about
   specific modules; these references belong behind a contract.
4. **Cross-module coupling detection** — add per-module layering (or a module-name
   collector) so module A→B dependencies become visible. Larger effort; post-launch.
