import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'

import { useCompanyStore, type Company } from '@/stores/companyStore'
import { formatCurrency, formatDate, formatNumber, formatPercent, formatPercentage } from './format'

/** fr-TN / fr-FR group with U+202F (narrow no-break space), not an ASCII space. */
const NNBSP = ' '

function seedCompany(currency: string, locale: string): void {
  const company: Company = {
    id: 'co-1',
    name: 'Test Co',
    legalName: 'Test Co SARL',
    taxId: null,
    countryCode: currency === 'TND' ? 'TN' : 'FR',
    currency,
    locale,
    timezone: 'UTC',
  }
  useCompanyStore.setState({ currentCompanyId: company.id, companies: [company] })
}

/**
 * W-6 D6 / W-7 F-7 (fix lane L4): the money formatters used to be
 * CURRENCY-BLIND. `formatCurrency` defaulted `currency` to a hardcoded `'EUR'`,
 * so six tiles on a Tunisian company rendered `228 728,39 EUR` beside siblings
 * that correctly rendered `228 728,386 TND`; and `formatNumber` pinned its
 * locale to `'en-US'`, so the document totals panel rendered `1,234.567` under a
 * `fr-TN` page — a 1 000x misread to a French or Tunisian reader.
 *
 * The contract these tests pin: the CURRENCY drives both the scale and the
 * locale. Never the UI language, never a hardcoded default.
 */
describe('currency-driven formatting', () => {
  afterEach(() => {
    useCompanyStore.setState({ currentCompanyId: null, companies: [] })
  })

  it('derives scale and locale from an explicitly passed currency', () => {
    expect(formatCurrency('1000', { currency: 'TND' })).toBe(`1${NNBSP}000,000 TND`)
    expect(formatCurrency('1000', { currency: 'EUR' })).toBe(`1${NNBSP}000,00 EUR`)
    expect(formatCurrency('1000', { currency: 'USD' })).toBe('USD 1,000.00')
  })

  it('defaults to the ACTIVE COMPANY currency, not a hardcoded EUR', () => {
    seedCompany('TND', 'fr_TN')

    expect(formatCurrency('228728.386')).toBe(`228${NNBSP}728,386 TND`)
  })

  it('still falls back to EUR when no company is selected', () => {
    expect(formatCurrency('1000')).toBe(`1${NNBSP}000,00 EUR`)
  })

  it('defaults formatNumber to the active company currency locale, not en-US', () => {
    seedCompany('TND', 'fr_TN')

    expect(formatNumber('1234.567', 3)).toBe(`1${NNBSP}234,567`)
  })

  it('honours an explicitly passed locale on formatNumber', () => {
    seedCompany('TND', 'fr_TN')

    expect(formatNumber('1234.567', 3, 'en-US')).toBe('1,234.567')
  })

  it('falls back to the SAME currency as formatCurrency when no company is selected', () => {
    // Both helpers must resolve from one source, or a page renders two decimal
    // conventions side by side — which is F-7 itself.
    expect(formatNumber('1234.567', 3)).toBe(`1${NNBSP}234,567`)
    expect(formatCurrency('1234.567', { includeCurrency: false })).toBe(`1${NNBSP}234,57`)
  })
})

describe('formatPercent', () => {
  it('trims percent values to at most two decimal places without fixed zeros', () => {
    expect(formatPercent('19.0000')).toBe('19%')
    expect(formatPercent('19.5000')).toBe('19.5%')
    expect(formatPercent('19.2500')).toBe('19.25%')
    expect(formatPercent('0.0000')).toBe('0%')
    expect(formatPercent(null)).toBe('0%')
  })

  it('keeps the legacy formatPercentage export on the safe implementation', () => {
    expect(formatPercentage('19.0000')).toBe('19%')
  })
})

// The formatDate helper is app-wide. A date-ONLY string (`YYYY-MM-DD`) carries no
// time or zone; it must render as the SAME calendar date in every timezone. The
// previous implementation parsed it as UTC midnight and then formatted in the local
// zone, so users behind UTC saw the day shift back by one. These tests pin a
// negative-offset zone (America/Los_Angeles) so the day-shift bug is reproducible.
describe('formatDate', () => {
  const originalTz = process.env.TZ

  beforeAll(() => {
    process.env.TZ = 'America/Los_Angeles'
  })

  afterAll(() => {
    // Assigning `undefined` would store the literal string "undefined"; delete when
    // TZ was originally unset so the environment is restored faithfully.
    if (originalTz === undefined) {
      delete process.env.TZ
    } else {
      process.env.TZ = originalTz
    }
  })

  it('renders a date-only string as its calendar date without a timezone day-shift', () => {
    expect(formatDate('2026-07-02', 'DD/MM/YYYY', 'en-GB')).toBe('02/07/2026')
  })

  it('renders a date-only string in the US month-first format without shifting', () => {
    expect(formatDate('2026-07-02', 'MM/DD/YYYY', 'en-US')).toBe('07/02/2026')
  })

  it('keeps timezone conversion for datetime strings with an explicit instant', () => {
    // 02:00Z on 2026-07-02 is 19:00 on 2026-07-01 in Los Angeles (UTC-7 in July).
    expect(formatDate('2026-07-02T02:00:00Z', 'DD/MM/YYYY', 'en-GB')).toBe('01/07/2026')
  })

  it('keeps timezone conversion for ISO strings carrying an offset', () => {
    // 00:30+02:00 on 2026-07-02 is 22:30Z on 2026-07-01 → 15:30 in Los Angeles.
    expect(formatDate('2026-07-02T00:30:00+02:00', 'DD/MM/YYYY', 'en-GB')).toBe('01/07/2026')
  })

  it('formats a Date object using its local calendar date', () => {
    expect(formatDate(new Date(2026, 6, 2), 'DD/MM/YYYY', 'en-GB')).toBe('02/07/2026')
  })

  it('returns an empty string for an invalid date input', () => {
    expect(formatDate('not-a-date', 'DD/MM/YYYY', 'en-GB')).toBe('')
  })

  it('rejects a year below 1000 as an invalid business date', () => {
    expect(formatDate('0099-01-01', 'DD/MM/YYYY', 'en-GB')).toBe('')
  })

  it('rejects an out-of-range month instead of rolling it over', () => {
    expect(formatDate('2026-13-01', 'DD/MM/YYYY', 'en-GB')).toBe('')
  })

  it('rejects an out-of-range day instead of rolling it over', () => {
    expect(formatDate('2026-02-30', 'DD/MM/YYYY', 'en-GB')).toBe('')
  })

  it('accepts a valid leap day', () => {
    expect(formatDate('2028-02-29', 'DD/MM/YYYY', 'en-GB')).toBe('29/02/2028')
  })
})
