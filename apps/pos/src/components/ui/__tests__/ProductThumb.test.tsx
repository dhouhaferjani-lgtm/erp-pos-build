import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { ProductThumb, initialsFromName, tintForCategory } from '../ProductThumb';

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

  it('renders the full-width neutral placeholder with tokens, not hardcoded hex', () => {
    const { getByTestId } = render(<ProductThumb name="AS Test" fullWidth />);
    const tile = getByTestId('product-thumb-placeholder');
    expect(tile.className).not.toContain('#eef3f8');
    expect(tile.className).not.toContain('#5e6670');
    expect(tile.className).toContain('bg-surface-sunken');
    expect(tile.getAttribute('style')).toContain('width: 100%');
  });
});
