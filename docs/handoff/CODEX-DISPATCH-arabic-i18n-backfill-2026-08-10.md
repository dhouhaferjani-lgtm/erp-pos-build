# Codex Dispatch Brief — Arabic (ar) i18n Backfill for apps/web

**Status: DISPATCH-READY (2026-08-10) — audit gated (R5 PASS) and owner scope confirmed.** Owner rulings: Arabic is NOT launch scope but must reach parity (own-pace parallel lane); **phase 1 = launch-critical namespaces (pos, sales, documents, settings, common)**, then the tail to full parity; **Modern Standard Arabic everywhere** (derja possibly later, not now). See `OWNER-DECISIONS-ui-audit-2026-08-10.md`.
**Routing:** Codex CLI cannot write the `apps/erp.*` sibling worktrees (see `reference_codex_cli_sandbox_worktrees`) and must not work on the shared main checkout — use the **Codex desktop route** with a dedicated branch off `origin/dev`, same as the OpenAPI lane.
**Date:** 2026-08-10
**Origin:** UI/presentation audit session; evidence base = `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/04-signal-noise-census.md` §3 (locale parity tables).
**Owner ruling captured 2026-08-10:** all three languages (en/fr/ar) should run in parallel, at least for cheap work such as translation files. This brief is that cheap-work lane.

## Problem statement (audited, code-verified)

`apps/web/src/locales/ar/` is severely incomplete relative to en/fr:

- **23 of 56 namespace files are missing entirely** (exact list: regenerate at dispatch time by diffing `ls locales/en` vs `ls locales/ar` — do not trust this brief's snapshot, the tree moves).
- Existing files have collapsed key counts. Census highlights: `pos.json` 602 (en) → 4 (ar); `sales.json` 704 → 168; `settings.json` 300 → 31; `import.json` 185 → 15.
- en/fr are near-parity (2 namespaces differ by ≤15 keys) — fr is the model for what "done" looks like.

## Scope

**In scope**
1. Create every missing `locales/ar/<ns>.json` and backfill every missing key in existing ar files, to full key parity with `locales/en`.
2. Modern Standard Arabic, register appropriate for a business/POS application used daily by Tunisian retail staff. Prefer established Tunisian-market commercial vocabulary (فاتورة، مخزون، صندوق…) over literal translations.
3. **Arabic plural forms:** Arabic CLDR has six plural categories (`zero/one/two/few/many/other`). Every base key that has `_one`/`_other` (or fr `_many`) variants in en/fr MUST get the full Arabic set where grammatically required. Known pitfall from the fr lane: en-vs-target diffs cannot catch missing plural-suffix keys — enumerate plural bases explicitly (grep for `_one"` in en namespaces and expand).
4. Interpolation placeholders (`{{count}}`, `{{name}}`, etc.) preserved exactly; RTL-safe ordering of placeholder + text.

**Out of scope — do NOT touch**
- Any `.ts`/`.tsx` source (RTL layout/CSS work is a separate lane; see `.claude/context/i18n.md` for the RTL-properties conventions but do not act on them).
- en/fr locale files (parity gaps there are a separate ticket).
- `apps/pos` and `apps/erp-mobile` locales.
- i18n config (`i18n.ts`) unless a missing-namespace registration is required for ar to load — if so, follow the 3-place rule (import, resources, ns array) documented in the repo CLAUDE.md and flag it in the handback.

## Working rules
- Branch off `origin/dev` in a dedicated worktree; never commit on shared `dev`. No `git stash` (repo-global stash across worktrees).
- Note: Codex CLI sandbox cannot write to `apps/erp.*` sibling worktrees — use a worktree location it can write to, or the desktop route (same constraint as the OpenAPI lane, see `reference_codex_cli_sandbox_worktrees`).
- Pure-JSON change set — no preflight backend gates needed, but run `pnpm lint` in apps/web (catches JSON syntax and any i18n lint) and `node apps/web/tools/../../scripts/lint-ratchet.mjs` if wired for locales.

## Verification contract (Definition of Done)
1. Key-parity script output in the handback: for each of the 56 namespaces, en vs ar key counts, delta = 0 (deep key paths, not top-level — use a jq/node one-liner and paste it in the handback for reproducibility).
2. Plural-base enumeration: list of every base with plural suffixes in en and confirmation each has the required ar forms.
3. Placeholder integrity check: script-diff the set of `{{…}}` tokens per key between en and ar — must match exactly.
4. JSON validity: all files parse; app boots with `ar` selected (screenshot or console-clean evidence if the dev stack is available; otherwise state it was not run).
5. Handback report to `docs/handoff/` listing files created/modified, key counts before/after, and any keys deliberately left in English (should be none; if any, justify).

## Review gate (after handback)
Claude-side gate before merge: (1) parity + placeholder scripts re-run by the reviewer, not trusted from the handback; (2) spot-check 30 random keys across POS/sales/settings for register and correctness (native-quality MSA, no machine-translation artifacts); (3) frontend-conventions-reviewer pass is NOT needed (no .tsx changes) unless i18n.ts was touched.

## Open items — RESOLVED 2026-08-10
- OQ-A1: **RULED** — launch-critical namespaces first (pos, sales, documents, settings, common) = phase 1 of this brief; tail to 100% parity in subsequent runs, own pace.
- OQ-A2: **RULED** — Modern Standard Arabic everywhere. No derja for now.
