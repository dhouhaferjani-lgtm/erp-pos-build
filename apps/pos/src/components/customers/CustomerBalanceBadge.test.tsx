import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerBalanceBadge } from './CustomerBalanceBadge';

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (value: string | number) => `TND ${Number(value).toFixed(3)}`,
  }),
}));

describe('CustomerBalanceBadge', () => {
  it('shows receivable, credit, net due, and visible update time', () => {
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
    expect(screen.getByText('Net due TND 39.250')).toBeInTheDocument();
    expect(screen.getByText(/Balance updated/)).toBeInTheDocument();
    expect(screen.getByText(/2026/)).toBeInTheDocument();
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

  it('does not show a negative net due when customer credit exceeds receivables', () => {
    render(
      <CustomerBalanceBadge
        receivableBalance="10.000"
        creditBalance="12.500"
        balanceUpdatedAt={null}
        stale={false}
      />,
    );

    expect(screen.getByText('Net due TND 0.000')).toBeInTheDocument();
    expect(screen.getByText('Balance updated Never synced')).toBeInTheDocument();
  });
});
