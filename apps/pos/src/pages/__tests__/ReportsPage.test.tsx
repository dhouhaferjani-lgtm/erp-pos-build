import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReportsPage } from '../ReportsPage';
import { ShiftClosurePage } from '../ShiftClosurePage';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { number?: number }) =>
      opts?.number !== undefined ? `${key} ${opts.number}` : key,
  }),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (value: string) => `${value} DT`,
  }),
}));

describe('Reports pulse screens', () => {
  it('renders the reports dashboard surface', () => {
    render(<ReportsPage />);

    expect(screen.getByTestId('reports-screen')).toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.paymentBreakdown')).toBeInTheDocument();
    expect(screen.getByText('reports.dashboard.ticket')).toBeInTheDocument();
  });

  it('renders the shift X/Z closure surface', () => {
    render(<ShiftClosurePage />);

    expect(screen.getByTestId('shift-screen')).toBeInTheDocument();
    expect(screen.getByText('shiftClosure.xReportTitle')).toBeInTheDocument();
    expect(screen.getByText('shiftClosure.zCloseTitle')).toBeInTheDocument();
  });
});
