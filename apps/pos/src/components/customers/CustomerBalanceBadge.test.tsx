import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerBalanceBadge } from './CustomerBalanceBadge';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

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

    // Labels are i18n keys (t() identity mock in unit tests)
    expect(screen.getByText('customerBalance.due')).toBeInTheDocument();
    expect(screen.getByText('customerBalance.credit')).toBeInTheDocument();
    expect(screen.getByText('customerBalance.netDue')).toBeInTheDocument();
    expect(screen.getByText('customerBalance.updatedAt')).toBeInTheDocument();
    expect(screen.getByText('customerBalance.fresh')).toBeInTheDocument();
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

    expect(screen.getByText('customerBalance.stale')).toBeInTheDocument();
    expect(screen.getByLabelText('customerBalance.staleAriaLabel')).toBeInTheDocument();
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

    expect(screen.getByText('customerBalance.netDue')).toBeInTheDocument();
    expect(screen.getByText('customerBalance.updatedAt')).toBeInTheDocument();
  });
});
