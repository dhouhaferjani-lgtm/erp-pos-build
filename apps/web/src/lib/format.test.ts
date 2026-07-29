import { afterAll, beforeAll, describe, expect, it } from 'vitest'

import { formatDate, formatPercent, formatPercentage } from './format'

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

  it('does not two-digit-century-map a sub-100 year in a date-only string', () => {
    expect(formatDate('0099-01-01', 'DD/MM/YYYY', 'en-GB')).not.toContain('1999')
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
