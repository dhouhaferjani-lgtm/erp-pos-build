import { render, screen } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { VerticalsPage } from '../pages/VerticalsPage'
import type { AdminVerticalConfig } from '../types'

vi.mock('../hooks/useVerticals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../hooks/useVerticals')>()
  return {
    ...actual,
    useVerticals: vi.fn(),
    useUpdateVerticalConfig: vi.fn(),
  }
})

import { useVerticals, useUpdateVerticalConfig } from '../hooks/useVerticals'

const VERTICAL_NAMES = [
  'automotive',
  'retail',
  'restaurant',
  'coffee_shop',
  'parapharmacy',
  'grocery',
  'bakery',
  'butcher',
  'clothing',
  'electronics',
  'hardware',
  'beauty',
] as const

function makeVertical(
  name: string,
  overrides: Partial<AdminVerticalConfig> = {}
): AdminVerticalConfig {
  return {
    vertical: name,
    label: `Label ${name}`,
    product: name === 'automotive' ? 'otospex' : 'izipos',
    default_modules: ['sales', 'inventory'],
    compatible_extras: ['loyalty'],
    is_overridden: false,
    ...overrides,
  }
}

const twelveVerticals: AdminVerticalConfig[] = VERTICAL_NAMES.map((name, i) =>
  makeVertical(name, { is_overridden: i === 0 })
)

function mockUseVerticals(
  value: Partial<ReturnType<typeof useVerticals>>
): void {
  vi.mocked(useVerticals).mockReturnValue(
    value as unknown as ReturnType<typeof useVerticals>
  )
}

function mockUseUpdateVerticalConfig(): void {
  vi.mocked(useUpdateVerticalConfig).mockReturnValue({
    mutate: vi.fn(),
    isPending: false,
    error: null,
    reset: vi.fn(),
  } as unknown as ReturnType<typeof useUpdateVerticalConfig>)
}

describe('VerticalsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseUpdateVerticalConfig()
  })

  it('renders a row per vertical (12 rows) with label, product badge and counts', () => {
    mockUseVerticals({
      data: { data: twelveVerticals, available_modules: ['sales', 'inventory', 'loyalty'] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<VerticalsPage />)

    // one row per vertical + header row
    const rows = screen.getAllByRole('row')
    expect(rows).toHaveLength(13)

    for (const name of VERTICAL_NAMES) {
      expect(screen.getByText(`Label ${name}`)).toBeInTheDocument()
    }

    // product badges
    expect(screen.getAllByText('otospex')).toHaveLength(1)
    expect(screen.getAllByText('izipos')).toHaveLength(11)

    // 12 edit buttons
    expect(screen.getAllByRole('button', { name: 'Edit' })).toHaveLength(12)
  })

  it('shows the Customized badge only for overridden verticals', () => {
    mockUseVerticals({
      data: { data: twelveVerticals, available_modules: ['sales'] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<VerticalsPage />)

    expect(screen.getAllByText('Customized')).toHaveLength(1)
  })

  it('renders a loading state', () => {
    mockUseVerticals({
      data: undefined,
      isLoading: true,
      error: null,
      refetch: vi.fn(),
    })

    render(<VerticalsPage />)

    expect(screen.getByText('Loading verticals...')).toBeInTheDocument()
  })

  it('renders an error state', () => {
    mockUseVerticals({
      data: undefined,
      isLoading: false,
      error: new Error('boom'),
      refetch: vi.fn(),
    })

    render(<VerticalsPage />)

    expect(screen.getByText('Failed to load verticals')).toBeInTheDocument()
  })
})
