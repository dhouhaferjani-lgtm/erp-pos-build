import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { ProductThumb, initialsFromName, tintForCategory, tintFromSeed } from '../ProductThumb';

describe('initialsFromName', () => {
  it('takes the first letter of the first two words', () => {
    expect(initialsFromName('Crème Hydratante')).toBe('CH');
  });
  it('takes the first two letters of a single word', () => {
    expect(initialsFromName('Doliprane')).toBe('DO');
  });
  it('handles empty / whitespace safely', () => {
    expect(initialsFromName('')).toBe('?');
    expect(initialsFromName('   ')).toBe('?');
  });
  it('uppercases', () => {
    expect(initialsFromName('avène eau')).toBe('AE');
  });
});

describe('tintForCategory', () => {
  it('maps known parapharmacy categories to a tint key', () => {
    expect(tintForCategory('Visage')).toBe('visage');
    expect(tintForCategory('solaire')).toBe('solaire');
  });
  it('falls back to neutral for unknown categories', () => {
    expect(tintForCategory('Quincaillerie')).toBe('neutral');
    expect(tintForCategory(undefined)).toBe('neutral');
  });
});

describe('ProductThumb', () => {
  it('renders initials when there is no image', () => {
    const { getByText } = render(<ProductThumb name="Crème Hydratante" category="Visage" />);
    expect(getByText('CH')).toBeTruthy();
  });

  it('renders an <img> with alt when an imageUrl is provided', () => {
    const { getByRole } = render(
      <ProductThumb name="Doliprane" imageUrl="https://example.test/p.png" />,
    );
    const img = getByRole('img') as HTMLImageElement;
    expect(img.getAttribute('alt')).toBe('Doliprane');
    expect(img.getAttribute('src')).toContain('p.png');
  });

  it('renders the full-width placeholder with token-based tints, not hardcoded hex', () => {
    const { getByTestId } = render(<ProductThumb name="AS Test" fullWidth />);
    const tile = getByTestId('product-thumb-placeholder');
    expect(tile.className).not.toContain('#eef3f8');
    expect(tile.className).not.toContain('#5e6670');
    // No-category products now derive a deterministic tint from the existing
    // --cat-* token set instead of uniform neutral gray.
    expect(tile.className).toContain('var(--cat-');
    expect(tile.getAttribute('style')).toContain('width: 100%');
  });

  it('categorized placeholders use the category tint surface (fg-tinted, token-based)', () => {
    const { getByTestId } = render(<ProductThumb name="Crème Hydratante" category="Visage" />);
    const tile = getByTestId('product-thumb-placeholder');
    expect(tile.className).toContain('var(--cat-visage-fg)');
  });
});

describe('tintFromSeed — deterministic no-category tint (owner polish, sub-task b)', () => {
  it('is deterministic: the same seed always yields the same tint', () => {
    expect(tintFromSeed('Doliprane 1000mg')).toBe(tintFromSeed('Doliprane 1000mg'));
  });

  it('never returns neutral for a non-empty seed', () => {
    expect(tintFromSeed('Doliprane 1000mg')).not.toBe('neutral');
  });

  it('spreads different names across the tint set (grid is not uniform gray)', () => {
    const names = [
      'Doliprane 1000mg',
      'Avène Eau Thermale',
      'CeraVe Crème',
      'Elgydium Clinic',
      'Beurer Vessie',
      'Gum Dentifrice',
      'Isdin Flavo-C',
    ];
    const tints = new Set(names.map(tintFromSeed));
    expect(tints.size).toBeGreaterThan(1);
  });

  it('no-category thumbs apply the seed tint in the DOM', () => {
    const first = render(<ProductThumb name="Doliprane 1000mg" />);
    const second = render(<ProductThumb name="Doliprane 1000mg" />);
    const a = first.container.querySelector('[data-testid="product-thumb-placeholder"]')!.className;
    const b = second.container.querySelector('[data-testid="product-thumb-placeholder"]')!.className;
    expect(a).toBe(b);
    expect(a).toContain('var(--cat-');
  });
});
