import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemberFormPage } from '../MemberFormPage'

const mockNavigate = vi.fn()
let mockParams: Record<string, string> = {}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('MemberFormPage', () => {
  beforeEach(() => {
    mockParams = {}
    mockNavigate.mockClear()
  })

  it('renders create mode with correct title', () => {
    render(<MemberFormPage />)
    expect(screen.getAllByText('loyalty:members.create').length).toBeGreaterThanOrEqual(1)
  })

  it('renders edit mode with correct title when id param exists', () => {
    mockParams = { id: '123' }
    render(<MemberFormPage />)
    expect(screen.getByText('loyalty:members.edit')).toBeInTheDocument()
  })

  it('renders all form fields', () => {
    render(<MemberFormPage />)
    expect(screen.getByText('loyalty:fields.phone')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.email')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.firstName')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.lastName')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.dateOfBirth')).toBeInTheDocument()
  })

  it('renders cancel and submit buttons', () => {
    render(<MemberFormPage />)
    expect(screen.getByText('common:cancel')).toBeInTheDocument()
    expect(screen.getAllByText('loyalty:members.create').length).toBeGreaterThanOrEqual(1)
  })
})
