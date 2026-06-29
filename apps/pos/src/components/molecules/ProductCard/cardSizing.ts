// apps/pos/src/components/molecules/ProductCard/cardSizing.ts

/**
 * Single source of truth for product-card and product-grid sizing.
 *
 * The virtualizer's row height MUST equal CARD_MIN_H_* + GAP for the grid
 * to avoid clipping the bottom of cards on long product names.
 */
export const GAP = 12;

/** Grid mode (text-first card): name (2 lines) + price + stock + p-4. */
export const CARD_MIN_H_GRID = 140;

/** Grid mode, dense density — tighter vertical rhythm. */
export const CARD_MIN_H_GRID_DENSE = 112;

/** Visual mode (image card): image (80px) + name (2 lines) + price + stock + p-4.
 *  Kept at 220 to match the existing virtualizer estimate — do NOT lower without
 *  visual verification at 1366×768, 1280×720, and 1024×600. */
export const CARD_MIN_H_VISUAL = 220;

/** Visual mode, dense density — reduced image area for tighter layout. */
export const CARD_MIN_H_VISUAL_DENSE = 176;

export const ROW_HEIGHT_GRID = CARD_MIN_H_GRID;
export const ROW_HEIGHT_VISUAL = CARD_MIN_H_VISUAL;

/**
 * Tailwind JIT class literals — kept here so a single string-literal
 * appears in the source for the scanner to extract. Never compute these
 * at runtime from CARD_MIN_H_*; the JIT will not pick up dynamic strings.
 */
export const CARD_MIN_H_CLASS_GRID = 'min-h-[140px]';
export const CARD_MIN_H_CLASS_VISUAL = 'min-h-[220px]';

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
 * Heights = 2 × line-height. Grid uses `text-base` (1rem / lh 1.5rem → 3rem),
 * visual uses `text-sm` (0.875rem / lh 1.25rem → 2.5rem). As JIT literals.
 */
export const CARD_NAME_MIN_H_CLASS_GRID = 'min-h-[3rem]';
export const CARD_NAME_MIN_H_CLASS_VISUAL = 'min-h-[2.5rem]';

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
 * Density-aware card min-height for the virtualizer row estimate.
 *
 * Use this wherever `CARD_MIN_H_GRID` / `CARD_MIN_H_VISUAL` was used so that
 * dense layouts get a tighter row estimate, reducing wasted space.
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
