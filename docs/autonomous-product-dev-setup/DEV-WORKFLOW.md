# Synerivia ERP — Autonomous Dev Workflow

> Established 2026-06-30 (Phase 4 of autonomous-product-dev setup). Companion to `docs/PRODUCT-BIBLE.md` (§7 Quality) and `.agents/`.

## Quality gates

| Gate | Where | Status |
|------|-------|--------|
| **TDD (mandatory)** | every change, red→green→refactor | process rule (PRODUCT-BIBLE §7.1) |
| **Coverage 100%** | new code by construction; legacy raised as touched | PRODUCT-BIBLE §7.1 |
| **preflight.sh** | PHPStan 8 + Pint + PHPUnit + tsc + ESLint | `scripts/preflight.sh` |
| **Deptrac** (architecture) | hexagonal layer + module-boundary rules | `apps/api/deptrac.yaml` — `php vendor/bin/deptrac analyse` (currently **70** violations; tracked by the hexagonal-audit work) |
| **react-doctor** (UI) | pre-commit, on staged files | shared `.git/hooks/pre-commit` (always on) |
| **Playwright visual gate** | **opt-in** pre-commit | `scripts/visual-test-gate.sh` (see below) |

> ⚠️ Never run the full PHPUnit suite without permission (crashes the laptop). Run tests by path.

## Deptrac

Already a dev dependency (`deptrac/deptrac ^4.6`) with `apps/api/deptrac.yaml` present. From a worktree with `vendor/` installed:

```bash
cd apps/api && php vendor/bin/deptrac analyse --no-progress
```

## Playwright visual gate (opt-in)

Playwright needs a running dev server + DB, so it is **NOT** a blocking pre-commit gate by default — that would stall every commit across all worktrees. Instead the shared `pre-commit` hook contains a **guarded, opt-in** block (preserving the always-on react-doctor gate):

```sh
# in .git/hooks/pre-commit (after the react-doctor section)
if [ "${RUN_VISUAL_GATE:-0}" = "1" ]; then
  visual_gate="$(git rev-parse --show-toplevel)/scripts/visual-test-gate.sh"
  [ -x "$visual_gate" ] && { "$visual_gate" || exit 1; }
fi
```

**Enable it for a session** (with the app running):

```bash
export RUN_VISUAL_GATE=1   # then commit as normal; UI commits run Playwright
```

The gate auto-skips when no UI files (`*.tsx`, `*.css`, `apps/web/src/`, `apps/web/e2e/`, design tokens, tailwind config) are staged.

**Re-installing the hook block** (git hooks are not version-controlled, so a fresh clone won't have it). The hook lives in the shared git common dir (all worktrees share it). Append the guarded block above to `<repo>/.git/hooks/pre-commit`, or run `scripts/visual-test-gate.sh` manually before committing.

## Merge & autonomy (PRODUCT-BIBLE BD-005)

- Work in a `git worktree` off `dev` (CLAUDE.md rule 21). Merge to **local `dev`** first; promote to **`origin/dev`** as clean fast-forwards; never force-push shared `dev`.
- **Phase A (now):** Houssam is **notified for every merge** and approves/triggers it. A dedicated Bible-aware **merge agent** executes merges, only when Houssam is around.
- **Phase B (later):** per-task-type freedom for low-risk categories once the approach proves itself.
- **Always human eyes:** money / fiscal events / hash chain / GL, DB topology/schema, published API/contract, production promotion.

## Orchestrator backlog

Initial top-5 backlog seeded in `.agents/orchestrator/MANIFEST.yaml` from the Bible's priority gaps (POS COGS, Treasury money-spine, hierarchy-balance fix, inventory-GL, e-facture/automotive).
