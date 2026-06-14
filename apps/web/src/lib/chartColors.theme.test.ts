import { describe, it, expect } from 'vitest'
import { chartColors } from './designTokens'

/**
 * Chart colours must be derived from the active "Deep Ocean" theme palette
 * (src/index.css :root). ECharts reads raw hex (it can't consume Tailwind
 * tokens), so this is the ONE place chart hex lives — and it historically
 * drifted off-theme (primary was #2563eb, ≠ theme primary-600 #1A6FB5),
 * making every dashboard chart render in a blue the rest of the app never uses.
 *
 * These values mirror src/index.css. If the theme changes, update both.
 */
const THEME = {
  primary600: '#1A6FB5',
  success: '#1B7F4E',
  warning: '#D97706',
  error: '#C53030',
  neutral500: '#6B7A8D',
  secondary500: '#C2703E',
} as const

// Off-theme literals that must never reappear in the chart palette.
const FORBIDDEN = ['#2563eb', '#16a34a', '#ca8a04', '#dc2626', '#64748b', '#7c3aed', '#0891b2']

describe('chartColors theme alignment', () => {
  it('anchors the semantic slots to the Deep Ocean theme tokens', () => {
    expect(chartColors.primary.toLowerCase()).toBe(THEME.primary600.toLowerCase())
    expect(chartColors.success.toLowerCase()).toBe(THEME.success.toLowerCase())
    expect(chartColors.warning.toLowerCase()).toBe(THEME.warning.toLowerCase())
    expect(chartColors.danger.toLowerCase()).toBe(THEME.error.toLowerCase())
    expect(chartColors.neutral.toLowerCase()).toBe(THEME.neutral500.toLowerCase())
  })

  it('exposes the copper secondary as a categorical accent', () => {
    expect(chartColors.secondary.toLowerCase()).toBe(THEME.secondary500.toLowerCase())
  })

  it('contains no legacy off-theme hex literals', () => {
    const values = Object.values(chartColors).map((v) => v.toLowerCase())
    for (const bad of FORBIDDEN) {
      expect(values).not.toContain(bad.toLowerCase())
    }
  })

  it('keeps every categorical slot a valid 6-digit hex', () => {
    for (const v of Object.values(chartColors)) {
      expect(v).toMatch(/^#[0-9a-fA-F]{6}$/)
    }
  })
})
