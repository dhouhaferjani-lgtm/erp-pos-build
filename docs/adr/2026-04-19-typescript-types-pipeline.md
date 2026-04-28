# ADR — TypeScript types pipeline

**Status:** Accepted
**Date:** 2026-04-19
**Authors:** Claude Code (session 2026-04-19-investigate-types-pipeline)
**Branch:** `feat/types-pipeline-overhaul` (merged to `dev` after review)

## Context

AutoERP generates TypeScript type declarations from PHP DTOs using
`spatie/laravel-typescript-transformer`. An audit on 2026-04-19 on
branch `dev` confirmed:

1. **Zero files in `apps/web` imported the generated namespace.** The
   pipeline was cosmetic — every feature hand-rolled parallel
   TypeScript interfaces that silently drifted from the wire.
2. **`DataCollection<T>` properties emitted `Array<any>`** — 80
   occurrences across 40 production DTOs. The transformer could not
   resolve element types from `#[DataCollectionOf]` attributes.
3. **Four Identity DTOs emitted camelCase** (`emailVerifiedAt`,
   `lastLoginAt`, `createdAt`, `canDiscount`, …) while 69 others
   emitted snake_case. No coherent convention was enforced.
4. **Shadow interfaces disagreed with the wire.** The
   `StockLevelsPage` shadow typed monetary fields as `number` while
   the wire emits `string` (Postgres DECIMAL → PHP string → JSON
   string). That live bug turned low-stock comparisons into **lexical
   string comparisons**, so `"10" <= "9"` was `true` and stock of 10
   with minimum 9 was erroneously flagged below-minimum.

AutoERP is pursuing fiscal certifications (NF525 France, Italian SDI,
Tunisia fiscal, UK MTD, Peppol/Factur-X). Silent drift between
backend DTOs and frontend types is a **compliance-material** risk:
fiscal reports, invoice XML schemas, and hash-chained audit trails
depend on the frontend presenting the exact data the backend recorded.

## Decisions

### D1. Writer — custom `GlobalNamespaceWriter` emitting to `.d.ts`.

The default `TypeDefinitionWriter` produces nested
`declare namespace App.Modules.…` blocks. With
`moduleDetection: "force"` in `apps/web/tsconfig.json`, that form is
module-local — the namespaces are unreachable from any `.ts`/`.tsx`
file in `apps/web`. Swapping to `ModuleWriter` was considered but
would have collided on duplicate basenames (`PaymentStatus` ×3,
`TransactionType` ×2).

