import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { GrowthDashboard } from '../GrowthDashboard'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
}))

const mockProfileRefetch = vi.fn()
const mockMilestonesRefetch = vi.fn()
const mockRecommendationsRefetch = vi.fn()

interface MockQueryReturn {
  data: unknown
  isLoading: boolean
  isError: boolean
  refetch: ReturnType<typeof vi.fn>
}

let mockProfileQuery: MockQueryReturn
let mockMilestonesQuery: MockQueryReturn
let mockRecommendationsQuery: MockQueryReturn

vi.mock('../../hooks/useCompanyProgression', () => ({
  useCompanyProfile: () => mockProfileQuery,
  useMilestones: () => mockMilestonesQuery,
  progressionKeys: {
    all: ['progression'],
    profile: () => ['progression', 'profile'],
    milestones: () => ['progression', 'milestones'],
    modules: () => ['progression', 'modules'],
    recommendations: () => ['progression', 'recommendations'],
  },
}))

vi.mock('../../hooks/useRecommendations', () => ({
  useRecommendations: () => mockRecommendationsQuery,
  useAcceptRecommendation: () => ({ mutate: vi.fn(), isPending: false }),
  useDismissRecommendation: () => ({ mutate: vi.fn(), isPending: false }),
}))

describe('GrowthDashboard', () => {
  beforeEach(() => {
    mockProfileQuery = {
      data: {
        id: '1',
        tenant_id: 't1',
        vertical: 'retail',
        country: 'FR',
        current_stage: 'launch',
        stage_progress_percent: 45,
        total_milestones: 10,
        completed_milestones: 4,
      },
      isLoading: false,
      isError: false,
      refetch: mockProfileRefetch,
    }
    mockMilestonesQuery = {
      data: [
        { id: 'm1', name: 'First Sale', description: 'Complete first sale', status: 'completed', progress_percent: 100, stage: 'launch' },
      ],
      isLoading: false,
      isError: false,
      refetch: mockMilestonesRefetch,
    }
    mockRecommendationsQuery = {
      data: [
        { id: 'r1', title: 'Add products', description: 'Add 50 products', priority: 'high', action_label: '', action_route: '', status: 'pending' },
      ],
      isLoading: false,
      isError: false,
      refetch: mockRecommendationsRefetch,
    }
  })

  it('renders loading state with spinner', () => {
    mockProfileQuery = { ...mockProfileQuery, data: undefined, isLoading: true }
    mockMilestonesQuery = { ...mockMilestonesQuery, data: undefined, isLoading: true }
    mockRecommendationsQuery = { ...mockRecommendationsQuery, data: undefined, isLoading: true }
    render(<GrowthDashboard />)
    expect(screen.queryByText('dashboard.title')).not.toBeInTheDocument()
  })

  it('renders error state when profile fails', () => {
    mockProfileQuery = { ...mockProfileQuery, data: undefined, isLoading: false, isError: true }
    render(<GrowthDashboard />)
    expect(screen.getByText('dashboard.unavailable')).toBeInTheDocument()
    expect(screen.getByText('dashboard.retry')).toBeInTheDocument()
  })

  it('renders error state when milestones AND recommendations both fail', () => {
    mockMilestonesQuery = { ...mockMilestonesQuery, data: undefined, isLoading: false, isError: true }
    mockRecommendationsQuery = { ...mockRecommendationsQuery, data: undefined, isLoading: false, isError: true }
    render(<GrowthDashboard />)
    expect(screen.getByText('dashboard.unavailable')).toBeInTheDocument()
  })

  it('renders success state with dashboard content', () => {
    render(<GrowthDashboard />)
    expect(screen.getByText('dashboard.title')).toBeInTheDocument()
    expect(screen.getByText('dashboard.subtitle')).toBeInTheDocument()
    expect(screen.getByText('First Sale')).toBeInTheDocument()
    expect(screen.getByText('Add products')).toBeInTheDocument()
  })

  it('calls refetch on retry button click', () => {
    mockProfileQuery = { ...mockProfileQuery, data: undefined, isLoading: false, isError: true }
    mockMilestonesQuery = { ...mockMilestonesQuery, data: undefined, isLoading: false, isError: true }
    mockRecommendationsQuery = { ...mockRecommendationsQuery, data: undefined, isLoading: false, isError: true }
    render(<GrowthDashboard />)
    fireEvent.click(screen.getByText('dashboard.retry'))
    expect(mockProfileRefetch).toHaveBeenCalled()
    expect(mockMilestonesRefetch).toHaveBeenCalled()
    expect(mockRecommendationsRefetch).toHaveBeenCalled()
  })
})
