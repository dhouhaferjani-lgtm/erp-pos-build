import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerBalanceBadge } from './CustomerBalanceBadge';

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (value: string | number) => `TND ${Number(value).toFixed(3)}`,
  }),
}));

describe('CustomerBalanceBadge', () => {
  it('shows receivable and credit balances', () => {
    render(
      <CustomerBalanceBadge
        receivableBalance="42.500"
        creditBalance="3.250"
        balanceUpdatedAt="2026-05-21T08:00:00.000Z"
        stale={false}
      />,
    );

    expect(screen.getByText('Due TND 42.500')).toBeInTheDocument();
    expect(screen.getByText('Credit TND 3.250')).toBeInTheDocument();
    expect(screen.getByText('Fresh')).toBeInTheDocument();
  });

  it('shows stale balance state when balance_updated_at exceeds threshold', () => {
    render(
      <CustomerBalanceBadge
        receivableBalance="42.500"
        creditBalance="0.000"
        balanceUpdatedAt="2026-05-21T07:00:00.000Z"
        stale={true}
      />,
    );

    expect(screen.getByText('Stale')).toBeInTheDocument();
    expect(screen.getByLabelText('Customer balance is stale')).toBeInTheDocument();
  });
});
