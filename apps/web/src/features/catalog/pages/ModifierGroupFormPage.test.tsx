import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { ModifierGroupFormPage } from './ModifierGroupFormPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
let mockParams: { id?: string } = { id: 'group-1' }
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const mockGroup = {
  id: 'group-1',
  code: 'SIZE',
  name: 'Size',
  selection_type: 'single',
  min_selections: 1,
  max_selections: 1,
  is_required: true,
  is_active: true,
  modifiers: [
    {
      id: 'mod-1', code: 'SM', name: 'Small', price_adjustment: '0.000',
      component_id: null, component_quantity: null, is_default: true,
    },
  ],
}

const mockUpdateMutate = vi.fn()

vi.mock('../hooks/useModifierGroups', () => ({
  useModifierGroup: () => ({ data: mockGroup, isLoading: false }),
  useCreateModifierGroup: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateModifierGroup: () => ({ mutate: mockUpdateMutate, isPending: false }),
  useCreateModifier: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateModifier: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteModifier: () => ({ mutate: vi.fn(), isPending: false }),
}))

vi.mock('../hooks/useVerticalLabels', () => ({
  useCompanyVerticalLabels: () => (key: string) => key,
}))

vi.mock('@/contexts', () => ({
  useCompanyConfig: () => ({ config: { vertical: 'fnb', currency: 'TND' }, hasModule: () => false }),
}))

vi.mock('@/components/molecules/line-items', () => ({
  ProductLineSelect: () => null,
}))

describe('ModifierGroupFormPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockParams = { id: 'group-1' }
    mockNavigate.mockReset()
    mockUpdateMutate.mockReset()
  })

  it('renders the page title via a single PageHeader h1', () => {
    render(<ModifierGroupFormPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the code field via the FormField atom (labelled input)', () => {
    render(<ModifierGroupFormPage />)
    const codeInput = screen.getByLabelText(/catalog:code/i)
    expect(codeInput.tagName).toBe('INPUT')
    expect((codeInput as HTMLInputElement).value).toBe('SIZE')
  })

  it('renders the selection-type field as a <select>', () => {
    render(<ModifierGroupFormPage />)
    const select = screen.getByLabelText(/catalog:selectionType/i)
    expect(select.tagName).toBe('SELECT')
  })

  it('renders the submit Save action as a <button>', () => {
    render(<ModifierGroupFormPage />)
    const saveButton = screen.getByRole('button', { name: /common:save/i })
    expect(saveButton.tagName).toBe('BUTTON')
    expect((saveButton as HTMLButtonElement).type).toBe('submit')
  })

  it('submits the group as an update with unchanged field/payload names', async () => {
    render(<ModifierGroupFormPage />)
    fireEvent.click(screen.getByRole('button', { name: /common:save/i }))
    await waitFor(() => { expect(mockUpdateMutate).toHaveBeenCalled() })
    const [arg] = mockUpdateMutate.mock.calls[0] as [{ id: string; data: Record<string, unknown> }]
    expect(arg).toEqual({
      id: 'group-1',
      data: {
        code: 'SIZE',
        name: 'Size',
        selection_type: 'single',
        min_selections: 1,
        max_selections: 1,
        is_required: true,
        is_active: true,
      },
    })
  })
})
