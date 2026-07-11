# Treasury Phase ② — Final Whole-Branch Review (orchestrated multi-agent)

> **Date:** 2026-07-11 · **Branch:** `feat/treasury-instruments` @ `7fbd2c92e` (42 commits, 147 files, +13,938/−1,208, based on origin/dev `9cd187161`)
> **Protocol (owner order):** Fable orchestrator ONLY; 2 Sonnet discovery lanes + 6 Opus verification lanes, all independent, all code-grounded (file:line). This review sits ABOVE Codex's four autonomous `claude -p` gates and is the merge decision.
> **VERDICT: APPROVE — merged to dev (ff) and promoted.**

## Lane verdicts

| Lane | Scope | Verdict |
|---|---|---|
| D1 (Sonnet) | 147-file diff map + scope drift vs plan File Structure | Clean — all files map to waves; port/balance-triggers/fiscal files untouched; generated types genuinely regenerated |
| D2 (Sonnet) | Process artifacts: gate trail, deviations, rebuttals, E2E report | Honest — escalation rule honored at all 4 gates (Gate-2 HIGH triggered Fable twice; Gate-4 non-escalation correct); rebuttals reviewer-adjudicated; E2E discloses its own fix-forwards |
| V1 (Opus, treasury-reviewer) | Spine invariant, global lock order, precision, immutability | **APPROVE** — port byte-untouched; exactly 2 new `record()` sites (clear/bounce), JE-linked, §7-exact amounts; lock order pinned by live pg lock-trace test; injected-GL-failure rollback proven |
| V2 (Opus, treasury-reviewer) | GL shapes vs spec §7, routing, reconcile #4, échéancier | **APPROVE** — every posting shape pinned on account codes + amounts; tolerance reversal document-scoped (`payment_id IS NULL`); reconcile #4 alert-only + watermark; forecast double-count pinned |
| V3 (Opus, treasury-reviewer) | Payment cutover, kind rule, side doors, refund guards | **APPROVE** — customer-direction + kind∈{cheque,effet} scoping exact; 4 side doors 422; refund guards in the real Domain/Services file, pre-write; zero TND literals on store paths |
| V4 (Opus, fiscal-pos-reviewer) | POS bridges, fiscal perimeter, replay probe, refund contract | **APPROVE** — fiscal perimeter inviolate (F9 snapshot pins); JE-debit replay probe all 3 shapes tested; refund contract per §9 (never guess-cancel; transients propagate); revenue nets to zero on void |
| V5 (Opus, frontend-conventions-reviewer) | FE under post-sweep conventions | **APPROVE** — audits verbatim-clean, baseline untouched; i18n en/fr parity exact; granular perms; 357/54 vitest green |
| V6 (Opus) | Independent re-run of all verification commands | All in-scope suites green fresh (1,306 tests / 5,476 assertions observed — a superset of Codex's claimed 1,016/4,198); phpstan 0; the only red = 5 Architecture failures + 20 pint files proven pre-existing on origin/dev, untouched by the branch |

## Cross-lane reconciliations

- V2's LOW (refund guard `RuntimeException` → feared 500) is resolved by V3's evidence: `PaymentRefundController` catches and maps to 422.
- D2's carry-forward "payment-currency-scale eligibility bccomp" is closed by construction: Gate-4 RC2 made `receive()` hard-reject non-company-currency instruments.
- D2's "Gate-2 HIGH-1 fix scoping" independently confirmed by V3 (both JEs swap; movement gated `!$isDeferredCustomer`; supplier byte-identical pin).
- D1's flagged GL-builder scope expansion (null-actor synchronous posting) was Gate-3-reviewed and is exercised by the sibling-bridge worker paths; safe direction (synchronous is stricter than afterCommit).

## Follow-up register (ALL LOW / non-blocking — ticket, don't block)

1. Add the immediate-excess advance-JE regression pin (Fable Gate-2 LOW-1 carry-forward — behavior change is live and safe-direction but unpinned).
2. Retire the last `'TND'` fallback in `PaymentRepository.php:96` model boot.
3. One-line comment at the refund amount match (`TreasuryReceiptBridge` candidate `where('amount', ...)`) documenting the same-currency-scale reliance (fails safe).
4. `InstrumentLifecycleService` tolerance-JE lookup uses `latest('created_at')` — revisit if multiple tolerance write-offs per document ever coexist.
5. Consider `is_system`/purpose-flagging the seeded portfolio accounts (currently user-editable plain accounts; missing-account is handled, so correctness holds).
6. FE pre-existing: `PaymentForm.tsx:774` `parseFloat` validation + `:1202` `bg-white` (origin/dev debt, not this branch).
7. Process nit: 3 unrelated finance test files got a flakiness fix bundled into commit `18362f40a` (test-only, harmless).

## Deploy

Per `docs/handoff/treasury-phase2-deploy-checklist.md` (in-branch): per-tenant `tenants:migrate`; chart seeder re-run (portfolio accounts — the E2E proved this prerequisite live when remise 422'd on a chart missing 5313); perm reseed + `permission:cache-reset` (`instruments.update/bounce/remit/cancel`); `phase2_cutover_at` stamped by migration; FEC descriptive carries the `EF` journal declaration.
