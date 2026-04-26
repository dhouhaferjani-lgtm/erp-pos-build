import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { I18nextProvider } from 'react-i18next'
import i18n from '@/lib/i18n'
import { CashDrawerControlsSection } from './CashDrawerControlsSection'

const initial = {
  cash_variance_over_soft: '1.00',
  cash_variance_over_hard: '20.00',
  cash_variance_under_soft: '1.00',
  cash_variance_under_hard: '20.00',
  require_blind_cash_count: false,
  require_manager_pin_above_hard: true,
  cash_variance_email_severity: 'none' as const,
}

describe('CashDrawerControlsSection', () => {
  it('renders the form with key fields', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="EUR" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    expect(screen.getByTestId('cash-controls-section')).toBeInTheDocument()
  })

  it('disables all inputs when canEdit is false', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="EUR" onChange={vi.fn()} canEdit={false} />
      </I18nextProvider>,
    )
    const inputs = screen.getAllByRole('spinbutton')
    inputs.forEach((input) => expect(input).toBeDisabled())
    const checkboxes = screen.getAllByRole('checkbox')
    checkboxes.forEach((cb) => expect(cb).toBeDisabled())
    const selects = screen.getAllByRole('combobox')
    selects.forEach((s) => expect(s).toBeDisabled())
  })

  it('calls onChange when a threshold input changes', async () => {
    const onChange = vi.fn()
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="EUR" onChange={onChange} canEdit={true} />
      </I18nextProvider>,
    )
    const inputs = screen.getAllByRole('spinbutton')
    await userEvent.clear(inputs[0])
    await userEvent.type(inputs[0], '2')
    expect(onChange).toHaveBeenCalled()
  })

  it('shows validation error when soft >= hard', async () => {
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="EUR" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    const inputs = screen.getAllByRole('spinbutton')
    await userEvent.clear(inputs[0])
    await userEvent.type(inputs[0], '50')
    expect(screen.getByTestId('cash-controls-error')).toBeInTheDocument()
  })

  // Item 2 — currency-aware step
  it('renders numeric inputs with step="0.001" for TND (3 decimals)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="TND" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    const inputs = screen.getAllByRole('spinbutton')
    inputs.forEach((input) => {
      expect(input).toHaveAttribute('step', '0.001')
    })
  })

  it('renders numeric inputs with step="0.01" for EUR (2 decimals)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={initial} currencyCode="EUR" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    const inputs = screen.getAllByRole('spinbutton')
    inputs.forEach((input) => {
      expect(input).toHaveAttribute('step', '0.01')
    })
  })

  // Item 3 — bccomp boundary validation
  it('shows validation error when overSoft === overHard (boundary: equal values must fail)', async () => {
    const equalThresholds = {
      ...initial,
      cash_variance_over_soft: '5.00',
      cash_variance_over_hard: '5.00',
    }
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={equalThresholds} currencyCode="EUR" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    expect(screen.getByTestId('cash-controls-error')).toBeInTheDocument()
  })

  it('does not show validation error when overSoft < overHard (boundary: strict less-than must pass)', () => {
    const validThresholds = {
      ...initial,
      cash_variance_over_soft: '4.99',
      cash_variance_over_hard: '5.00',
    }
    render(
      <I18nextProvider i18n={i18n}>
        <CashDrawerControlsSection value={validThresholds} currencyCode="EUR" onChange={vi.fn()} canEdit={true} />
      </I18nextProvider>,
    )
    expect(screen.queryByTestId('cash-controls-error')).not.toBeInTheDocument()
  })
})
