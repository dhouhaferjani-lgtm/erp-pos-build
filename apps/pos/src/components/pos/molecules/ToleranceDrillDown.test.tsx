import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { ToleranceDrillDown } from './ToleranceDrillDown';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      (opts?.defaultValue as string) ?? key,
  }),
}));

vi.mock('@/api/toleranceApi', () => ({
  fetchToleranceReceiptsForShift: vi.fn().mockResolvedValue([
    {
      receiptId: 'r-1',
      receiptNumber: 'R0042',
      cashierName: 'Amine',
      occurredAt: '2026-04-24T10:05:00Z',
      writeoffAmount: '0.5000',
      currencyCode: 'EUR',
    },
  ]),
}));

describe('ToleranceDrillDown', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('does not show receipt rows before toggle is clicked', () => {
    render(
      <ToleranceDrillDown
        shiftId="s-1"
        totalAmount="0.5000"
        writeoffCount={1}
        currencyCode="EUR"
      />,
    );

    expect(screen.queryByText(/R0042/)).not.toBeInTheDocument();
    expect(screen.getByTestId('tolerance-drill')).toBeInTheDocument();
    expect(screen.getByTestId('tolerance-drill-toggle')).toBeInTheDocument();
  });

  it('expands on click and renders per-receipt rows', async () => {
    render(
      <ToleranceDrillDown
        shiftId="s-1"
        totalAmount="0.5000"
        writeoffCount={1}
        currencyCode="EUR"
      />,
    );

    expect(screen.queryByText(/R0042/)).not.toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByTestId('tolerance-drill-toggle'));
    });

    expect(await screen.findByText('R0042')).toBeInTheDocument();
    expect(screen.getByText('Amine')).toBeInTheDocument();
  });

  it('shows empty state when no rows returned', async () => {
    const toleranceApi = await import('@/api/toleranceApi');
    vi.mocked(toleranceApi.fetchToleranceReceiptsForShift).mockResolvedValueOnce([]);

    render(
      <ToleranceDrillDown
        shiftId="s-3"
        totalAmount="0.0000"
        writeoffCount={0}
        currencyCode="EUR"
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByTestId('tolerance-drill-toggle'));
    });

    await waitFor(() => {
      expect(screen.getByText('No write-offs recorded.')).toBeInTheDocument();
    });
  });

  it('does not re-fetch when toggled closed and opened again', async () => {
    const toleranceApi = await import('@/api/toleranceApi');

    render(
      <ToleranceDrillDown
        shiftId="s-4"
        totalAmount="0.5000"
        writeoffCount={1}
        currencyCode="EUR"
      />,
    );

    const toggle = screen.getByTestId('tolerance-drill-toggle');

    // First click — opens and fetches
    await act(async () => {
      fireEvent.click(toggle);
    });
    await screen.findByText('R0042');

    // Second click — closes
    await act(async () => {
      fireEvent.click(toggle);
    });

    // Third click — re-opens, must NOT fetch again
    await act(async () => {
      fireEvent.click(toggle);
    });
    await screen.findByText('R0042');

    expect(vi.mocked(toleranceApi.fetchToleranceReceiptsForShift)).toHaveBeenCalledTimes(1);
  });
});
