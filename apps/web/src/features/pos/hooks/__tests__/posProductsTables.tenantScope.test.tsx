import { QueryClient } from '@tanstack/react-query'
import { waitFor } from '@testing-library/react'
import { useRef } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { usePOSProducts } from '../usePOSProducts'
import {
  tableKeys,
  useCreateFloor,
  useCreateTable,
  useDeleteFloor,
  useDeleteTable,
  useFloors,
  useReleaseTable,
  useSetTableStatus,
  useTables,
  useUpdateFloor,
  useUpdateTable,
} from '../useTables'
import type { POSProduct } from '../../api/productApi'
import type {
  CreateFloorRequest,
  CreateTableRequest,
  FloorData,
  TableData,
  UpdateFloorRequest,
  UpdateTableRequest,
} from '../../api/tableApi'

const mockProductApi = vi.hoisted(() => ({
  fetchPOSProducts: vi.fn(),
}))
const mockTableApi = vi.hoisted(() => ({
  getFloors: vi.fn(),
  getTables: vi.fn(),
  createFloor: vi.fn(),
  updateFloor: vi.fn(),
  deleteFloor: vi.fn(),
  createTable: vi.fn(),
  updateTable: vi.fn(),
  deleteTable: vi.fn(),
  releaseTable: vi.fn(),
  setTableStatus: vi.fn(),
}))

vi.mock('../../api/productApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/productApi')>('../../api/productApi')
  return {
    ...actual,
    ...mockProductApi,
  }
})

vi.mock('../../api/tableApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/tableApi')>('../../api/tableApi')
  return {
    ...actual,
    ...mockTableApi,
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

const product: POSProduct = {
  id: 'product-1',
  name: 'Coffee',
  sku: 'COF',
  sale_price: '3.000',
  stock_quantity: 10,
}

const floor: FloorData = {
  id: 'floor-1',
  name: 'Main',
  position: 1,
  is_active: true,
  created_at: '2026-05-11T10:00:00Z',
  updated_at: '2026-05-11T10:00:00Z',
}

const table: TableData = {
  id: 'table-1',
  floor_id: 'floor-1',
  table_number: 'T1',
  label: null,
  seats: 4,
  status: 'available',
  shape: null,
  position_x: null,
  position_y: null,
  width: null,
  height: null,
  current_order_id: null,
  created_at: '2026-05-11T10:00:00Z',
  updated_at: '2026-05-11T10:00:00Z',
}

const createFloorRequest: CreateFloorRequest = { name: 'Patio' }
const updateFloorRequest: UpdateFloorRequest = { name: 'Dining' }
const createTableRequest: CreateTableRequest = { floor_id: 'floor-1', table_number: 'T2' }
const updateTableRequest: UpdateTableRequest = { label: 'Window' }

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockProductApi.fetchPOSProducts.mockResolvedValue([product])
  mockTableApi.getFloors.mockResolvedValue([floor])
  mockTableApi.getTables.mockResolvedValue([table])
  mockTableApi.createFloor.mockResolvedValue(floor)
  mockTableApi.updateFloor.mockResolvedValue(floor)
  mockTableApi.deleteFloor.mockResolvedValue(undefined)
  mockTableApi.createTable.mockResolvedValue(table)
  mockTableApi.updateTable.mockResolvedValue(table)
  mockTableApi.deleteTable.mockResolvedValue(undefined)
  mockTableApi.releaseTable.mockResolvedValue(table)
  mockTableApi.setTableStatus.mockResolvedValue(table)
})

afterEach(() => {
  resetTenant()
})

describe('POS products and tables queryKey tenant scope', () => {
  function QueryProbe() {
    usePOSProducts({ search: 'coffee', limit: 20 })
    useFloors()
    useTables({ floor_id: 'floor-1' })
    return null
  }

  it('scopes product, floor, and table query keys (.500-.502)', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<QueryProbe />, { queryClient })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['pos', 'products', 'list', { search: 'coffee', limit: 20 }, 'tenant-A', 'company-1'],
      ['tables', 'floors', 'tenant-A', 'company-1'],
      ['tables', 'list', { floor_id: 'floor-1' }, 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch without tenant/company state', () => {
    resetTenant()

    renderWithProviders(<QueryProbe />)

    expect(mockProductApi.fetchPOSProducts).not.toHaveBeenCalled()
    expect(mockTableApi.getFloors).not.toHaveBeenCalled()
    expect(mockTableApi.getTables).not.toHaveBeenCalled()
  })
})

