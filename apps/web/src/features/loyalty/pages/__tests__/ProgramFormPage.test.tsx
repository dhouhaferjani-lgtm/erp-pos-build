import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProgramFormPage } from '../ProgramFormPage'

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

describe('ProgramFormPage', () => {
  beforeEach(() => {
    mockParams = {}
    mockNavigate.mockClear()
  })

  it('renders create mode with correct title', () => {
    render(<ProgramFormPage />)
    expect(screen.getAllByText('loyalty:programs.create').length).toBeGreaterThanOrEqual(1)
  })

  it('renders edit mode with correct title when id param exists', () => {
    mockParams = { id: '123' }
    render(<ProgramFormPage />)
    expect(screen.getByText('loyalty:programs.edit')).toBeInTheDocument()
  })

  it('renders all form fields', () => {
    render(<ProgramFormPage />)
    expect(screen.getByText('loyalty:fields.name')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.programType')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.currency')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.startDate')).toBeInTheDocument()
    expect(screen.getByText('loyalty:fields.endDate')).toBeInTheDocument()
  })

  it('renders cancel and submit buttons', () => {
    render(<ProgramFormPage />)
    expect(screen.getByText('common:cancel')).toBeInTheDocument()
    expect(screen.getAllByText('loyalty:programs.create').length).toBeGreaterThanOrEqual(1)
  })
})
