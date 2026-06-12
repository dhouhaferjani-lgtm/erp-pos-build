import { render, screen, within, fireEvent } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ManageModulesSection } from '../components/TenantDetailModal'

vi.mock('../hooks/useTenants', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../hooks/useTenants')>()
  return {
    ...actual,
    useUpdateTenantExtras: vi.fn(),
  }
})

import { useUpdateTenantExtras } from '../hooks/useTenants'

const DEFAULT_MODULES = [
  'Identity',
  'Catalog',
  'Sales',
  'Treasury',
  'CompositeItems',
]

interface MutationMockOverrides {
  mutate?: ReturnType<typeof vi.fn>
  isPending?: boolean
}

function mockMutation(
  overrides: MutationMockOverrides = {}
): ReturnType<typeof vi.fn> {
  const mutate = overrides.mutate ?? vi.fn()
  vi.mocked(useUpdateTenantExtras).mockReturnValue({
    mutate,
    isPending: overrides.isPending ?? false,
    reset: vi.fn(),
  } as unknown as ReturnType<typeof useUpdateTenantExtras>)
  return mutate
}

function renderSection(
  overrides: Partial<Parameters<typeof ManageModulesSection>[0]> = {}
) {
  return render(
    <ManageModulesSection
      tenantId="tenant-1"
      verticalLabel="Coffee Shop"
      defaultModules={DEFAULT_MODULES}
      compatibleExtras={['Tables', 'Loyalty']}
      enabledExtras={['Tables']}
      onRefresh={undefined}
      {...overrides}
    />
  )
}

describe('ManageModulesSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the vertical default modules as read-only chips', () => {
    mockMutation()
    renderSection()

    const defaults = screen.getByRole('group', { name: 'Default modules' })
    for (const module of DEFAULT_MODULES) {
      expect(within(defaults).getByText(module)).toBeInTheDocument()
    }
    // Vertical label is shown in the hint
    expect(
      screen.getByText(/Coffee Shop/, { exact: false })
    ).toBeInTheDocument()
  })

  it('default-module chips are not interactive', () => {
    mockMutation()
    renderSection()

    const defaults = screen.getByRole('group', { name: 'Default modules' })
    expect(within(defaults).queryAllByRole('switch')).toHaveLength(0)
    expect(within(defaults).queryAllByRole('checkbox')).toHaveLength(0)
    expect(within(defaults).queryAllByRole('button')).toHaveLength(0)
  })

  it('renders defaults even when the vertical has no compatible extras', () => {
    mockMutation()
    renderSection({ compatibleExtras: [], enabledExtras: [] })

    const defaults = screen.getByRole('group', { name: 'Default modules' })
    expect(within(defaults).getByText('Identity')).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('renders nothing when there are no defaults and no extras', () => {
    mockMutation()
    const { container } = renderSection({
      defaultModules: [],
      compatibleExtras: [],
      enabledExtras: [],
    })

    expect(container).toBeEmptyDOMElement()
  })

  it('extras toggles still work: confirming a toggle calls the mutation', () => {
    const mutate = mockMutation()
    renderSection()

    fireEvent.click(screen.getByRole('button', { name: 'Enable Loyalty' }))
    fireEvent.click(screen.getByRole('button', { name: 'Enable' }))

    expect(mutate).toHaveBeenCalledTimes(1)
    const call = mutate.mock.calls[0] as unknown[]
    expect(call[0]).toEqual({
      tenantId: 'tenant-1',
      enabledExtras: ['Tables', 'Loyalty'],
    })
  })
})
