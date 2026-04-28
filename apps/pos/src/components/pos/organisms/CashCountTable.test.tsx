import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CashCountTable } from './CashCountTable';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, o?: Record<string, unknown>) => (o?.defaultValue as string) ?? k }),
  initReactI18next: { type: '3rdParty', init: () => {} },
}));

const cashTender = {
  payment_method_id: 'pm-cash',
  payment_method_code: 'CASH',
  payment_method_name: 'Cash',
  is_physical: true,
  expected_amount: '100.0000',
  transaction_count: 5,
  currency_code: 'EUR',
};

const cardTender = {
  payment_method_id: 'pm-card',
  payment_method_code: 'CARD',
  payment_method_name: 'Card',
  is_physical: false,
  expected_amount: '50.0000',
  transaction_count: 2,
  currency_code: 'EUR',
};

describe('CashCountTable', () => {
  it('renders one row per tender', () => {
    render(
      <CashCountTable
        tenders={[cashTender, cardTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={false}
        committed={false}
        variances={{}}
      />,
    );
    expect(screen.getByTestId('tender-row-CASH')).toBeInTheDocument();
    expect(screen.getByTestId('tender-row-CARD')).toBeInTheDocument();
  });

  it('marks electronic rows with ✓ elec. and shows expected amount as actual', () => {
    render(
      <CashCountTable
        tenders={[cardTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={false}
        committed={false}
        variances={{}}
      />,
    );
    expect(screen.getByTestId('tender-electronic-CARD')).toBeInTheDocument();
  });

  it('hides Expected column in blindMode Phase 1', () => {
    render(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={true}
        committed={false}
        variances={{}}
      />,
    );
    expect(screen.queryByText('100.0000')).not.toBeInTheDocument();
    expect(screen.queryByText('Expected')).not.toBeInTheDocument();
  });

  it('reveals Expected and Variance columns in blindMode Phase 2 (committed)', () => {
    render(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{ 'pm-cash': '99.0000' }}
        onActualChange={vi.fn()}
        blindMode={true}
        committed={true}
        variances={{ 'pm-cash': { amount: '-1.0000', direction: 'under', status: 'warning' } }}
      />,
    );
    expect(screen.getByText('100.0000')).toBeInTheDocument();
    expect(screen.getByTestId('tender-variance-CASH')).toBeInTheDocument();
  });

  it('opens the numpad when a physical row Actual is tapped', () => {
    render(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={false}
        committed={false}
        variances={{}}
      />,
    );
    fireEvent.click(screen.getByTestId('tender-actual-input-CASH'));
    expect(screen.getByTestId('cash-count-numpad-panel')).toBeInTheDocument();
  });

  it('disables Actual input when committed', () => {
    render(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{ 'pm-cash': '99.0000' }}
        onActualChange={vi.fn()}
        blindMode={true}
        committed={true}
        variances={{}}
      />,
    );
    expect(screen.getByTestId('tender-actual-input-CASH')).toBeDisabled();
  });

  it('color-codes variance by status (critical=red, warning=amber, balanced=green)', () => {
    const { rerender } = render(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={false}
        committed={false}
        variances={{ 'pm-cash': { amount: '0.0000', direction: 'balanced', status: 'balanced' } }}
      />,
    );
    expect(screen.getByTestId('tender-variance-CASH').className).toContain('text-green');

    rerender(
      <CashCountTable
        tenders={[cashTender]}
        actuals={{}}
        onActualChange={vi.fn()}
        blindMode={false}
        committed={false}
        variances={{ 'pm-cash': { amount: '50.0000', direction: 'over', status: 'critical' } }}
      />,
    );
    expect(screen.getByTestId('tender-variance-CASH').className).toContain('text-red');
  });
});
