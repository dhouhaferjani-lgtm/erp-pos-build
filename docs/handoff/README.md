# Handoff: IZI POS — Crisp / Operational design language

## Overview
Re-skin the dashboard / desktop app and rebuild the **product editor** for IZI POS, a universal **CRM / ERP / POS**. Two things ship here: (1) a **global design language** that makes *every* page consistent, and (2) the **product-page layout** as the first screen built to it.

## About the design files
The `.dc.html` files in this bundle are **design references** — HTML prototypes showing the intended look, structure, and behavior. They are **not production code to copy**. The task is to **recreate them in the existing `apps/web` React + Tailwind environment** using its established patterns (`lib/designTokens.ts`, the `tokens.*` system, existing components). Only `izipos-theme.css` is meant to be pasted in more or less as-is.

## Fidelity
**High-fidelity.** Colors, type, spacing, radii, and states are final. Recreate pixel-faithfully using the codebase's libraries — don't ship the HTML.

## How to apply it — two layers

### Layer 1 — Global language (makes ALL pages consistent, ~zero component edits)
This is the answer to "how do the rest of the pages follow the same design?":

1. **Paste `izipos-theme.css`** into `apps/web/src/index.css` after the `[data-product="otospex"]` block, and resolve the IziPOS vertical to `product="izipos"` (`DashboardLayout` already sets `data-product`). The existing `@theme` bridge maps every `blue-*` / `gray-*` / `primary-*` / `secondary-*` utility onto these vars — so **every existing button, focus ring, nav state, badge, and chart re-skins to navy + orange automatically**, across all pages, with no per-component edits.
2. **Wire the structural tokens** (bottom of `izipos-theme.css`: `--radius-*`, `--border-hairline`, `--elevation-*`, `--font-*`) into `lib/designTokens.ts` so cards/inputs/buttons read them. This propagates the *Crisp / Operational* structure (hairline borders over shadows, tight corners, mono numerics) everywhere — not just color.
3. **Run your `Hallmark` skill** to enforce the now-decided language across screens (it polishes/aligns code to the spec — it is not where the look gets invented; that's already decided here).

Result: colors are global immediately; structure becomes global as components adopt the token layer. New pages inherit the language by using `tokens.*`.

### Layer 2 — The product page (build this screen to the language)
- **Layout:** `IZI POS - Add Product.dc.html` — scroll + sticky section nav (Variation B), slimmed barcode-first hero, opening-balance inventory, right-rail shortcuts.
- **Visual treatment:** `IZI POS - Add Product (Modern Directions).dc.html` → **Direction A**. Apply its crisp treatment (flat hairline cards, 10px corners, mono numerics, `01/02/03` section markers) over that layout.
- Full layout, data-model gaps, and enrichment behavior are specified in **`HANDOFF-izipos-design.md` §3–§4**.

## Visual-language rules (Direction A)
See `HANDOFF-izipos-design.md` §0. In short: flat cards with a 1px `#E0E4E9` hairline (no resting shadow); shadow only for overlays; corners 6/8/10/16px; IBM Plex Mono for all aligned numbers; one orange action per screen; semantic color for status only; focus ring is the single orange-over-navy moment.

## Atomic structure
See `HANDOFF-izipos-design.md` §0b — atoms (tokens, button, input, badge…) → molecules (form field, nav item, KPI tile…) → organisms (sidebar, top bar, section card, table…) → templates. `lib/designTokens.ts` is the atom layer.

## Design tokens
- **Color / structure tokens:** `izipos-theme.css` (complete, drop-in).
- **Design-system reference:** `IZI POS Design System.dc.html` — full color scales, type, spacing, components, dark mode, UX principles, rendered.

## Files in this bundle
| File | Role |
|------|------|
| `README.md` | This file — start here. |
| `HANDOFF-izipos-design.md` | Deep spec: visual language (§0), atomic map (§0b), theme (§2), product editor (§3), data-model gaps (§4), build order (§5). |
| `izipos-theme.css` | **The keystone.** Drop-in theme + structural tokens. |
| `mocks/*.dc.html` | **Pixel-precise source.** Self-contained HTML with exact inline styles (hex, spacing, radii). **Read values from these** — more reliable than the screenshots. Open in any browser (assets + runtime are included alongside). `IZI POS - Add Product (Modern Directions).dc.html` → **Direction A** is the chosen product-page treatment. |
| `screenshots/*.png` | Visual ground truth: `product-page-direction-A*.png` (the editor) and `0X-design-system.png` (tokens, buttons, color, data). |

> **To match the product page:** read `mocks/IZI POS - Add Product (Modern Directions).dc.html` (Direction A frame) for exact pixels, use `IZI POS - Add Product.dc.html` for the full layout/sections, and cross-check against `screenshots/product-page-direction-A*.png`.
