/**
 * Theme application for the Caisse Parapharmacie redesign.
 *
 * Five runtime knobs are expressed as `data-*` attributes on the document root
 * (`<html>`). The CSS token layer in `index.css` reads them via attribute
 * selectors (`[data-theme='dark']`, `[data-accent='teal']`, …) and re-points the
 * semantic `--color-*` tokens, so every tokenized component flips with no JS.
 *
 * `density` and `cartPosition` are also surfaced as attributes for any
 * presentational CSS, but the virtualized ProductGrid reads `density` from the
 * settings store directly (column count is computed in JS, not CSS).
 */

export const THEME_MODES = ['light', 'dark'] as const;
export const ACCENTS = ['orange', 'green', 'blue', 'teal'] as const;
export const CORNER_STYLES = ['rounded', 'sharp'] as const;
export const DENSITIES = ['comfortable', 'dense'] as const;

export type ThemeMode = (typeof THEME_MODES)[number];
export type AccentName = (typeof ACCENTS)[number];
export type CornerStyle = (typeof CORNER_STYLES)[number];
export type Density = (typeof DENSITIES)[number];
export type CartPosition = 'start' | 'end';

export interface ThemeSettings {
  theme: ThemeMode;
  accent: AccentName;
  corner: CornerStyle;
  density: Density;
  cartPosition: CartPosition;
}

export const DEFAULT_THEME_SETTINGS: ThemeSettings = {
  theme: 'light',
  accent: 'orange',
  corner: 'rounded',
  density: 'comfortable',
  cartPosition: 'start',
};

/**
 * Write the theme knobs onto a root element as `data-*` attributes. Pure and
 * idempotent — safe to call on every settings change.
 */
export function applyTheme(root: HTMLElement, settings: ThemeSettings): void {
  root.dataset.theme = settings.theme;
  root.dataset.accent = settings.accent;
  root.dataset.corner = settings.corner;
  root.dataset.density = settings.density;
  root.dataset.cartSide = settings.cartPosition;
}
