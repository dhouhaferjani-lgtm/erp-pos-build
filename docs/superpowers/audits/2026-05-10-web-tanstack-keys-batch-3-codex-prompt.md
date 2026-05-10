# Codex review prompt — web.tanstack-keys batch 3 (callsites .839-.849)

You are reviewing batch 3 of the `web.tanstack-keys` cluster (master plan §11). Batches 1+2 locked at `6f63d731` (22/849 fixed, 827 pending). This batch covers `apps/web/src/pages/POS/*` — 11 callsites across 3 files.

## Self-disclosed batch-scope clarification

The original inventory listed 3 invalidate callsites in `POSTransactions.tsx` at lines 402/452/522 (.846/.847/.848). When the import block was added at the top of the file, the line numbers shifted +6, and the scanner surfaced an additional `['pos', 'shift']` invalidate at line ~460 that shared the `.847` shape. Treating it as part of the same batch (1 fix, not split into a new claim) preserves the single-batch invariant — the file's three "invalidate shift after receipt" sites collapse to one mental model.

## Scope (11 callsites, 3 files)

| callsite | file | line | shape | fix |
|----------|------|-----:|-------|-----|
| .839 | POSShiftsDashboard.tsx | 48 | useQuery [pos, shift, terminalCode] | tenantScopedKey wrap + enabled gate |
| .840 | POSShiftsDashboard.tsx | 56 | useQuery [pos, shift-balance, shiftId] | tenantScopedKey wrap + enabled gate |
| .841 | POSShiftsDashboard.tsx | 63 | invalidate [pos, shift] (cascade) | predicate posShiftInvalidationPredicate |
| .842 | POSShiftsDashboard.tsx | 64 | invalidate [pos, shift-balance] (cascade) | predicate posShiftBalanceInvalidationPredicate |
| .843 | POSTransactions.tsx | 128 | useQuery [pos, shift, terminalCode] | tenantScopedKey wrap + enabled gate |
| .844 | POSTransactions.tsx | 145 | useQuery [pos, payment-methods] | tenantScopedKey wrap + enabled gate |
| .845 | POSTransactions.tsx | 152 | useQuery [pos, payment-repositories] | tenantScopedKey wrap + enabled gate |
| .846 | POSTransactions.tsx | 402 | invalidate [pos, shift] (cascade) | predicate posShiftInvalidationPredicate |
| .847 | POSTransactions.tsx | 452 + 460 (shifted) | invalidate [pos, shift] (cascade) — 2 sites | predicate posShiftInvalidationPredicate |
| .848 | POSTransactions.tsx | 522 | invalidate [pos, shift, terminalCode] (exact-match) | tenantScopedKey wrap |
| .849 | Terminals.tsx | 39 | useQuery [locations] | tenantScopedKey wrap + enabled gate |

## Fix at commit `65a6f325` (current branch tip)

New helper module: `apps/web/src/pages/POS/_invalidation.ts` exports two predicate factories — `posShiftInvalidationPredicate(t, c)` matching `[pos, shift, ...]` queries with the active tenant tail; `posShiftBalanceInvalidationPredicate(t, c)` mirroring for `[pos, shift-balance, ...]`. Both used by Dashboard's `invalidateShiftData` and Transactions' post-receipt cleanup paths.

Why predicates: the prefix-match cascade defect from batch 1+2 applies here too. `tenantScopedKey(['pos', 'shift'])` resolves to `[pos, shift, t, c]` which is NOT a prefix of leaf `[pos, shift, terminalCode, t, c]` because position 2 mismatches. Predicate sidesteps the positional issue.

The `onSuccess` modal callback at L522 (`.848`) uses an exact-match wrap (`tenantScopedKey(['pos', 'shift', terminalCode])`) because both invalidate-key and leaf are identical shape — exact match cascade works without predicate.

## What you should adversarially check

