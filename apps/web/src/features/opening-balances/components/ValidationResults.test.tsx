import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ValidationResults } from './ValidationResults'
import type { OpeningBalanceImportRow, ValidationResult } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    // Render `key(param=value, …)` so a test can assert BOTH that the right key
    // was chosen and that the server's parameters reached it.
    t: (key: string, params?: Record<string, unknown>) =>
      params === undefined
        ? key
        : `${key}(${Object.entries(params)
            .map(([k, v]) => `${k}=${String(v)}`)
            .join(', ')})`,
  }),
}))

/**
 * W4-2 / treasury gate r2 G-1.
 *
 * The `OPENING_CASH_NOT_FULLY_SEEDED` refusal fires at VALIDATE time into
 * `errors._batch`. The API put it on the wire and the wizard threw it away:
 * `ValidationResults` accepted only `{valid, total_rows, valid_rows,
 * invalid_rows}`, so a four-column legacy sheet — the exact sheet F-1 had just
 * un-blocked — uploaded, validated every row VALID, reported "0 invalid",
 * refused to advance, and told the operator NOTHING. That is strictly worse
 * than the defect it replaced, which at least said "missing columns".
 *
 * These cases pin: the batch-level refusal is on screen, translated from the
 * server's CODE (never its English `message`), with the server's parameters.
 */
const NO_ROWS: OpeningBalanceImportRow[] = []

function result(overrides: Partial<ValidationResult> = {}): ValidationResult {
  return {
    valid: false,
    total_rows: 2,
    valid_rows: 2,
    invalid_rows: 0,
    errors: {},
    ...overrides,
  }
}

describe('ValidationResults — batch-level refusals', () => {
  it('renders the coverage refusal from its code and params, not the server English', () => {
    render(
      <ValidationResults
        rows={NO_ROWS}
        validationResult={result({
          errors: {
            _batch: [
              {
                code: 'OPENING_CASH_NOT_FULLY_SEEDED',
                params: {
                  account: '53',
                  debited: '1200.000',
                  attributed: '0.000',
                  unattributed: '1200.000',
                  repositories: 'CASH-01, SAFE-01',
                },
                message: 'Account 53 is debited 1200.000 but only 0.000 is assigned to a payment repository',
              },
            ],
          },
        })}
      />
    )

    const rendered = screen.getByText(/openingBalances\.validation\.batchErrors\.OPENING_CASH_NOT_FULLY_SEEDED/)
    expect(rendered).toBeInTheDocument()
    expect(rendered.textContent).toContain('account=53')
    expect(rendered.textContent).toContain('unattributed=1200.000')
    expect(rendered.textContent).toContain('repositories=CASH-01, SAFE-01')

    // The server's English must never reach the operator.
    expect(screen.queryByText(/is assigned to a payment repository/)).not.toBeInTheDocument()
  })

  it('renders every batch-level refusal when a sheet trips more than one', () => {
    render(
      <ValidationResults
        rows={NO_ROWS}
        validationResult={result({
          errors: {
            _batch: [
              {
                code: 'OPENING_CASH_NOT_FULLY_SEEDED',
                params: { account: '53', debited: '1200.000', attributed: '0.000', unattributed: '1200.000', repositories: 'CASH-01' },
                message: 'coverage',
              },
              {
                code: 'OPENING_BATCH_NOT_BALANCED',
                params: { debit: '1200.000', credit: '900.000', difference: '300.000' },
                message: 'imbalance',
              },
            ],
          },
        })}
      />
    )

    expect(
      screen.getByText(/openingBalances\.validation\.batchErrors\.OPENING_CASH_NOT_FULLY_SEEDED/)
    ).toBeInTheDocument()
    expect(
      screen.getByText(/openingBalances\.validation\.batchErrors\.OPENING_BATCH_NOT_BALANCED/)
    ).toBeInTheDocument()
  })

  it('falls back to a translated generic line for a code this build does not know', () => {
    render(
      <ValidationResults
        rows={NO_ROWS}
        validationResult={result({
          errors: {
            _batch: [{ code: 'SOME_FUTURE_REFUSAL', params: {}, message: 'raw server prose' }],
          },
        })}
      />
    )

    const rendered = screen.getByText(/openingBalances\.validation\.batchErrors\.unknown/)
    expect(rendered.textContent).toContain('code=SOME_FUTURE_REFUSAL')
    expect(screen.queryByText(/raw server prose/)).not.toBeInTheDocument()
  })

  it('renders nothing extra when the batch has no batch-level refusal', () => {
    render(<ValidationResults rows={NO_ROWS} validationResult={result({ valid: true, errors: {} })} />)

    expect(
      screen.queryByText(/openingBalances\.validation\.batchErrors\./)
    ).not.toBeInTheDocument()
  })
})
