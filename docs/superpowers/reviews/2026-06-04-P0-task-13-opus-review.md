# Opus Adversarial Review — Task 13: Frontend types, API shapes, transform

**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` — Task 13
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md`
**Diff:** `/tmp/branch-tax-id-task-13.diff` (150 lines)
**Date:** 2026-06-05
**Reviewer:** Opus (adversarial)

## Scope of change

Phase 1, frontend-only, no fiscal payload. Adds `taxId` / `vatNumber` / `legalIdentifiers`
(camelCase) and their `tax_id` / `vat_number` / `legal_identifiers` (snake) counterparts to:

- `apps/web/src/features/location/api.ts` — `LocationApiResponse`, `CreateLocationInput`,
  `UpdateLocationInput`, `CreateLocationPayload`, `UpdateLocationPayload`,
  `createLocation`/`updateLocation` mappers, `transformLocationResponse`.
- `apps/web/src/features/locations/types.ts` — `Location`.
- New test `location/__tests__/transformLocationResponse.test.ts`.
- `locations/hooks/__tests__/useLocations.tenantScope.test.tsx` — fixture updated.

## Review-gate checklist (plan line 1247)

> "confirm all 4 shapes + response type + transform + both payload mappers updated
> (memory: missing a mapping shape silently drops fields)."

Verified against the real `location/api.ts`:

| Surface | Updated? | Evidence |
|---|---|---|
| `LocationApiResponse` (response type) | ✅ | api.ts:19-21 |
| `CreateLocationInput` | ✅ | api.ts:42-44 |
| `UpdateLocationInput` | ✅ | api.ts:61-63 |
| `CreateLocationPayload` | ✅ | api.ts:81-83 |
| `UpdateLocationPayload` | ✅ | api.ts:97-99 |
| `createLocation` mapper body | ✅ | diff:97-99 (`tax_id: input.taxId`, …) |
| `updateLocation` mapper body | ✅ | diff:107-109 (conditional `if (… !== undefined)`) |
| `transformLocationResponse` | ✅ | diff:117-119 |
| `locations/types.ts` `Location` | ✅ | types.ts:18-20 |

All nine surfaces updated. No mapping shape dropped. The memory-flagged failure mode
(silently dropping a field through one un-updated mapper) does **not** occur.

## Hunt results

- **Field-name / spec drift:** None. Spec §4 (`tax_id`, `vat_number`, `legal_identifiers`)
  and §3 DTO (`taxId`, `vatNumber`, `legalIdentifiers`) match the diff exactly. `legal_identifiers`
  is jsonb keyed by `siret`; `Record<string, unknown> | null` is the correct TS shape (object,
  not array — the spec's `legalIdentifiers[]` shorthand notwithstanding).
- **Fiscal payload / version drift:** None touched. Task 13 is types/transport only; no
  `SALE_RECEIPT`, no canonical-payload bytes, no version bump. Correct for Phase 1.
- **TDD:** Test present and precedes implementation in plan order. The test object now
  matches `LocationApiResponse`'s 20 fields exactly (`type: 'shop'` is a valid `LocationType`,
  locationStore.ts:7), so it typechecks post-impl; the plan's defensive `as never` was correctly
  dropped. At the red stage the assertions (`out.taxId`) fail at runtime (vitest doesn't
  typecheck) — valid red→green.
- **`app()` / `mixed` / `any`:** None. `Record<string, unknown>` used throughout, no `any`.
- **i18n `t()` keys:** N/A — Task 13 adds no user-facing strings (that is Task 14).
- **Hardcoded Tailwind colors:** N/A — no `.tsx` UI changed.
- **Branch-vs-company fallback bug:** N/A at this layer — fallback resolution is backend
  (`TaxIdentityResolver`, Tasks 1–12) and device (Phase 2). This task only transports nullable
  fields verbatim; `null` correctly flows through (inherit semantics preserved downstream).
- **Both Location representations caught:** Good. The repo has two parallel Location shapes —
  the transformed `location/api.ts` path (LocationsPage, LocationProvider) and the cast
  `locations/types.ts` path (useLocations). Both were updated, plus the only other `Location`
  constructor (the tenantScope fixture). No latent typecheck breakage from the new non-optional
  `Location` fields.

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR
None.

### NIT

1. **`locations/api/locations.ts:8` — new camelCase tax fields will be `undefined` at runtime
   on the un-transformed `getLocations()` path.** `getLocations()` does
   `apiGet<Location[]>('/locations')`, casting the raw snake-case API response directly to the
   camelCase `Location` type with **no** `transformLocationResponse` step. So `location.taxId`
   from `useLocations()` is `undefined` at runtime (the wire field is `tax_id`). This is a
   **pre-existing** mismatch — `addressCity`, `addressCountry`, etc. already have the identical
   latent bug on this path — and no Task 13 consumer reads the tax fields, so it is not a defect
   *introduced* by this task. Flag only so that whoever wires a `useLocations()`-fed consumer of
   `taxId`/`vatNumber` in a later task (or Task 14 if it ever switches data sources) either routes
   through the transform or reads the snake field. Worth a one-line TODO near `getLocations`.

2. **`createLocation` emits `tax_id: undefined` unconditionally** (diff:97-99) whereas
   `updateLocation` guards with `if (… !== undefined)`. This is intentional and consistent with
   the surrounding address-field pattern in the same functions (undefined keys are dropped by
   JSON serialization), so no change required — noted only for completeness.

## Verification note

`pnpm test` / `pnpm typecheck` / `npx vitest` were blocked by this session's command-approval
sandbox and could not be executed here. Findings are from static inspection of the real files:
the test object is field-for-field congruent with `LocationApiResponse`; the only `Location`
object literal in the tree (the tenantScope fixture) is updated; no other consumer constructs a
`Location`. The reviewer is confident typecheck and the new test pass, but the executing engineer
should confirm `cd apps/web && pnpm typecheck && pnpm test src/features/location` is green before
merge, per "Verification is Law".

## Verdict

Faithful, complete, in-scope implementation of Task 13. All nine transport surfaces updated;
spec field names exact; no fiscal-payload or version drift; no `any`/`app()`; TDD test present;
both parallel Location shapes and the affected fixture handled. Only forward-looking NITs.

VERDICT: APPROVE
