import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ContactListPage } from '../ContactListPage'
import type { ContactListResponse } from '../../api/contactApi'

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

// Mock react-router-dom
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// Mock the contact API
vi.mock('../../api/contactApi', async () => {
  const actual = await vi.importActual('../../api/contactApi')
  return {
    ...actual,
    fetchContacts: vi.fn(),
  }
})

// Mock tanstack query
const mockQueryData: ContactListResponse = {
  data: [
    {
      id: '1',
      first_name: 'Alice',
      last_name: 'Smith',
      full_name: 'Alice Smith',
      email: 'alice@example.com',
      phone: '+33612345678',
      mobile: null,
      date_of_birth: null,
      gender: null,
      national_id: null,
      notes: null,
      is_active: true,
      created_at: '2026-01-01T00:00:00Z',
      updated_at: null,
      parties: [{ id: 'p1', name: 'ACME Corp', type: 'customer', job_title: null, department: null, is_primary: true }],
    },
    {
      id: '2',
      first_name: 'Bob',
      last_name: null,
      full_name: 'Bob',
      email: null,
      phone: null,
      mobile: null,
      date_of_birth: null,
      gender: null,
      national_id: null,
      notes: null,
      is_active: false,
      created_at: '2026-01-02T00:00:00Z',
      updated_at: null,
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 },
}

let mockUseQueryReturn: { data: ContactListResponse | undefined; isLoading: boolean } = {
  data: mockQueryData,
  isLoading: false,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockUseQueryReturn,
  }
})

describe('ContactListPage', () => {
  it('renders contact table with data', () => {
    render(<ContactListPage />)

    expect(screen.getByText('Alice Smith')).toBeInTheDocument()
    expect(screen.getByText('Bob')).toBeInTheDocument()
    expect(screen.getByText('+33612345678')).toBeInTheDocument()
    expect(screen.getByText('alice@example.com')).toBeInTheDocument()
    expect(screen.getByText('ACME Corp')).toBeInTheDocument()
  })

  it('renders loading state', () => {
    mockUseQueryReturn = { data: undefined, isLoading: true }

    render(<ContactListPage />)

    expect(screen.getByText('common:common.loading')).toBeInTheDocument()

    // Reset
    mockUseQueryReturn = { data: mockQueryData, isLoading: false }
  })

  it('renders empty state message', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } },
      isLoading: false,
    }

    render(<ContactListPage />)

    expect(screen.getByText('crm:contacts.noContacts')).toBeInTheDocument()

    // Reset
    mockUseQueryReturn = { data: mockQueryData, isLoading: false }
  })

  it('navigates to detail on row click', async () => {
    mockUseQueryReturn = { data: mockQueryData, isLoading: false }
    const user = userEvent.setup()

    render(<ContactListPage />)

    const row = screen.getByText('Alice Smith').closest('tr')
    expect(row).toBeTruthy()
    await user.click(row!)

    expect(mockNavigate).toHaveBeenCalledWith('/crm/contacts/1')
  })

  it('renders active/inactive status badges', () => {
    mockUseQueryReturn = { data: mockQueryData, isLoading: false }

    render(<ContactListPage />)

    expect(screen.getByText('crm:contacts.filters.active')).toBeInTheDocument()
    expect(screen.getByText('crm:contacts.filters.inactive')).toBeInTheDocument()
  })

  it('has new contact link', () => {
    mockUseQueryReturn = { data: mockQueryData, isLoading: false }

    render(<ContactListPage />)

    const newLink = screen.getByText('crm:contacts.newContact')
    expect(newLink.closest('a')).toHaveAttribute('href', '/crm/contacts/new')
  })
})
