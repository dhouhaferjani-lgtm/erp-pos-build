import { render, screen, within, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import type { AxiosResponse } from 'axios'
import { VerticalConfigModal } from '../components/VerticalConfigModal'
import type { AdminVerticalConfig } from '../types'

vi.mock('../hooks/useVerticals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../hooks/useVerticals')>()
  return {
    ...actual,
    useVerticals: vi.fn(),
    useUpdateVerticalConfig: vi.fn(),
  }
})

import { useUpdateVerticalConfig } from '../hooks/useVerticals'

const AVAILABLE_MODULES = ['sales', 'inventory', 'loyalty', 'workshop']

const vertical: AdminVerticalConfig = {
  vertical: 'coffee_shop',
  label: 'Coffee Shop',
  product: 'izipos',
  default_modules: ['sales', 'inventory'],
  compatible_extras: ['loyalty'],
  is_overridden: false,
}

interface MutationMockOverrides {
  mutate?: ReturnType<typeof vi.fn>
  isPending?: boolean
  error?: unknown
}

function mockMutation(overrides: MutationMockOverrides = {}): ReturnType<typeof vi.fn> {
  const mutate = overrides.mutate ?? vi.fn()
  vi.mocked(useUpdateVerticalConfig).mockReturnValue({
    mutate,
    isPending: overrides.isPending ?? false,
    error: overrides.error ?? null,
    reset: vi.fn(),
  } as unknown as ReturnType<typeof useUpdateVerticalConfig>)
  return mutate
}

function getSection(name: string): HTMLElement {
  return screen.getByRole('group', { name })
}

describe('VerticalConfigModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('opens with the current default modules and compatible extras checked', () => {
    mockMutation()

    render(
      <VerticalConfigModal
        vertical={vertical}
        availableModules={AVAILABLE_MODULES}
        onClose={vi.fn()}
      />
    )

    const defaults = getSection('Default modules')
    expect(within(defaults).getByRole('checkbox', { name: 'sales' })).toBeChecked()
    expect(within(defaults).getByRole('checkbox', { name: 'inventory' })).toBeChecked()
    expect(within(defaults).getByRole('checkbox', { name: 'loyalty' })).not.toBeChecked()
    expect(within(defaults).getByRole('checkbox', { name: 'workshop' })).not.toBeChecked()

    const extras = getSection('Compatible extras')
    expect(within(extras).getByRole('checkbox', { name: 'loyalty' })).toBeChecked()
    expect(within(extras).getByRole('checkbox', { name: 'sales' })).not.toBeChecked()
  })

  it('toggling checkboxes and saving calls the mutation with the updated payload', () => {
    const mutate = mockMutation()

    render(
      <VerticalConfigModal
        vertical={vertical}
        availableModules={AVAILABLE_MODULES}
        onClose={vi.fn()}
      />
    )

    // add workshop to defaults, remove inventory from defaults
    const defaults = getSection('Default modules')
    fireEvent.click(within(defaults).getByRole('checkbox', { name: 'workshop' }))
    fireEvent.click(within(defaults).getByRole('checkbox', { name: 'inventory' }))

    // add workshop to extras
    const extras = getSection('Compatible extras')
    fireEvent.click(within(extras).getByRole('checkbox', { name: 'workshop' }))

    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(mutate).toHaveBeenCalledTimes(1)
    const call = mutate.mock.calls[0] as unknown[]
    expect(call[0]).toEqual({
      vertical: 'coffee_shop',
      payload: {
        default_modules: ['sales', 'workshop'],
        compatible_extras: ['loyalty', 'workshop'],
      },
    })
  })

  it('disables the save button while the mutation is pending', () => {
    mockMutation({ isPending: true })

    render(
      <VerticalConfigModal
        vertical={vertical}
        availableModules={AVAILABLE_MODULES}
        onClose={vi.fn()}
      />
    )

    expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled()
  })

  it('surfaces the server 422 error text', () => {
    const response: AxiosResponse = {
      status: 422,
      statusText: 'Unprocessable Entity',
      data: { error: 'Invalid module names: bogus' },
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    mockMutation({
      error: new AxiosError(
        'Request failed with status code 422',
        AxiosError.ERR_BAD_REQUEST,
        undefined,
        undefined,
        response
      ),
    })

    render(
      <VerticalConfigModal
        vertical={vertical}
        availableModules={AVAILABLE_MODULES}
        onClose={vi.fn()}
      />
    )

    expect(screen.getByText('Invalid module names: bogus')).toBeInTheDocument()
  })

  it('calls onClose when Cancel is clicked', () => {
    mockMutation()
    const onClose = vi.fn()

    render(
      <VerticalConfigModal
        vertical={vertical}
        availableModules={AVAILABLE_MODULES}
        onClose={onClose}
      />
    )

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onClose).toHaveBeenCalled()
  })
})
