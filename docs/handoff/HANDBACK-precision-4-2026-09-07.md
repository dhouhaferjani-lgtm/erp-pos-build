# HANDBACK — lane `precision-4` PARKED (superseded), 2026-09-07

**Status:** parked before any code. The orchestrator session's handover note (2026-09-06 21:30) moved the precision widening INSIDE slice W-CASH-1 rev 10 as packages P0-a (read-only census) / P0-b (non-additive widening to decimal(15,4)). This session must not run its own widening.

**What exists from this lane (input for W-CASH-1 P0, not to be re-derived):**
- Brief rev 1 `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` (`51dd84878`): premise correction with live tuples (all four columns already `decimal(15,3)`), census classification allowlist (§T1 check 5), deploy variables (§T5), local census snapshot (49 scale-2 / 143 scale-3 / 95 scale-4 numeric columns on a local tenant DB).
- Owner row A10 + owner chat ruling 2026-09-07: Eloquent casts stay `decimal:3` **provisionally**; a benchmark note on 4-decimal accounting practice (Tunisia) and the existing rounding rules decides, then the owner rules. **W-CASH-1 rev 10 P0-b currently says casts → `decimal:4` (plan line 172) — that conflicts with the provisional ruling; the benchmark note `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` is the input for the orchestrator's next fix round.**

**Branch/worktree:** `lane/precision-4` had no commits beyond `dev`; worktree `.worktrees/precision-4` removed and branch deleted (nothing to preserve). Codex gate prompt (scratchpad) not run.
