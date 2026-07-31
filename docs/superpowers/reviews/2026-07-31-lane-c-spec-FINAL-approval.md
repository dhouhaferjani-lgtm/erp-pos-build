# Lane C spec — FINAL APPROVAL (2026-07-31)

**Spec:** `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` @ `bc34e2096`
(branch `feat/v3-refund-chain`, revision 4.3).

**Review ladder:** rev 1 REJECT×3 → rev 2 REJECT×3 (converged) → orchestrator scope directive
(cash-only, same-device, single-terminal launch slice; VOID/voucher/original_payment fail-closed or
roadmapped §16) → rev 3: treasury+fiscal APPROVE-WITH-FIXES, Codex REJECT (same items) → rev 4 fold
with 4 orchestrator rulings → scoped verification: fiscal APPROVE, Codex 6/7, treasury
APPROVE-WITH-FIXES (1 blocker: SalesReturn not seeded FR/TN — premise verified false) → rev 4.1
errata → treasury micro-verify T1/T2 PASS, T3 miss (+2× preview error) → rev 4.2 → treasury: SPEC
FINAL after one documentation fix (backfill never-rewrites-type; installed-base divergence accepted)
→ rev 4.3 closing edit, orchestrator-verified in place.

**All verdicts satisfied.** Code phase may be planned and dispatched against the spec's frozen §17
manifest. Landing sequence per spec §9 (server-first, capability-gated, two-phase enable/acknowledge).
E-7 closes only on the green device-sale → refund → next-sale → Z integration evidence at schema 3.

Records: r1 `…codex-refund-chain-spec-review.md` + `…lane-c-spec-fiscal-treasury-reviews.md`;
r2 `…-r2-…`(×2); r3 `…-r3-…`(×2); r4 `…-r4-verify…`(×2). 8 pre-existing production defects
discovered across the ladder — all in-design or ticketed.
