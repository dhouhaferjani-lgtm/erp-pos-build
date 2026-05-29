import { QueryClient } from '@tanstack/react-query'
import { waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import {
  terminalKeys,
  useActivateTerminal,
  useArchiveTerminal,
  useCreateTerminal,
  useDeactivateTerminal,
  useDeleteTerminal,
  useTerminal,
  useTerminals,
  useToggleTrainingMode,
  useUpdateTerminal,
} from '../useTerminals'
import type {
  CreateTerminalInput,
  DeactivateTerminalInput,
  Terminal,
  UpdateTerminalInput,
} from '../../api/terminalApi'

const mockTerminalApi = vi.hoisted(() => ({
  fetchTerminals: vi.fn(),
  fetchTerminal: vi.fn(),
  createTerminal: vi.fn(),
  updateTerminal: vi.fn(),
  archiveTerminal: vi.fn(),
  deleteTerminal: vi.fn(),
  activateTerminal: vi.fn(),
  deactivateTerminal: vi.fn(),
  toggleTrainingMode: vi.fn(),
}))

vi.mock('../../api/terminalApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/terminalApi')>('../../api/terminalApi')
  return {
    ...actual,
    ...mockTerminalApi,
  }
})

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [
      {
        id: companyId,
        name: 'Test Company',
        legalName: 'Test Company LLC',
        taxId: null,
        countryCode: 'TN',
        currency: 'TND',
        locale: 'en_US',
        timezone: 'Africa/Tunis',
      },
    ],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function cacheKeys(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

const terminal: Terminal = {
  id: 'terminal-1',
  type: 'web',
  code: 'TERM-1',
  name: 'Terminal 1',
  description: null,
  location_id: 'location-1',
  is_active: true,
  is_training_mode: false,
  activated_at: '2026-05-11T10:00:00Z',
  deactivated_at: null,
  deactivation_reason: null,
  has_history: false,
  current_sequence: 1,
  current_year: 2026,
  max_discount_percent: '10.00',
  allow_line_discounts: true,
  allow_transaction_discounts: true,
  created_at: '2026-05-11T10:00:00Z',
  updated_at: '2026-05-11T10:00:00Z',
}

const createRequest: CreateTerminalInput = {
  name: 'Terminal 2',
  location_id: 'location-1',
}

const updateRequest: UpdateTerminalInput = {
  name: 'Terminal One',
}

const deactivateRequest: DeactivateTerminalInput = {
  reason: 'maintenance',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockTerminalApi.fetchTerminals.mockResolvedValue([terminal])
  mockTerminalApi.fetchTerminal.mockResolvedValue(terminal)
  mockTerminalApi.createTerminal.mockResolvedValue(terminal)
  mockTerminalApi.updateTerminal.mockResolvedValue(terminal)
  mockTerminalApi.archiveTerminal.mockResolvedValue(undefined)
  mockTerminalApi.deleteTerminal.mockResolvedValue(undefined)
  mockTerminalApi.activateTerminal.mockResolvedValue(terminal)
  mockTerminalApi.deactivateTerminal.mockResolvedValue(terminal)
  mockTerminalApi.toggleTrainingMode.mockResolvedValue(terminal)
})

afterEach(() => {
  resetTenant()
})

describe('POS terminals queryKey tenant scope', () => {
  function QueryProbe() {
    useTerminals()
    useTerminal('terminal-1')
    return null
  }

  it('scopes terminal list and detail query keys (.511-.512)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<QueryProbe />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['terminals', 'list', 'tenant-A', 'company-1'],
      ['terminals', 'detail', 'terminal-1', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<QueryProbe />)

    expect(mockTerminalApi.fetchTerminals).not.toHaveBeenCalled()
    expect(mockTerminalApi.fetchTerminal).not.toHaveBeenCalled()
  })
})

describe('POS terminal mutation invalidation', () => {
  function TerminalsMutationProbe() {
    const listFetchesRef = useRef(0)
    const detailFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__terminalCounters'] = {
      list: () => listFetchesRef.current,
      detail: () => detailFetchesRef.current,
    }
    mockTerminalApi.fetchTerminals.mockImplementation(async () => {
      listFetchesRef.current += 1
      return [terminal]
    })
    mockTerminalApi.fetchTerminal.mockImplementation(async () => {
      detailFetchesRef.current += 1
      return terminal
    })
    useTerminals()
    useTerminal('terminal-1')
    const create = useCreateTerminal()
    const update = useUpdateTerminal()
    const archive = useArchiveTerminal()
    const remove = useDeleteTerminal()
    const activate = useActivateTerminal()
    const deactivate = useDeactivateTerminal()
    const toggle = useToggleTrainingMode()
    ;(globalThis as Record<string, unknown>)['__terminalMutations'] = {
      create,
      update,
      archive,
      remove,
      activate,
      deactivate,
      toggle,
    }
    return null
  }

  function terminalCounters() {
    return (globalThis as Record<string, unknown>)['__terminalCounters'] as {
      list: () => number
      detail: () => number
    }
  }

  function terminalMutations() {
    return (globalThis as Record<string, unknown>)['__terminalMutations'] as {
      create: { mutateAsync: (input: CreateTerminalInput) => Promise<unknown> }
      update: { mutateAsync: (input: { id: string; data: UpdateTerminalInput }) => Promise<unknown> }
      archive: { mutateAsync: (id: string) => Promise<unknown> }
      remove: { mutateAsync: (id: string) => Promise<unknown> }
      activate: { mutateAsync: (id: string) => Promise<unknown> }
      deactivate: { mutateAsync: (input: { id: string; data?: DeactivateTerminalInput | undefined }) => Promise<unknown> }
      toggle: { mutateAsync: (id: string) => Promise<unknown> }
    }
  }

  it('refetches current-tenant terminal caches and preserves tenant-B cache (.513-.523)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<TerminalsMutationProbe />, { queryClient })

    await waitFor(() => {
      expect(terminalCounters().list()).toBe(1)
      expect(terminalCounters().detail()).toBe(1)
    })

    queryClient.setQueryData([...terminalKeys.lists(), 'tenant-B', 'company-1'], { marker: 'tenant-B-list' })
    queryClient.setQueryData([...terminalKeys.detail('terminal-1'), 'tenant-B', 'company-1'], {
      marker: 'tenant-B-detail',
    })

    await terminalMutations().create.mutateAsync(createRequest)
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(2)
      expect(terminalCounters().detail()).toBe(1)
    })

    await terminalMutations().update.mutateAsync({ id: 'terminal-1', data: updateRequest })
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(3)
      expect(terminalCounters().detail()).toBe(2)
    })

    await terminalMutations().archive.mutateAsync('terminal-1')
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(4)
      expect(terminalCounters().detail()).toBe(2)
    })

    await terminalMutations().remove.mutateAsync('terminal-1')
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(5)
      expect(terminalCounters().detail()).toBe(2)
    })

    await terminalMutations().activate.mutateAsync('terminal-1')
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(6)
      expect(terminalCounters().detail()).toBe(3)
    })

    await terminalMutations().deactivate.mutateAsync({ id: 'terminal-1', data: deactivateRequest })
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(7)
      expect(terminalCounters().detail()).toBe(4)
    })

    await terminalMutations().toggle.mutateAsync('terminal-1')
    await waitFor(() => {
      expect(terminalCounters().list()).toBe(8)
      expect(terminalCounters().detail()).toBe(5)
    })
    expect(queryClient.getQueryData([...terminalKeys.lists(), 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-list',
    })
    expect(queryClient.getQueryData([...terminalKeys.detail('terminal-1'), 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-detail',
    })
  })
})
