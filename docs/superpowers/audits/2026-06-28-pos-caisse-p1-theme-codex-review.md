# Codex adversarial review — P1 theme foundation (commit 85abcecac)

Reviewer: Codex (gpt-5-class), 2026-06-28. Branch `feat/pos-caisse-redesign`.
Verdict: **No CRITICAL or HIGH bugs.** Tailwind v4 `@theme inline` + same-element
`data-theme`/`data-accent` cascade confirmed sound (incl. dark `--accent-tint`
source-order). Zustand persist merges old `izipos-settings` with new defaults —
no backward-compat blocker. Two real MED issues + two LOW.

## Findings & resolutions

| # | Sev | Finding | Resolution |
|---|-----|---------|------------|
| 1 | MED | `--color-success-subtle` / `warning-subtle` / `danger-subtle` were dropped in the index.css rewrite — `Badge` atom uses `border-success-subtle` etc. → broken borders. | **FIXED** (commit follows). Restored raw `-subtle` vars (light) + dark `color-mix` overrides + `@theme inline` mappings. Verified in-browser: `border-success-subtle` → `#B8DEC8` (light), dark blend resolves. |
| 2 | MED | Re-pointing `--color-action` to accent leaves `bg-action text-ink-inverse` (white on orange ≈ 3.2:1) below 4.5:1 for small text. | **Addressed + documented.** Added explicit `--color-action-fg` (= `--accent-text`, white) token for tunability, and an index.css note: white-on-accent passes WCAG AA only for LARGE/BOLD text (3:1). Primary CTAs (Encaisser/Valider) are `lg` + bold → qualify. Rule: never put small/normal-weight text on bare `bg-action`. Accent hue preserved per design intent (user granted leeway); revisit if a non-orange demo needs darker accent. |
| 3 | LOW | Legacy `primary-*` utilities are static (not themed) → `bg-primary-*` consumers won't pick up accent. | **Already planned** (spec §3.2 / §8b): `primary-*` kept as non-themed chrome (header etc.); rogue `primary-*`/`blue-*` CTA/selection refs swept to `action`/`accent` per phase as each surface is restyled. |
| 4 | LOW | IBM Plex Mono bold (grand total = mono-bold) had no self-hosted 700 face; `font-synthesis: none` ⇒ no faux-bold. | **FIXED.** Added `@fontsource/ibm-plex-mono/latin-700.css`. |

All four addressed in the follow-up commit (subtle tokens + action-fg + mono-700)
alongside the first redesign atoms (StockBadge, ProductThumb).
