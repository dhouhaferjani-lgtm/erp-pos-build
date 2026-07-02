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

const TINT_CLASS: Record<CategoryTint, string> = {
  visage: 'bg-[var(--cat-visage-bg)] text-[var(--cat-visage-fg)]',
  solaire: 'bg-[var(--cat-solaire-bg)] text-[var(--cat-solaire-fg)]',
  corps: 'bg-[var(--cat-corps-bg)] text-[var(--cat-corps-fg)]',
  cheveux: 'bg-[var(--cat-cheveux-bg)] text-[var(--cat-cheveux-fg)]',
  bebe: 'bg-[var(--cat-bebe-bg)] text-[var(--cat-bebe-fg)]',
  complements: 'bg-[var(--cat-complements-bg)] text-[var(--cat-complements-fg)]',
  hygiene: 'bg-[var(--cat-hygiene-bg)] text-[var(--cat-hygiene-fg)]',
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
  const tint = tintForCategory(category);
  // Square thumbs use large initials; full-width POS tiles match the mock's
  // compact 20px initials centered in an 88px-high image area.
  const fontSize = fullWidth ? 20 : Math.round(size * 0.34);
  return (
    <div
      style={dim}
      aria-hidden
      className={cn(
        'flex shrink-0 select-none items-center justify-center rounded-tile font-display font-bold tracking-tight',
        TINT_CLASS[tint],
        className,
      )}
    >
      <span style={{ fontSize }}>{initialsFromName(name)}</span>
    </div>
  );
}
