import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ContactFormPage } from '../ContactFormPage'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

// Mock react-router-dom
const mockNavigate = vi.fn()
let mockParams: Record<string, string> = {}
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// Mock sonner
vi.mock('sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}))

// Mock api lib
vi.mock('@/lib/api', () => ({
  api: { get: vi.fn() },
  getErrorMessage: (err: unknown) => (err instanceof Error ? err.message : 'An error occurred'),
}))

// Mock contact API
const mockCreateContact = vi.fn()
const mockUpdateContact = vi.fn()
const mockFetchContact = vi.fn()
vi.mock('../../api/contactApi', () => ({
  fetchContact: (...args: unknown[]) => mockFetchContact(...args),
  createContact: (...args: unknown[]) => mockCreateContact(...args),
  updateContact: (...args: unknown[]) => mockUpdateContact(...args),
  contactKeys: {
    all: ['contacts'],
    lists: () => ['contacts', 'list'],
    list: (f: unknown) => ['contacts', 'list', f],
    details: () => ['contacts', 'detail'],
    detail: (id: string) => ['contacts', 'detail', id],
  },
  contactsInvalidationPredicate: () => () => false,
}))

// Mock PartnerPicker
vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: ({
    value,
    onChange,
  }: {
    value: string | null
    onChange: (v: { id: string; name: string; type: 'customer' } | null) => void
  }) => (
    <input
      data-testid="partner-select"
      value={value ?? ''}
      onChange={(e) => {
        onChange(e.target.value === '' ? null : {
          id: e.target.value,
          name: 'Selected company',
          type: 'customer',
        })
      }}
    />
  ),
}))

// Mock tanstack query
const mockInvalidateQueries = vi.fn()
let mockQueryResult: { data: unknown } = { data: undefined }
const mockMutate = vi.fn()
let mockMutationState = { isPending: false }

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => mockQueryResult,
  useQueryClient: () => ({ invalidateQueries: mockInvalidateQueries }),
  useMutation: ({ mutationFn, onSuccess }: { mutationFn: (data: unknown) => Promise<unknown>; onSuccess: (data: unknown) => void }) => {
    mockMutate.mockImplementation(async (data: unknown) => {
      const result = await mutationFn(data)
      onSuccess(result)
    })
    return {
      mutate: mockMutate,
      isPending: mockMutationState.isPending,
    }
  },
}))

describe('ContactFormPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockParams = {}
    mockQueryResult = { data: undefined }
    mockMutationState = { isPending: false }
  })

  it('renders create mode with empty form', () => {
    render(<ContactFormPage />)

    expect(screen.getByText('crm:contacts.newContact')).toBeInTheDocument()
    expect(screen.getByLabelText(/crm:contacts.firstName/)).toHaveValue('')
    expect(screen.getByLabelText(/crm:contacts.lastName/)).toHaveValue('')
  })

  it('renders edit mode title when id param present', () => {
    mockParams = { id: '123' }
    mockQueryResult = {
      data: {
        id: '123',
        first_name: 'John',
        last_name: 'Doe',
        full_name: 'John Doe',
        email: 'john@example.com',
        phone: null,
        mobile: null,
        date_of_birth: null,
        gender: null,
        national_id: null,
        notes: null,
        is_active: true,
        created_at: '2026-01-01',
        updated_at: null,
      },
    }

    render(<ContactFormPage />)

    expect(screen.getByText('crm:contacts.editContact')).toBeInTheDocument()
  })

  it('calls createContact on form submission', async () => {
    mockCreateContact.mockResolvedValue({ id: 'new-id', full_name: 'Test' })
    const user = userEvent.setup()

    render(<ContactFormPage />)

    const firstNameInput = screen.getByLabelText(/crm:contacts.firstName/)
    await user.type(firstNameInput, 'Test')

    const submitButton = screen.getByText('common:actions.create')
    await user.click(submitButton)

    expect(mockMutate).toHaveBeenCalledTimes(1)
  })

  it('shows back link to contacts list', () => {
    render(<ContactFormPage />)

    const backLink = screen.getByText('common:actions.back')
    expect(backLink.closest('a')).toHaveAttribute('href', '/crm/contacts')
  })

  it('has cancel link that points to contacts list', () => {
    render(<ContactFormPage />)

    const cancelLink = screen.getByText('common:actions.cancel')
    expect(cancelLink.closest('a')).toHaveAttribute('href', '/crm/contacts')
  })

  it('renders company association section', () => {
    render(<ContactFormPage />)

    expect(screen.getByText('crm:contacts.companyAssociations')).toBeInTheDocument()
    expect(screen.getByTestId('partner-select')).toBeInTheDocument()
    expect(screen.getByLabelText(/crm:contacts.jobTitle/)).toBeInTheDocument()
  })

  it('submits with party_id when company selected', async () => {
    mockCreateContact.mockResolvedValue({ id: 'new-id', full_name: 'Test' })
    const user = userEvent.setup()

    render(<ContactFormPage />)

    await user.type(screen.getByLabelText(/crm:contacts.firstName/), 'Test')

    const partnerSelect = screen.getByTestId('partner-select')
    await user.clear(partnerSelect)
    await user.type(partnerSelect, 'partner-123')

    await user.type(screen.getByLabelText(/crm:contacts.jobTitle/), 'Manager')

    const submitButton = screen.getByText('common:actions.create')
    await user.click(submitButton)

    expect(mockMutate).toHaveBeenCalledTimes(1)
  })
})
