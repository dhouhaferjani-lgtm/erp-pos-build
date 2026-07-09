import { cn } from '@/lib/utils';
import type { CSSProperties } from 'react';

/**
 * ProductThumb — the tinted-initials product tile used across the grid, cart,
 * and detail. Falls back to category-tinted initials when no product image is
 * available (the mock has no photos; real images slot in when present).
 */

export type CategoryTint =
  | 'visage'
  | 'solaire'
  | 'corps'
  | 'cheveux'
  | 'bebe'
  | 'complements'
  | 'hygiene'
  | 'neutral';

/** First letter of the first two words, or first two letters of one word. */
export function initialsFromName(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return '?';
  if (words.length === 1) {
    const w = words[0] ?? '';
    return (w.slice(0, 2) || '?').toUpperCase();
  }
  return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase() || '?';
}

const CATEGORY_TINTS: CategoryTint[] = [
  'visage',
  'solaire',
  'corps',
  'cheveux',
  'bebe',
  'complements',
  'hygiene',
];

/** Map a free-text product category to a known tint key (else 'neutral'). */
export function tintForCategory(category?: string | null): CategoryTint {
  if (!category) return 'neutral';
  const c = category.trim().toLowerCase();
  const hit = CATEGORY_TINTS.find((t) => c.includes(t));
  return hit ?? 'neutral';
}

/**
 * Deterministic tint for products with no mapped category (owner polish
 * 2026-07-09, sub-task b): hash the seed (product name) over the SAME
 * `--cat-*` tint set so a grid of no-category placeholders isn't a uniform
 * gray wall. Same seed → same tint, stable across renders and sessions. The
 * tint carries no category meaning here — it is purely visual identity.
 * Reuses existing tokens only; no new colors.
 */
export function tintFromSeed(seed: string): CategoryTint {
  const s = seed.trim();
  if (s.length === 0) return 'neutral';
  let h = 0;
  for (let i = 0; i < s.length; i++) {
    h = (h * 31 + s.charCodeAt(i)) >>> 0;
  }
  return CATEGORY_TINTS[h % CATEGORY_TINTS.length] ?? 'neutral';
}

/**
 * Tint surface = the category FOREGROUND mixed 18% into the theme surface
 * (the same `color-mix(… , var(--surface))` pattern `src/index.css` uses for
 * dark-mode status surfaces). This reads slightly stronger than the old
 * pastel `--cat-*-bg` values (which were near-white-on-white — the
 * "washed-out wall" from the owner's screenshots) AND stays theme-correct in
 * dark mode, where the pastel light backgrounds never had a dark override.
 * Initials keep the curated `--cat-*-fg` foreground.
 */
const TINT_CLASS: Record<CategoryTint, string> = {
  visage:
    'bg-[color-mix(in_srgb,var(--cat-visage-fg)_18%,var(--surface))] text-[var(--cat-visage-fg)]',
  solaire:
    'bg-[color-mix(in_srgb,var(--cat-solaire-fg)_18%,var(--surface))] text-[var(--cat-solaire-fg)]',
  corps:
    'bg-[color-mix(in_srgb,var(--cat-corps-fg)_18%,var(--surface))] text-[var(--cat-corps-fg)]',
  cheveux:
    'bg-[color-mix(in_srgb,var(--cat-cheveux-fg)_18%,var(--surface))] text-[var(--cat-cheveux-fg)]',
  bebe:
    'bg-[color-mix(in_srgb,var(--cat-bebe-fg)_18%,var(--surface))] text-[var(--cat-bebe-fg)]',
  complements:
    'bg-[color-mix(in_srgb,var(--cat-complements-fg)_18%,var(--surface))] text-[var(--cat-complements-fg)]',
  hygiene:
    'bg-[color-mix(in_srgb,var(--cat-hygiene-fg)_18%,var(--surface))] text-[var(--cat-hygiene-fg)]',
  neutral: 'bg-surface-sunken text-ink-muted',
};

export interface ProductThumbProps {
  name: string;
  category?: string | null;
  imageUrl?: string | null;
  /** Square edge in px. Default 88 (visual card). */
  size?: number;
  /** Render as the full-width POS image tile from the Caisse mock. */
  fullWidth?: boolean;
  className?: string;
}

export function ProductThumb({
  name,
  category,
  imageUrl,
  size = 88,
  fullWidth = false,
  className,
}: ProductThumbProps) {
  const dim: CSSProperties = { width: fullWidth ? '100%' : size, height: size };
  if (imageUrl) {
    return (
      <img
        src={imageUrl}
        alt={name}
        style={dim}
        className={cn('shrink-0 rounded-tile object-cover', className)}
      />
    );
  }
  // Category tint when the category maps; otherwise a deterministic tint
  // derived from the name so no-category grids don't collapse to uniform gray.
  const categoryTint = tintForCategory(category);
  const tint = categoryTint === 'neutral' ? tintFromSeed(name) : categoryTint;
  const tintClass = TINT_CLASS[tint];
  // Square thumbs use large initials; full-width POS tiles match the mock's
  // compact 20px initials centered in an 88px-high image area.
  const fontSize = fullWidth ? 20 : Math.round(size * 0.34);
  return (
    <div
      data-testid="product-thumb-placeholder"
      style={dim}
      aria-hidden
      className={cn(
        'flex shrink-0 select-none items-center justify-center rounded-tile font-display font-bold tracking-tight',
        tintClass,
        className,
      )}
    >
      {/* Category tints already carry a curated `--cat-*-fg` foreground (via
          `tintClass` on the parent) tuned to pair with their own background —
          leave those alone. Only the neutral tint's `text-ink-muted` reads
          washed-out for initials, so bump that one case to the stronger
          `text-ink` token; everything else inherits the parent's tint color. */}
      <span className={tint === 'neutral' ? 'text-ink' : undefined} style={{ fontSize }}>
        {initialsFromName(name)}
      </span>
    </div>
  );
}
