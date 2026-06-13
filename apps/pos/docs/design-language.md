# IziPOS Design Language

> The locked design system for `apps/pos`. Every new screen and component must follow this. Enforced by the ESLint hardcoded-color guard (`eslint.config.js`) and `tsc`. Iterable — propose changes via PR to this file + `src/index.css` `@theme` + `src/lib/designTokens.ts` together.

Established 2026-06-13 from the Hallmark UI audit. Source of truth for tokens: `src/index.css` (`@theme`) and `src/lib/designTokens.ts`.

---

## 1. Color grammar (non-negotiable)

Color carries meaning. Do not reuse a status color for decoration.

| Role | Token family | Use for | Never use for |
|---|---|---|---|
| **action** (ocean blue) | `action`, `action-hover`, `action-subtle`, `action-strong` | Interactive/primary actions, selected state | Prices, decoration |
| **success** (green) | `success`, `success-surface`, `success-strong`, `success-subtle` | Confirmed money & sync events ONLY (completed sale, "synced", change due) | Generic "good" states, in-stock counts |
| **warning** (amber) | `warning`, `warning-surface`, `warning-strong`, `warning-subtle` | Warnings, low stock, "caution" actions (e.g. change terminal) | Errors |
| **danger** (red) | `danger`, `danger-surface`, `danger-strong`, `danger-subtle` | Errors + destructive/irreversible actions ONLY | Out-of-stock, backspace keys, generic emphasis |
| **brand** (copper) | `brand` | Wordmark, brand chrome | Buttons, body text |
| **ink** | `ink`, `ink-muted`, `ink-faint`, `ink-inverse` | All text; **prices use `ink`** | — |

Out-of-stock = desaturated surface (`surface-sunken`) + neutral badge, NOT red. Low-stock = amber dot + `warning-strong`.

## 2. Surface scale (elevation = separation)

The fix for "weak separation": elevation comes from the surface scale, not from borders alone.

- `surface-canvas` — app background (slightly tinted, neutral-100). The page sits here.
- `surface-raised` — panels & cards (white + `shadow-sm`). The cart (money zone) is raised against the canvas.
- `surface-overlay` — modals (white + `shadow-2xl` + `bg-black/50` scrim).
- `surface-sunken` — inset boxes, disabled controls, neutral keypad keys.

## 3. Components — one voice (from `tokens` in `designTokens.ts`)

- **Buttons:** `tokens.button.{primary,confirm,secondary,ghost,destructive}`. Primary = the one strong action per view. Confirm = money completion (green). Destructive = irreversible (red). Pair with a min-height (`min-h-[56px]` for touch).
- **Badges/pills:** `tokens.badge.{neutral,success,warning,danger}`.
- **Choose-one controls:** `tokens.segmented` — the ONLY segmented/chip-group voice. Do not hand-roll a second one.
- **Header status:** `tokens.statusPill` (healthy/warning/danger).
- **Disabled controls:** `tokens.disabledReason` — communicate disabled by surface + ink + cursor, **never opacity alone**, and show the reason near the control. A faded primary button reads as "is this on?".

## 4. Typography & numbers

- Font: Inter (system stack fallback). Roles: display amount > section heading > body > caption.
- **All monetary & quantity values use `tabular-nums`** (utility class `tabular-nums` or `tokens.money`). Columns of money must align. TND is 3-decimal — keypads include a `000` key.

## 5. Touch targets

- Tactile mode: ≥ 48px. Desktop: ≥ 40px. Clickable text never wraps to two lines (`whitespace-nowrap` + shorten the label).

## 6. Status & alarm discipline

- Healthy/normal states are quiet (a header pill), not full-width banners. Surface a banner ONLY when something needs attention (elevated/escalated). This matches the POS fail-closed philosophy and prevents alarm fatigue.

## 7. PR checklist (design)

- [ ] No raw Tailwind palette classes (`bg-blue-600`, `text-gray-500`, …). Use semantic tokens. (ESLint errors on migrated dirs.)
- [ ] Color grammar respected (success = money/sync, danger = error/destructive only).
- [ ] Surfaces use the elevation scale; money zones read as raised.
- [ ] Text contrast ≥ 4.5:1; UI/border contrast ≥ 3:1.
- [ ] Monetary values use `tabular-nums`.
- [ ] Interactive elements: visible disabled treatment (not opacity-only) + focus-visible ring; touch targets meet the minimum.
- [ ] Choose-one controls use `tokens.segmented`; buttons use `tokens.button.*`.
- [ ] All user-facing strings via `t()` (no hardcoded English/French).

## 8. Adding a new screen/dir

When a new directory is fully tokenized, add its glob to `tokenMigratedGlobs` in `eslint.config.js` so the color guard enforces it at `error`.