1. **Predicate correctness for `.841`, `.842`, `.846`, `.847`** — walk through each predicate's conditions:
   - `posShiftInvalidationPredicate` matches when `k.length >= 4 && k[0] === 'pos' && k[1] === 'shift' && k.at(-2) === t && k.at(-1) === c`. Confirm it matches `[pos, shift, TERM-1, t, c]` (length 5) and rejects `[pos, shift-balance, sid, t, c]` (k[1] mismatch).
   - `posShiftBalanceInvalidationPredicate` mirrors but with `k[1] === 'shift-balance'`.
   - The `k.length >= 4` minimum guards against partial keys (`[pos, shift]` would have length 2).

2. **Exact-match cascade for `.848`** — confirm `tenantScopedKey(['pos', 'shift', terminalCode])` produces `[pos, shift, terminalCode, t, c]` matching the leaf shape `[pos, shift, terminalCode, t, c]` from `.843`. Cascade works exactly.

3. **Closure capture of tenant scope** — both `POSShiftsDashboard` and `POSTransactions` subscribe to `useAuthStore` + `useCompanyStore` via selector form. The closure inside the invalidate handlers captures the closure-fresh tenantId/companyId. Verify the subscription pattern matches what closed F1 in batch 2 (state-value selector, not action selector).

4. **`enabled` defense-in-depth gate** — every wrapped useQuery hook has `enabled: !!tenantId && !!companyId` (often combined with `!!terminalCode`).

5. **Test honesty** — read `apps/web/src/pages/POS/__tests__/tenantScope.test.tsx`:
   - The cascade test seeds `[pos, shift, TERM-1]` AND `[pos, shift, TERM-2]` AND a tenant-B shift entry, fires predicate-based invalidation, and asserts both tenant-A entries refetched (data observable) AND tenant-B entry untouched. Removing the predicate from the helper would fail the cascade refetch assertion.
   - The shape probes use real `tenantScopedKey` calls in the probe components, mirroring production. Removing the wrap from production would break the same shape in any consumer.
   
6. **Hostile-grep** — confirm only the 11 expected callsites are touched. Scanner says 827 → 816 delta = 11. Confirm by reading each of the 3 source files end-to-end. Note the inventory `.847` covers two adjacent `['pos', 'shift']` invalidates (the shift +6 issue) — this is intentional same-batch closure.

7. **No regressions** in batch 1 (categories) + batch 2 (products) + foundation (tenantScopedKey, scanner, queryKeyNamespace).

## Quality gates the main session ran at `65a6f325`

```text
pnpm vitest run src/pages/POS/__tests__/tenantScope.test.tsx                     → 12/12 pass
pnpm vitest run src/features/categories/hooks/__tests__/useCategories.tenantScope → 9/9 pass
pnpm vitest run src/features/products/__tests__/tenantScope.test.tsx             → 13/13 pass
pnpm vitest run src/__tests__/architecture/queryKeyNamespace.test.ts             → 4/4 pass
pnpm typecheck → pass
node apps/web/tools/audit-tanstack-keys.mjs --json | jq '.violations | length'  → 816 (was 827; delta = 11)
php artisan sweep:inventory:verify-history → 2973 events / 1205 callsites / 0 problems
```

## Deliverable

Save your verdict to `docs/superpowers/reviews/2026-05-10-web-tanstack-keys-batch-3-codex-review.md`.

Format (strict):

```text
Commit reviewed: 65a6f325
Verdict: <APPROVE | APPROVE-WITH-MINOR-EDITS-APPLIED | REQUEST-CHANGES | BLOCKER>

## Findings

[F1] ...

## Verification Run

(any commands you ran)
```

The `Verdict:` line MUST equal one of those four exact strings.

Single-round APPROVE expected — cascade tests written upfront with fetch-count + cross-tenant signals. The predicate pattern is now well-established from batches 1+2; batch 3 applies it to two new predicate variants (shift + shift-balance). If you find a substantive defect, REQUEST-CHANGES is fine; main session will iterate.

If sandbox blocks the file write, report findings + verdict in your final message — main session will persist the verdict file.
