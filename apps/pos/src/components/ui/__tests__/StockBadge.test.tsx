import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { StockBadge } from '../StockBadge';

describe('StockBadge', () => {
  it('renders the label for each stock status', () => {
    const { getByText } = render(
      <>
        <StockBadge status="ok">En stock</StockBadge>
        <StockBadge status="low">Stock faible</StockBadge>
        <StockBadge status="out">Rupture</StockBadge>
      </>,
    );
    expect(getByText('En stock')).toBeTruthy();
    expect(getByText('Stock faible')).toBeTruthy();
    expect(getByText('Rupture')).toBeTruthy();
  });

  it('exposes the status via a data attribute (stock semantics, not money/error tones)', () => {
    const { getByText } = render(<StockBadge status="out">Rupture</StockBadge>);
    const el = getByText('Rupture').closest('[data-status]');
    expect(el?.getAttribute('data-status')).toBe('out');
  });

  it('forwards extra props/className', () => {
    const { getByText } = render(
      <StockBadge status="ok" className="my-extra" data-testid="sb">
        En stock
      </StockBadge>,
    );
    const el = getByText('En stock').closest('span');
    expect(el?.className).toContain('my-extra');
  });
});
