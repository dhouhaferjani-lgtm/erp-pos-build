import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));
vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (v: string | number) => `${v}`, decimals: 3, currency: 'TND' }),
}));

import { SaleDetailModal } from './SaleDetailModal';
import type { ShiftReceipt } from '@/api/reportApi';

const receipt: ShiftReceipt = {
  id: 'r1',
  receipt_number: 'T-001',
  receipt_type: 'sale',
  total: '108.000',
  subtotal: '90.000',
  tax_amount: '18.000',
  is_voided: false,
  posted_at: '2026-06-28T10:00:00Z',
  payments: [{ id: 'pay1', payment_type: 'CASH', amount: '108.000' }],
  lines: [
    { id: 'l1', quantity: 2, unit_price: '45.000', line_total: '90.000', product: { id: 'p1', name: 'Avène' } },
  ],
};

describe('SaleDetailModal', () => {
  it('returns null with no receipt', () => {
    const { container } = render(<SaleDetailModal receipt={null} isOpen onClose={vi.fn()} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders line items, totals and payments (read-only view)', () => {
    const { getByText, getAllByText } = render(
      <SaleDetailModal receipt={receipt} isOpen onClose={vi.fn()} />,
    );
    expect(getByText('Avène')).toBeTruthy();
    expect(getAllByText('90.000').length).toBeGreaterThan(0); // line total + subtotal
    expect(getAllByText('108.000').length).toBeGreaterThan(0); // grand total + payment
    expect(getByText('CASH')).toBeTruthy();
  });

  it('formats a historical line without a product at the scale-four fallback', () => {
    const { getByText } = render(
      <SaleDetailModal
        receipt={{
          ...receipt,
          lines: [{
            id: 'historical-line',
            quantity: -1.2,
            unit_price: '45.000',
            line_total: '-54.000',
            product: null,
            product_name: 'Archived Avène',
          }],
        }}
        isOpen
        onClose={vi.fn()}
      />,
    );

    expect(getByText('Archived Avène')).toBeTruthy();
    expect(getByText('-1.2000')).toBeTruthy();
  });

  it("formats an existing product's historical line at its current non-default unit precision", () => {
    const { getByText } = render(
      <SaleDetailModal
        receipt={{
          ...receipt,
          lines: [{
            id: 'current-product-line',
            quantity: 1.2,
            quantity_decimals: 2,
            unit_price: '45.000',
            line_total: '54.000',
            product: { id: 'p1', name: 'Avène' },
          }],
        }}
        isOpen
        onClose={vi.fn()}
      />,
    );

    expect(getByText('Avène')).toBeTruthy();
    expect(getByText('1.20')).toBeTruthy();
  });

  it('fires onReprint when the reprint action is used', () => {
    const onReprint = vi.fn();
    const { getByText } = render(
      <SaleDetailModal receipt={receipt} isOpen onClose={vi.fn()} onReprint={onReprint} />,
    );
    fireEvent.click(getByText('reports.reprint'));
    expect(onReprint).toHaveBeenCalledWith('r1');
  });
});
