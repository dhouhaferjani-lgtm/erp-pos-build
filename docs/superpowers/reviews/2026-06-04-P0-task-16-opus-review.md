# Opus Adversarial Review — Task 16 (Device `terminalStore.ts` — extend `Terminal.location` type)

**Date:** 2026-06-05
**Commit reviewed:** `46117f25c` — `feat(branch-tax-id): device Terminal.location carries tax fields`
**Plan:** `docs/superpowers/plans/2026-06-04-branch-tax-id-P0.md` (Task 16)
**Spec:** `docs/superpowers/specs/2026-06-04-branch-tax-id-design.md` (§8 step 2)
**Reviewer:** Opus (adversarial)

> Note: the supplied `/tmp/branch-tax-id-task-16.diff` was outside the session's allowed read roots. Reviewed the equivalent change directly from git commit `46117f25c` (matches the Task 16 commit message verbatim) plus the working-tree files.

---

## Scope of the change

Task 16 is a **TypeScript type-only** change (Phase 2, device). It:

1. Extends `Terminal.location` in `apps/pos/src/stores/terminalStore.ts:46-53` with `tax_id`, `vat_number`, `legal_identifiers`.
2. Adds a new round-trip test `apps/pos/src/stores/__tests__/terminalStore.branchTax.test.ts`.
3. Updates 4 existing strongly-typed `Terminal` fixtures (terminalStore.test, TrainingModeBanner, TerminalNotReadyBanner, offlineFirstFlow:429) to add the three new required keys so typecheck stays green.

No runtime/store logic, no payload builder, no fiscal schema/version touched.

---

## Verification performed

| Check | Result |
|---|---|
| Type fields match spec §4/§8 (`tax_id`/`vat_number`/`legal_identifiers`) | ✅ Match |
| snake_case parity with backend `TerminalResource` (Task 15, `TerminalResource.php:33-35`) | ✅ Byte-for-byte (`tax_id`, `vat_number`, `legal_identifiers`) |
| Type parity with web shape (Task 13, `Record<string, unknown> \| null`) | ✅ Consistent (device snake_case mirror) |
| No `any` | ✅ Uses `satisfies Terminal` + `Record<string, unknown>` |
| No `app()`/PHP concerns | ✅ N/A (TS only) |
| No hardcoded Tailwind colors | ✅ No UI/`.tsx` styling touched (only test fixtures) |
| No missing i18n `t()` keys | ✅ N/A (no user-facing strings) |
| No fiscal payload schema/version drift | ✅ No payload builder/golden fixture touched; `fiscal_schema_version` values in fixtures are pre-existing test data |
| Branch-vs-company fallback bug | ✅ N/A — fallback logic is Task 17; Task 16 is the type carrier only |
| Scope creep | ✅ None — only the type + the fixtures the type change forces + the new test |
| Other un-updated `Terminal.location` fixtures break typecheck? | ✅ No — paymentStore.{stress,offlineFirst,cashTenderedAmount}.test.ts and offlineFirstFlow:236 all use `as never` casts, so they are typecheck-exempt. The implementer correctly updated exactly the strongly-typed fixtures and left the `as never` ones alone. |
| `type: 'physical'` in new fixture valid | ✅ `Terminal.type` is `string` (terminalStore.ts:28) |

The test mirrors `initialize()`'s offline path: `getStoredValue` → `apiGet` rejects → fall back to cache → `setStoredValue(StorageKeys.TERMINAL, resolvedTerminal)` → `set({ terminal })`, then asserts the three branch fields survive and `setStoredValue` received the full object. The persist path (`terminalStore.ts:450`) stores the whole object, so the round-trip assertion is sound.

---

## Findings

### BLOCKER
None.

### MAJOR
None.

### MINOR

**MIN-1 — TDD "red" is typecheck-only, not runtime.**
`terminalStore.branchTax.test.ts` asserts the fields round-trip at *runtime*. Because this is a type-only change and JS preserves unknown object keys, the runtime assertions would **pass even without the production edit** (the store passes the cached object through verbatim). The genuine pre-implementation failure is at `pnpm typecheck` (the `satisfies Terminal` fixture would not compile against the old type). So the Step-2 "run to verify it fails" red was a typecheck failure, not a Vitest failure. This is acceptable for a type-only change — the test still serves as a real regression guard against any future serializer that strips fields — but the test is not a strict runtime-red. No action required; noted for honesty of the TDD claim.

### NIT

**NIT-1 — Inconsistent fixture style.** The two `location` objects inside `offlineFirstFlow.test.ts` diverge: the typed setState block at `:429` was extended with the tax fields, while the `as never`-cast block at `:236` was left as `{ id, name, code }`. Both are correct (the cast exempts :236), but a reader may wonder why one was touched and not the other. Optional: add the three `null` fields to :236 too for uniformity, or leave as-is.

---

## Spec/plan alignment

- Plan Task 16 Step 3 prescribes exactly `tax_id: string | null; vat_number: string | null; legal_identifiers: Record<string, unknown> | null` — implementation matches verbatim (`terminalStore.ts:50-52`).
- Spec §8 step 2 ("extend `terminalStore.ts` `Terminal.location` type with the tax fields, persisted to localStorage + SQLite mirror") — satisfied; persistence is covered by the round-trip test.
- The required-not-optional choice (keys mandatory, values nullable) is correct: backend now always emits these on the `location` object (Task 15), so the stronger contract is warranted and forces explicit fixtures.

---

## Conclusion

A precise, minimal, correctly-scoped type-only change with sound backend parity, no fiscal risk, no scope creep, and no typecheck collateral missed. The only substantive note (MIN-1) is a candor caveat on TDD red for a type-only change, not a defect.

VERDICT: APPROVE
