# Start here — ERP hardening continuation (Dhouha), 2026-09-02

You're picking up the ERP hardening effort for our first tenant. Everything is on `origin/dev` (commit `79fb56505`) and deployed to staging (`https://erp.otospex.dev` / API `https://api.erp.otospex.dev`). Start by pulling `dev` and reading, in this order:

1. **`docs/handoff/WAVE-1-IMPORTS-HANDOFF-2026-09-02.md`** — what shipped in the imports/enrichment wave, the exact behaviour of our real test files, and the open items.
2. **`docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md`** — your wave-2 (purchase-order) continuation, including environment recipe and the ops notes (the permission precondition is already satisfied automatically on staging — verified).
3. **`docs/qa/MANUAL-TESTING-LOOP.md`** — our testing method: fresh tenant every time, the §2 journey, and the bug-report shape (use it for every finding, one report per bug).

## How we work (non-negotiable)
- One focused wave at a time.
- Every step is tested in the real UI, not just the API, including edge cases.
- A step is "done" only with committed evidence (spec file, environment, counts) in `docs/superpowers/reviews/`.
- Browser tests must capture and assert **zero 5xx responses and zero console errors**.
- Fixes go through Claude/Codex lanes with an adversarial review gate *before* implementation — briefs first, never ad-hoc patches.

When you start a Claude Code session in `apps/erp/`, tell it:

> Read docs/handoff/WAVE-1-IMPORTS-HANDOFF-2026-09-02.md and docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md, then continue under the focused-waves discipline described there.

## Priorities, in order

1. **Fix the open issue K-14** — opening-balance import reports "imported / Completed" while posting nothing when the referenced account doesn't exist. The gated brief is at `docs/superpowers/briefs/LANE-K14-opening-balance-silent-nopost-BRIEF.md`; the currently-red test `tests/Feature/Import/ImportTypesTest.php:438` must turn green *for the right reason*.
2. **Continue wave 2 (purchase orders)** per your handover: finish the scenario matrix (partial receipts, batch/expiry lots, price overrides, landed costs, cancellations, mixed VAT incl. 0 %, permissions per role), run it on staging, and file findings as gated briefs. Note: the PO "total mode" gross-price bug is already owned by lane K-1 — reproduce and reference it, don't re-fix it.
3. **Then wave 3 (stock transfers between branches) and wave 4 (treasury: expenses, categories, payments)** — same method: research the code first, write the scenario matrix with edge cases, get it gate-reviewed, script it in Playwright, run local then staging.
4. **Manual desktop POS testing (IziPOS Tauri app)** — this stays manual, on the current staging build: full cashier day on a fresh tenant (open shift with float → cash and card sales incl. batch-tracked products → a refund → account-charge/credit sale if enabled → cash count → close shift → Z-report), plus offline/reconnect behaviour and receipt printing. After every money step ask the standard question: *where did it land* — drawer/repository, GL, stock — and do the three agree? Report findings with the bug-report shape from the testing loop doc.

## Data facts about our own test files (not bugs — don't chase them)
- `model produits.xlsx` has Excel-corrupted barcodes (only 180 distinct across 859 rows — the import now refuses those rows with named reasons, **by design**) and three rows with negative quantities (141, 160, 827).
- With units mapped once (Settings → Units: `piece → pc`) and barcodes cleaned, the file imports **856 / 3**.

Anything unclear: the evidence trail with every number and screenshot reference is in `docs/superpowers/reviews/2026-08-31-wave1-imports-local-evidence.md`.

Good luck — the build you're inheriting is green end-to-end on everything above.
