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

afterEach(() => {
  for (const k of KEYS) root.style.removeProperty(`--chart-${k}`)
})

describe('chartColors (theme-derived, runtime-resolved)', () => {
  it('resolves each key from its --chart-* CSS variable', () => {
    root.style.setProperty('--chart-primary', '#1A6FB5')
    root.style.setProperty('--chart-success', '#1B7F4E')
    expect(chartColors.primary).toBe('#1A6FB5')
    expect(chartColors.success).toBe('#1B7F4E')
  })

  it('reflects a re-theme without code changes (just swap the CSS var)', () => {
    root.style.setProperty('--chart-primary', '#1A6FB5')
    expect(chartColors.primary).toBe('#1A6FB5')
    // Simulate switching vertical / re-theming: only the CSS var changes.
    root.style.setProperty('--chart-primary', '#084AA9')
    expect(chartColors.primary).toBe('#084AA9')
  })

  it('contains no hardcoded hex — returns empty when the var is unset', () => {
    expect(readChartColor('violet')).toBe('')
    expect(chartColors.violet).toBe('')
  })

  it('exposes an ordered categorical sequence for multi-series charts', () => {
    expect(chartCategoricalKeys).toEqual(['primary', 'success', 'warning', 'cyan', 'violet', 'neutral'])
  })

  it('readChartColor returns trimmed values', () => {
    root.style.setProperty('--chart-warning', '  #D97706  ')
    expect(readChartColor('warning')).toBe('#D97706')
  })
})
