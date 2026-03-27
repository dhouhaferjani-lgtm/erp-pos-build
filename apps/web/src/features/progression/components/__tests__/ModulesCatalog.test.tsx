import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ModulesCatalog } from '../ModulesCatalog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

interface MockQueryReturn {
  data: unknown
  isLoading: boolean
  isError: boolean
  refetch: ReturnType<typeof vi.fn>
}

let mockModulesQuery: MockQueryReturn
let mockProfileQuery: MockQueryReturn

vi.mock('../../hooks/useModuleReadiness', () => ({
  useModules: () => mockModulesQuery,
  useActivateModule: () => ({ mutate: vi.fn(), isPending: false }),
}))

vi.mock('../../hooks/useCompanyProgression', () => ({
  useCompanyProfile: () => mockProfileQuery,
  progressionKeys: {
    all: ['progression'],
    profile: () => ['progression', 'profile'],
    milestones: () => ['progression', 'milestones'],
    modules: () => ['progression', 'modules'],
    recommendations: () => ['progression', 'recommendations'],
  },
}))

describe('ModulesCatalog', () => {
  beforeEach(() => {
    mockModulesQuery = {
      data: [
        { id: 'mod1', name: 'Loyalty', description: 'Customer loyalty', icon: '🎯', status: 'ready', readiness_percent: 95, stage: 'launch', discount_percent: 20, requirements: [] },
        { id: 'mod2', name: 'Analytics', description: 'Advanced analytics', icon: '📊', status: 'locked', readiness_percent: 30, stage: 'optimize', discount_percent: 0, requirements: ['Complete 100 sales'] },
      ],
      isLoading: false,
      isError: false,
      refetch: vi.fn(),
    }
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
      refetch: vi.fn(),
    }
  })

  it('renders loading state with spinner', () => {
    mockModulesQuery = { ...mockModulesQuery, data: undefined, isLoading: true }
    render(<ModulesCatalog />)
    expect(screen.queryByText('modules.title')).not.toBeInTheDocument()
  })

  it('renders error state when modules query fails', () => {
    mockModulesQuery = { ...mockModulesQuery, data: undefined, isLoading: false, isError: true }
    render(<ModulesCatalog />)
    expect(screen.getByText('dashboard.unavailable')).toBeInTheDocument()
    expect(screen.getByText('dashboard.retry')).toBeInTheDocument()
  })

  it('renders success state with modules', () => {
    render(<ModulesCatalog />)
    expect(screen.getByText('modules.title')).toBeInTheDocument()
    expect(screen.getByText('modules.subtitle')).toBeInTheDocument()
    expect(screen.getByText('Loyalty')).toBeInTheDocument()
    expect(screen.getByText('Analytics')).toBeInTheDocument()
  })

  it('renders view toggle buttons', () => {
    render(<ModulesCatalog />)
    expect(screen.getByText('modules.viewRoadmap')).toBeInTheDocument()
    expect(screen.getByText('modules.viewGrid')).toBeInTheDocument()
  })
})
