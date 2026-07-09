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

  it('always shows the number for ok/low, word only for out — same pill shape', () => {
    const ok = render(<StockBadge status="ok">{'Stock 12'}</StockBadge>);
    expect(ok.getByText('Stock 12')).toBeTruthy();
    const okPill = ok.getByText('Stock 12').closest('[data-status]');
    expect(okPill?.getAttribute('data-status')).toBe('ok');

    const low = render(<StockBadge status="low">{'Stock 3'}</StockBadge>);
    expect(low.getByText('Stock 3')).toBeTruthy();
    const lowPill = low.getByText('Stock 3').closest('[data-status]');
    expect(lowPill?.getAttribute('data-status')).toBe('low');

    const out = render(<StockBadge status="out">{'Rupture'}</StockBadge>);
    expect(out.getByText('Rupture')).toBeTruthy();
    const outPill = out.getByText('Rupture').closest('[data-status]');
    expect(outPill?.getAttribute('data-status')).toBe('out');

    // Same pill shape/structure across all three statuses: identical
    // wrapper classes (color tokens aside) — rounded-pill/border/padding.
    for (const pill of [okPill, lowPill, outPill]) {
      expect(pill?.className).toContain('rounded-pill');
      expect(pill?.className).toContain('px-2.5');
      expect(pill?.className).toContain('py-0.5');
    }
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
