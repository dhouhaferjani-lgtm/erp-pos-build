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

/** Visual mode (image card): image (80px) + name (2 lines) + price + stock + p-4.
 *  Kept at 220 to match the existing virtualizer estimate — do NOT lower without
 *  visual verification at 1366×768, 1280×720, and 1024×600. */
export const CARD_MIN_H_VISUAL = 220;

export const ROW_HEIGHT_GRID = CARD_MIN_H_GRID;
export const ROW_HEIGHT_VISUAL = CARD_MIN_H_VISUAL;

/**
 * Tailwind JIT class literals — kept here so a single string-literal
 * appears in the source for the scanner to extract. Never compute these
 * at runtime from CARD_MIN_H_*; the JIT will not pick up dynamic strings.
 */
export const CARD_MIN_H_CLASS_GRID = 'min-h-[140px]';
export const CARD_MIN_H_CLASS_VISUAL = 'min-h-[220px]';