`App\Shared\TypeScript\GlobalNamespaceWriter` subclasses
`TypeDefinitionWriter` and wraps the output in
`declare global { … } export {};`. That lifts the whole namespace
tree to ambient global scope without collisions, while the trailing
`export {};` keeps the file a module so `moduleDetection: "force"`
is satisfied. Output moves to
`packages/shared/types/generated.d.ts` and is referenced from
`apps/web/src/vite-env.d.ts` via a path-based triple-slash reference
(with a targeted `eslint-disable-next-line` because the `types=`
form doesn't honor the package `exports` map).

### D2. Case convention — snake_case on both sides.

69/73 tagged DTOs already emitted snake_case. Every hand-written
frontend `types.ts` already used snake_case. The cost of
standardizing on snake_case was migrating 4 Identity DTOs (`UserData`,
`AuthUserData`, `LoginData`, `LoginResponseData`). Alternative
policies — an axios response interceptor or backend-side
`CamelCaseMapper` — would have required migrating dozens of DTOs or
every frontend type file for no safety gain.

**Phase C (the Identity migration) is deferred** from this PR to a
separate coordinated release. The migration changes the `auth/me`
and `auth/login` wire formats which are consumed by:

- The web app (in lock-step)
- The Tauri desktop apps (IziPOS, Otospex — separate deploy cycle)
- The React Native mobile app
- Any `localStorage` / service-worker caches holding the old user
  shape

A compat-shim release that emits BOTH cases simultaneously, followed
by a deprecation cycle, is the planned rollout. Until then, the
snake_case convention is enforced on NEW DTOs only via code review
against this ADR.

### D3. `DataCollection` lowering — Data-aware transformer.

The config previously registered
`Spatie\TypeScriptTransformer\Transformers\DtoTransformer` — the
generic upstream transformer that knows nothing about Spatie Laravel
Data. `spatie/laravel-data` itself ships
`Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer`,
which resolves `DataCollection<T>` / `#[DataCollectionOf]` via the
same `DataConfig` machinery used at runtime for JSON serialization.

The config now registers both: the Data-aware transformer FIRST (for
`BaseData` subclasses) and the generic transformer SECOND as a
fallback (for non-Data tagged classes like `PaginationData`). The
`Array<any>` count dropped from 80 to 60.

The remaining 60 residuals across 28 DTOs split into three
categories, tracked in follow-up task #12:

- **Category A** — truly untyped JSONB records (e.g.
  `VehicleContextData::$snapshot`). Need proper DTOs per CLAUDE.md
  rule #3.
- **Category B** — element type is a scalar known to `DataConfig`
  but the Data-aware transformer drops it (limitation of
  `resolveTypeForProperty` on non-`dataClass` branches). Needs
  either a custom type processor or an upstream patch.
- **Category C** — element type IS a `Data` subclass but the element
  DTO lacks `#[TypeScript]`. Cheap fix: tag the element (e.g.
  `DocumentLineData`). Knock out first.

### D4. Decimal helpers crash-proof — `safeBig` silent-zero fallback.

Phase D exposed that the `DataTypeScriptTransformer` correctly types
monetary fields as `string` (matching the wire), which forced the
frontend to use `lib/decimal.ts`'s `bccomp`/`bcsub`/`bcadd` helpers
instead of native JavaScript operators. Those helpers route every
operation through `safeBig`, which previously threw on any
non-numeric input. A user paste of `"1.2.3"` into a number input
would crash the page.

`safeBig` was hardened to wrap `new Big(value)` in try/catch,
returning `new Big(0)` on parse failure. One fix protects every
decimal call site (~100 across 12 files — POS, Inventory, Treasury,
Promotion, etc.). Thirty unit tests in `apps/web/src/lib/decimal.test.ts`
exercise 7 garbage inputs through every public helper.

The trade-off: silent-zero masks invalid input as "0" rather than
surfacing an error. Upstream callers should validate monetary input
before it reaches this module; the silent-zero contract is a safety
net, not the preferred path. Task #17 tracks adding an explicit
`isValidDecimal()` helper and wiring it into monetary inputs.

Verified scope: fiscal/compliance frontend code
(`features/compliance/`, `types/fiscal.ts`) does NOT consume the
decimal helpers — hash chains are computed backend-side. The
silent-zero default does not affect fiscal integrity.

### D5. CI enforcement — drift guard in preflight + GitHub Actions.

`scripts/preflight.sh` regenerates `generated.d.ts` and fails on
diff. `.github/workflows/ci.yml` has a dedicated `types-drift` job
added to the `all-checks-pass` gate, so a PR that edits a tagged DTO
without regenerating types blocks at merge time. Both surfaces emit
the same three-command fix recipe:

```bash
(cd apps/api && php artisan typescript:transform)
git add packages/shared/types/generated.d.ts
git commit -m 'chore(types): regenerate TypeScript types'
```

The CI job is intentionally lean (no `.env`, no APP_KEY, no DB, no
Redis) because the transformer is pure reflection. If that ever
changes, the job's inline comment directs investigators to recent
service-provider boot-path changes.

### D6. Migration sequence.

1. **Inventory `StockLevelsPage`** — the reference migration done in
   this PR. Smallest-surface shadow with a tagged counterpart. Along
   the way fixed a live compliance-material lexical-comparison bug.
2. **Catalog** — 7 tagged DTOs (`CompositeItemData`, `RecipeData`,
   `ModifierGroupData`, …) with a shadow at
   `features/catalog/types.ts`. Natural second target.
3. **Accounting** — done separately by
   `fix/test-suite-baseline-remediation`. This PR explicitly avoided
   `features/finance/*` to prevent merge conflicts with that branch.
4. **Every other feature** migrates as it's touched. No mass rewrite.
5. **New features** MUST re-export from the generated namespace —
   enforced by code review against `docs/conventions/04-FRONTEND-TYPES.md`.

## Consequences

- Backend DTO shape changes are now **compile-time breaking** at the
  `pnpm typecheck` step, not runtime-surprising in production. That's
  the desired signal.
- Future new modules get type safety for free as long as they tag
  their DTOs with `#[TypeScript]` and re-export from their
  `types.ts`.
- The 18 tagged DTOs without shadow interfaces (Automotive,
  Marketplace) get instant type visibility on the frontend without a
  separate migration pass.
- The `expectTypeOf` pattern used in
  `features/inventory/__tests__/types.test.ts` is validated by
  `tsc --noEmit`, not by `vitest run`. Future contributors writing
  similar contract-lock tests should note: `vitest run` will report
  them green regardless — the actual guard is the typechecker.
- The silent-zero `safeBig` default is deliberately permissive.
  Upstream callers that need strict validation must gate before
  reaching the decimal helpers.

## References

- Plan: `docs/superpowers/plans/2026-04-19-typescript-types-pipeline.md`
- Coordination memo for parallel module sessions:
  `docs/superpowers/coordination/2026-04-19-types-pipeline.md`
- Investigation prompt:
  `docs/sessions/2026-04-19-investigate-types-pipeline-prompt.md`
- Updated convention doc: `docs/conventions/04-FRONTEND-TYPES.md`
