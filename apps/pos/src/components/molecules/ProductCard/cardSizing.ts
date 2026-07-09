// apps/pos/src/components/molecules/ProductCard/cardSizing.ts

/**
 * Single source of truth for product-card and product-grid sizing.
 *
 * Cards are CONTENT-SIZED (they match the mock, which pins no card height).
 * The virtualizer measures each row's real height at runtime
 * (`virtualizer.measureElement`), so the `CARD_MIN_H_*` values below are only
 * the INITIAL estimate used before the first measurement — they no longer
 * have to match the rendered height exactly (an off estimate just causes a
 * one-frame scrollbar correction, never clipping).
 */
export const GAP = 12;

/** Grid/compact card estimate: brand + 2-line name + price/stock row + padding. */
export const CARD_MIN_H_GRID = 92;

/** Grid mode, dense — same content, narrower; estimate unchanged. */
export const CARD_MIN_H_GRID_DENSE = 92;

/** Visual card estimate: 72 thumb + gap + brand + 2-line name + stacked price/stock + padding.
 * (Owner polish 2026-07-09: tile 88→72, name slot 2.5rem→2.25rem → 244→224.) */
export const CARD_MIN_H_VISUAL = 224;

/** Visual mode, dense — same content; narrow columns keep the stacked footer. */
export const CARD_MIN_H_VISUAL_DENSE = 224;

/**
 * Deterministic two-line slot for the product name.
 *
 * `line-clamp-2` clamps the VISIBLE text but leaves the box height driven by
 * the actual content: a one-line name yields a one-line box, a long name a
 * two-line box. In a `flex-col` card that variance let a sliced third line
 * leak and the price/stock rows shift up to overlap the name. Pinning a fixed
 * min-height equal to two text lines gives every card the SAME name region, so
 * price/stock are always anchored below it regardless of name length.
 *
 * Heights = 2 × line-height. Both modes render the name with the SHARED
 * `tokens.productName.base` recipe (13.5px × lh 1.3 ≈ 17.55px/line → two
 * lines ≈ 35.1px), so both slots pin the same 2.25rem (36px) — the old
 * 3rem/2.5rem values were computed for type scales the card no longer uses
 * and left dead vertical space between name and price (owner 2026-07-09).
 * As JIT literals.
 */
export const CARD_NAME_MIN_H_CLASS_GRID = 'min-h-[2.25rem]';
export const CARD_NAME_MIN_H_CLASS_VISUAL = 'min-h-[2.25rem]';

/**
 * Density-aware column count for the product grid.
 *
 * Breakpoints match Tailwind's default (sm=640, lg=1024).
 * Column matrix at lg (≥1024px):
 *   visual + comfortable = 5 | visual + dense = 6
 *   grid  + comfortable = 4 | grid  + dense = 5
 *
 * Pass `window.innerWidth` (or 0 for SSR) — defaults to xs (smallest bucket).
 */
export function getColumns(
  displayMode: 'grid' | 'visual',
  density: 'comfortable' | 'dense',
  width: number,
): number {
  if (displayMode === 'visual') {
    if (width >= 1024) return density === 'dense' ? 6 : 5;
    if (width >= 640) return density === 'dense' ? 5 : 4;
    return density === 'dense' ? 4 : 3;
  }
  // grid / compact mode
  if (width >= 1024) return density === 'dense' ? 5 : 4;
  if (width >= 640) return density === 'dense' ? 4 : 3;
  return density === 'dense' ? 3 : 2;
}

/**
 * Density-aware initial row-height ESTIMATE for the virtualizer. The real row
 * height is measured at runtime (see file header); this only seeds the first
 * paint.
 */
export function getCardMinH(
  displayMode: 'grid' | 'visual',
  density: 'comfortable' | 'dense',
): number {
  if (displayMode === 'visual') {
    return density === 'dense' ? CARD_MIN_H_VISUAL_DENSE : CARD_MIN_H_VISUAL;
  }
  return density === 'dense' ? CARD_MIN_H_GRID_DENSE : CARD_MIN_H_GRID;
}
