/**
 * ToleranceSettingsDisplay Component Tests
 * TDD: Tests written FIRST before implementation
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToleranceSettingsDisplay } from './ToleranceSettingsDisplay'
import * as hooks from '../hooks/useSmartPayment'
import type { ToleranceSettings } from '@/types/treasury'

// Mock the translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      // Handle both namespaced and non-namespaced keys
      // Non-namespaced keys default to 'treasury' namespace when using useTranslation(['treasury', 'common'])
      const translations: Record<string, string> = {
        // Treasury namespace (can be called without prefix)
        'smartPayment.tolerance.title': 'Payment Tolerance',
        'smartPayment.tolerance.enabled': 'Tolerance enabled',
        'smartPayment.tolerance.disabled': 'Tolerance disabled',
        'smartPayment.tolerance.threshold': 'Threshold: {{percentage}}% / {{maxAmount}} max',
        'smartPayment.tolerance.source.company': 'Company settings',
        'smartPayment.tolerance.source.country': 'Country default',
        'smartPayment.tolerance.source.system': 'System default',
        // Common namespace (called with prefix)
        'common.loading': 'Loading...',
        'common.error': 'Error loading tolerance settings',
      }
      let result = translations[key] || key
      if (params) {
        Object.entries(params).forEach(([k, v]) => {
          result = result.replace(`{{${k}}}`, String(v))
        })
      }
      return result
    },
  }),
}))

const createQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <QueryClientProvider client={createQueryClient()}>{children}</QueryClientProvider>
)

describe('ToleranceSettingsDisplay', () => {
  it('renders loading state initially', () => {
    vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
      data: undefined,
      isLoading: true,
      error: null,
    } as any)

    render(<ToleranceSettingsDisplay />, { wrapper })

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('renders error state when fetch fails', () => {
    vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('Network error'),
    } as any)

    render(<ToleranceSettingsDisplay />, { wrapper })

    expect(screen.getByText(/error/i)).toBeInTheDocument()
  })

  it('displays tolerance settings when enabled (system default)', () => {
    const settings: ToleranceSettings = {
      enabled: true,
      percentage: '0.0050', // 0.5%
      max_amount: '0.5000',
      source: 'system',
    }

    vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
      data: settings,
      isLoading: false,
      error: null,
    } as any)

    render(<ToleranceSettingsDisplay />, { wrapper })

    expect(screen.getByText('Payment Tolerance')).toBeInTheDocument()
    expect(screen.getByText('Tolerance enabled')).toBeInTheDocument()
    // Fix lane L4 (W-7 F-7): the amount is formatted against the company
    // CURRENCY's locale, not a hardcoded en-US. No company is seeded here, so
    // the EUR fallback applies -> fr-FR comma decimal.
    expect(screen.getByText('Threshold: 0.5% / 0,50 max')).toBeInTheDocument()
    expect(screen.getByText('System default')).toBeInTheDocument()
  })

  it('displays tolerance settings when disabled', () => {
    const settings: ToleranceSettings = {
      enabled: false,
      percentage: '0.0000',
      max_amount: '0.0000',
      source: 'company',
    }

    vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
      data: settings,
      isLoading: false,
      error: null,
    } as any)

    render(<ToleranceSettingsDisplay />, { wrapper })

    expect(screen.getByText('Payment Tolerance')).toBeInTheDocument()
    expect(screen.getByText('Tolerance disabled')).toBeInTheDocument()
    expect(screen.getByText('Company settings')).toBeInTheDocument()
  })

  it('formats percentage correctly (converts decimal to percentage)', () => {
    const settings: ToleranceSettings = {
      enabled: true,
      percentage: '0.0100', // 1%
      max_amount: '10.0000',
      source: 'country',
    }

    vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
      data: settings,
      isLoading: false,
      error: null,
    } as any)

    render(<ToleranceSettingsDisplay />, { wrapper })

    expect(screen.getByText('Threshold: 1% / 10,00 max')).toBeInTheDocument()
    expect(screen.getByText('Country default')).toBeInTheDocument()
  })

  it('handles different source types', () => {
    const sources: Array<'company' | 'country' | 'system'> = ['company', 'country', 'system']

    sources.forEach((source) => {
      const settings: ToleranceSettings = {
        enabled: true,
        percentage: '0.0050',
        max_amount: '0.5000',
        source,
      }

      vi.spyOn(hooks, 'useToleranceSettings').mockReturnValue({
        data: settings,
        isLoading: false,
        error: null,
      } as any)

      const { unmount } = render(<ToleranceSettingsDisplay />, { wrapper })

      const expectedText =
        source === 'company'
          ? 'Company settings'
          : source === 'country'
            ? 'Country default'
            : 'System default'

      expect(screen.getByText(expectedText)).toBeInTheDocument()
      unmount()
    })
  })
})
