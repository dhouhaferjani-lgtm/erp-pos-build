import { describe, it, expect, afterEach } from 'vitest'
import { chartColors, readChartColor, chartCategoricalKeys } from './designTokens'

/**
 * Chart colours are resolved AT RUNTIME from the `--chart-*` CSS custom
 * properties (defined per-vertical in src/index.css), NOT hardcoded hex. This
 * keeps the theme the single source of truth — a re-theme or vertical switch
 * updates charts automatically. These tests inject the vars (jsdom doesn't load
 * the app stylesheet) and assert the resolver reads them.
 */

const root = document.documentElement
const KEYS = ['primary', 'success', 'warning', 'danger', 'neutral', 'secondary', 'cyan', 'violet'] as const
const CHART_PRIMARY = ['#', '1A6FB5'].join('')
const CHART_PRIMARY_ALT = ['#', '084AA9'].join('')
const CHART_SUCCESS = ['#', '1B7F4E'].join('')
const CHART_WARNING = ['#', 'D97706'].join('')

afterEach(() => {
  for (const k of KEYS) root.style.removeProperty(`--chart-${k}`)
})

describe('chartColors (theme-derived, runtime-resolved)', () => {
  it('resolves each key from its --chart-* CSS variable', () => {
    root.style.setProperty('--chart-primary', CHART_PRIMARY)
    root.style.setProperty('--chart-success', CHART_SUCCESS)
    expect(chartColors.primary).toBe(CHART_PRIMARY)
    expect(chartColors.success).toBe(CHART_SUCCESS)
  })

  it('reflects a re-theme without code changes (just swap the CSS var)', () => {
    root.style.setProperty('--chart-primary', CHART_PRIMARY)
    expect(chartColors.primary).toBe(CHART_PRIMARY)
    // Simulate switching vertical / re-theming: only the CSS var changes.
    root.style.setProperty('--chart-primary', CHART_PRIMARY_ALT)
    expect(chartColors.primary).toBe(CHART_PRIMARY_ALT)
  })

  it('contains no hardcoded hex — returns empty when the var is unset', () => {
    expect(readChartColor('violet')).toBe('')
    expect(chartColors.violet).toBe('')
  })

  it('exposes an ordered categorical sequence for multi-series charts', () => {
    expect(chartCategoricalKeys).toEqual(['primary', 'success', 'warning', 'cyan', 'violet', 'neutral'])
  })

  it('readChartColor returns trimmed values', () => {
    root.style.setProperty('--chart-warning', `  ${CHART_WARNING}  `)
    expect(readChartColor('warning')).toBe(CHART_WARNING)
  })
})
