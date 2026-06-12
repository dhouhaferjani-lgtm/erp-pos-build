# Codex Adversarial Review — POS location-stock follow-ups (FU-1…FU-10)

Branch: `feat/pos-location-stock-followups` (base `origin/dev`)
Date: 2026-06-12
Reviewer: Codex (adversarial), saved by Claude after verifying findings against code.

## Verdict: APPROVE-WITH-MINOR-EDITS

Codex ran lint (`pnpm --filter @autoerp/pos lint` — 0 errors, 39 pre-existing
warnings), a focused Vitest suite (6 files, 112 tests, all passed), and a manual
ESLint-API probe on the `no-restricted-syntax` rule. Every high-risk claim was
verified against actual file content before being stated.

| Severity | Count |
|---|---|
| BLOCKER | 0 |
| P1 | 0 |
| P2 | 0 |
| NIT | 1 |

## Area-by-area

**FU-3 (FISCAL — highest risk): LGTM.**
- `resolveSellerIdentity()` destructures only `.identity` from
  `resolveSellerIdentityWithSource()`; the `source` discriminant is
  structurally impossible to leak into the signed seller object.
- Signed seller shape (`name, taxNumber, countryCode, street, city,
  postalCode`) is byte-identical to pre-branch.
- All three `paymentStore.ts` signing sites (~570/675/761) call the bare
  `resolveSellerIdentity` wrapper.
- `buildEscPosReceiptData` migrated to `(receipt, options)` bag; both HomePage
  production callers and all test callers migrated; no positional caller found.

**FU-2 (ESLint gate): LGTM.** Manual ESLint probe confirmed raw `.addItem(` /
`.updateQuantity(` member calls and bare-identifier calls are blocked outside
`lib/stock/`, `stores/cartStore.ts`, and tests; gated wrappers
`addItemGated`/`updateQuantityGated` are not caught; 0 errors on the existing
codebase.

**FU-9 (post-drain stock pull): LGTM.** `void pullLocationStock(db,'delta')
.catch(...)` is genuinely non-blocking; `.catch` swallows errors (no unhandled
rejection). Placement before fiscal-event verification is correct — a stock
pull failure cannot prevent the fiscal path executing.

**FU-6 / FU-7 / FU-10: LGTM** (one NIT below). Shared migration helper, the
zero-scale tolerance characterization test, the `isOlderThan` helper, and the
15-minute amber threshold are all correct.

**Doc pins FU-4/FU-5/FU-8: LGTM.** Docblocks verified against the code they
describe; no false claims.

## NIT — StockFreshness docblock accuracy (confidence 88%)

`apps/pos/src/components/atoms/StockFreshness/StockFreshness.tsx` — the "Renders
NOTHING when … the local DB is unavailable" framing predates FU-10. The effect's
catch path leaves the previous timestamp untouched, so after a prior good read a
transient Tauri DB error keeps showing the last-good time (and goes amber once
stale). Only the browser/never-read case actually renders nothing. Qualify the
docblock to match FU-10's keep-last-good behavior.

→ **Resolution:** docblock clarified (commit on this branch). Behavior is
correct and intentional (FU-10 keep-last-good); the comment was the only gap.
