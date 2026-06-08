import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom'
import { AttributeListPage } from '../AttributeListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

let mockHasPermission = vi.fn().mockReturnValue(true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

const mockCreateMutate = vi.fn()
const mockAddValueMutate = vi.fn()
const mockDeleteMutate = vi.fn()
let mockAttributes: unknown[] = []
let mockValues: unknown[] = []

vi.mock('../../hooks/useVariants', () => ({
  useAttributes: () => ({ data: mockAttributes, isLoading: false }),
  useAttributeValues: () => ({ data: mockValues, isLoading: false }),
  useCreateAttribute: () => ({ mutateAsync: mockCreateMutate, isPending: false }),
  useAddAttributeValue: () => ({ mutateAsync: mockAddValueMutate, isPending: false }),
  useDeleteAttribute: () => ({ mutateAsync: mockDeleteMutate, isPending: false }),
}))

describe('AttributeListPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission = vi.fn().mockReturnValue(true)
    mockAttributes = []
    mockValues = []
  })

  it('renders empty state when no attributes', () => {
    render(<AttributeListPage />)
    expect(screen.getByText('catalog:attributes.empty')).toBeInTheDocument()
  })

  it('lists attributes when present', () => {
    mockAttributes = [
      {
        id: 'attr-1',
        tenant_id: 't1',
        code: 'taille',
        name: 'Taille',
        data_type: 'selection',
        is_variant_axis: true,
        display_order: 0,
        is_active: true,
      },
    ]
    render(<AttributeListPage />)
    expect(screen.getByText('Taille')).toBeInTheDocument()
    expect(screen.getByText('taille')).toBeInTheDocument()
  })

  it('opens the create form when Add clicked', async () => {
    const user = userEvent.setup()
    render(<AttributeListPage />)
    await user.click(screen.getByRole('button', { name: 'catalog:attributes.add' }))
    expect(screen.getByLabelText(/catalog:attributes\.code/)).toBeInTheDocument()
  })

  it('submits a new attribute via the form', async () => {
    mockCreateMutate.mockResolvedValue({ id: 'new-attr' })
    const user = userEvent.setup()
    render(<AttributeListPage />)

    await user.click(screen.getByRole('button', { name: 'catalog:attributes.add' }))
    await user.type(screen.getByLabelText(/catalog:attributes\.code/), 'couleur')
    await user.type(screen.getByLabelText(/catalog:attributes\.name/), 'Couleur')
    await user.click(screen.getByRole('button', { name: 'catalog:attributes.save' }))

    await waitFor(() => {
      expect(mockCreateMutate).toHaveBeenCalledWith(
        expect.objectContaining({ code: 'couleur', name: 'Couleur' }),
      )
    })
  })

  it('stays on /catalog/attributes after saving (no navigation, submit prevented)', async () => {
    mockCreateMutate.mockResolvedValue({ id: 'new-attr' })
    const user = userEvent.setup()

    // jsdom never performs a real form navigation, so asserting the URL alone
    // cannot catch a missing preventDefault. We additionally observe the native
    // submit event in the bubble phase (which runs AFTER React's onSubmit
    // handler) and assert it was defaultPrevented — that is the regression guard
    // for the redirect-to-/inventory/movements bug.
    let submitDefaultPrevented: boolean | null = null
    const onNativeSubmit = (e: Event) => {
      submitDefaultPrevented = e.defaultPrevented
    }
    document.addEventListener('submit', onNativeSubmit)

    function LocationProbe() {
      const location = useLocation()
      return <div data-testid="pathname">{location.pathname}</div>
    }

    try {
      render(
        <MemoryRouter initialEntries={['/catalog/attributes']}>
          <LocationProbe />
          <Routes>
            <Route path="/catalog/attributes" element={<AttributeListPage />} />
            <Route path="*" element={<div>OTHER ROUTE</div>} />
          </Routes>
        </MemoryRouter>,
      )

      await user.click(screen.getByRole('button', { name: 'catalog:attributes.add' }))
      await user.type(screen.getByLabelText(/catalog:attributes\.code/), 'couleur')
      await user.type(screen.getByLabelText(/catalog:attributes\.name/), 'Couleur')
      await user.click(screen.getByRole('button', { name: 'catalog:attributes.save' }))

      await waitFor(() => { expect(mockCreateMutate).toHaveBeenCalled() })

      expect(screen.getByTestId('pathname').textContent).toBe('/catalog/attributes')
      expect(screen.queryByText('OTHER ROUTE')).not.toBeInTheDocument()
      expect(submitDefaultPrevented).toBe(true)
    } finally {
      document.removeEventListener('submit', onNativeSubmit)
    }
  })

  it('hides the Add button without the create permission', () => {
    mockHasPermission = vi.fn().mockReturnValue(false)
    render(<AttributeListPage />)
    expect(
      screen.queryByRole('button', { name: 'catalog:attributes.add' }),
    ).not.toBeInTheDocument()
  })
})