describe('POS table mutation invalidation', () => {
  function TablesMutationProbe() {
    const floorFetchesRef = useRef(0)
    const tableFetchesRef = useRef(0)
    ;(globalThis as Record<string, unknown>)['__tableCounters'] = {
      floors: () => floorFetchesRef.current,
      tables: () => tableFetchesRef.current,
    }
    mockTableApi.getFloors.mockImplementation(async () => {
      floorFetchesRef.current += 1
      return [floor]
    })
    mockTableApi.getTables.mockImplementation(async () => {
      tableFetchesRef.current += 1
      return [table]
    })
    useFloors()
    useTables({ floor_id: 'floor-1' })
    const createFloor = useCreateFloor()
    const updateFloor = useUpdateFloor()
    const deleteFloor = useDeleteFloor()
    const createTable = useCreateTable()
    const updateTable = useUpdateTable()
    const deleteTable = useDeleteTable()
    const releaseTable = useReleaseTable()
    const setStatus = useSetTableStatus()
    ;(globalThis as Record<string, unknown>)['__tableMutations'] = {
      createFloor,
      updateFloor,
      deleteFloor,
      createTable,
      updateTable,
      deleteTable,
      releaseTable,
      setStatus,
    }
    return null
  }

  function tableCounters() {
    return (globalThis as Record<string, unknown>)['__tableCounters'] as {
      floors: () => number
      tables: () => number
    }
  }

  function tableMutations() {
    return (globalThis as Record<string, unknown>)['__tableMutations'] as {
      createFloor: { mutateAsync: (input: CreateFloorRequest) => Promise<unknown> }
      updateFloor: { mutateAsync: (input: { id: string; data: UpdateFloorRequest }) => Promise<unknown> }
      deleteFloor: { mutateAsync: (id: string) => Promise<unknown> }
      createTable: { mutateAsync: (input: CreateTableRequest) => Promise<unknown> }
      updateTable: { mutateAsync: (input: { id: string; data: UpdateTableRequest }) => Promise<unknown> }
      deleteTable: { mutateAsync: (id: string) => Promise<unknown> }
      releaseTable: { mutateAsync: (id: string) => Promise<unknown> }
      setStatus: { mutateAsync: (input: { id: string; status: string }) => Promise<unknown> }
    }
  }

  it('refetches current-tenant table caches and preserves tenant-B cache (.503-.510)', async () => {
    const queryClient = createPersistentQueryClient()
    renderWithProviders(<TablesMutationProbe />, { queryClient })

    await waitFor(() => {
      expect(tableCounters().floors()).toBe(1)
      expect(tableCounters().tables()).toBe(1)
    })

    queryClient.setQueryData([...tableKeys.floors(), 'tenant-B', 'company-1'], { marker: 'tenant-B-floors' })
    queryClient.setQueryData([...tableKeys.tableList({ floor_id: 'floor-1' }), 'tenant-B', 'company-1'], {
      marker: 'tenant-B-tables',
    })

    await tableMutations().createFloor.mutateAsync(createFloorRequest)
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(2)
      expect(tableCounters().tables()).toBe(1)
    })

    await tableMutations().updateFloor.mutateAsync({ id: 'floor-1', data: updateFloorRequest })
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(3)
      expect(tableCounters().tables()).toBe(1)
    })

    await tableMutations().deleteFloor.mutateAsync('floor-1')
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(4)
      expect(tableCounters().tables()).toBe(1)
    })

    await tableMutations().createTable.mutateAsync(createTableRequest)
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(5)
      expect(tableCounters().tables()).toBe(2)
    })

    await tableMutations().updateTable.mutateAsync({ id: 'table-1', data: updateTableRequest })
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(6)
      expect(tableCounters().tables()).toBe(3)
    })

    await tableMutations().deleteTable.mutateAsync('table-1')
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(7)
      expect(tableCounters().tables()).toBe(4)
    })

    await tableMutations().releaseTable.mutateAsync('table-1')
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(8)
      expect(tableCounters().tables()).toBe(5)
    })

    await tableMutations().setStatus.mutateAsync({ id: 'table-1', status: 'reserved' })
    await waitFor(() => {
      expect(tableCounters().floors()).toBe(9)
      expect(tableCounters().tables()).toBe(6)
    })
    expect(queryClient.getQueryData([...tableKeys.floors(), 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-floors',
    })
    expect(queryClient.getQueryData([...tableKeys.tableList({ floor_id: 'floor-1' }), 'tenant-B', 'company-1'])).toEqual({
      marker: 'tenant-B-tables',
    })
  })
})
