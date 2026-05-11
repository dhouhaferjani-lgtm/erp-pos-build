import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import type { DocumentType, TaxConfiguration, TaxConfigurationFormData } from '../../types/tax'
import {
  useCreateTaxConfiguration,
  useDeleteTaxConfiguration,
  useDocumentTypes,
  useReorderTaxConfigurations,
  useTaxConfiguration,
  useTaxConfigurations,
  useUpdateTaxConfiguration,
} from '../useTaxConfigurations'

const mockList = vi.hoisted(() => vi.fn())
const mockGet = vi.hoisted(() => vi.fn())
const mockCreate = vi.hoisted(() => vi.fn())
const mockUpdate = vi.hoisted(() => vi.fn())
const mockDelete = vi.hoisted(() => vi.fn())
const mockReorder = vi.hoisted(() => vi.fn())
const mockGetDocumentTypes = vi.hoisted(() => vi.fn())

vi.mock('../../api/taxConfigurationApi', () => ({
  taxConfigurationApi: {
    list: mockList,
    get: mockGet,
    create: mockCreate,
    update: mockUpdate,
    delete: mockDelete,
    reorder: mockReorder,
    getDocumentTypes: mockGetDocumentTypes,
  },
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.test',
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
    companies: [],
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

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

function cacheKeys(queryClient: QueryClient): unknown[][] {
  return queryClient
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

function taxConfigurationFixture(id: string): TaxConfiguration {
  return {
    id,
    country_code: 'TN',
    name: `VAT ${id}`,
    code: `VAT-${id}`,
    tax_type: 'PERCENTAGE',
    percentage_rate: '19.000',
    fixed_amount: null,
    applies_to: 'LINE_ITEMS',
    sequence_order: 1,
    stacks_on: 'BASE_AMOUNT',
    applicable_document_types: ['invoice'],
    is_default: false,
    is_active: true,
    is_stamp_duty: false,
    is_recoverable: true,
    created_at: '2026-05-11T09:00:00Z',
    updated_at: '2026-05-11T09:00:00Z',
  }
}

const documentTypes: DocumentType[] = [
  { value: 'invoice', label: 'Invoice' },
]

const createPayload: TaxConfigurationFormData = {
  name: 'VAT',
  tax_type: 'PERCENTAGE',
  percentage_rate: '19.000',
  applies_to: 'LINE_ITEMS',
  stacks_on: 'BASE_AMOUNT',
  applicable_document_types: ['invoice'],
  is_active: true,
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')

  mockList.mockResolvedValue([taxConfigurationFixture('tax-1')])
  mockGet.mockResolvedValue(taxConfigurationFixture('tax-1'))
  mockCreate.mockResolvedValue(taxConfigurationFixture('tax-new'))
  mockUpdate.mockResolvedValue(taxConfigurationFixture('tax-1'))
  mockDelete.mockResolvedValue(undefined)
  mockReorder.mockResolvedValue(undefined)
  mockGetDocumentTypes.mockResolvedValue(documentTypes)
})

afterEach(() => {
  resetTenant()
})

describe('useTaxConfigurations tenant scope', () => {
  it('wraps tax configuration read query keys with the active tenant and company (.660-.662)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    const { result } = renderHook(() => ({
      list: useTaxConfigurations(),
      detail: useTaxConfiguration('tax-1'),
      documentTypes: useDocumentTypes(),
    }), { wrapper })

    await waitFor(() => {
      expect(result.current.list.isSuccess).toBe(true)
      expect(result.current.detail.isSuccess).toBe(true)
      expect(result.current.documentTypes.isSuccess).toBe(true)
    })

    expect(cacheKeys(queryClient)).toEqual(expect.arrayContaining([
      ['tax-configurations', 'list', 'tenant-A', 'company-1'],
      ['tax-configurations', 'detail', 'tax-1', 'tenant-A', 'company-1'],
      ['tax-configurations', 'document-types', 'tenant-A', 'company-1'],
    ]))
  })

  it('does not fetch tax configuration reads without tenant/company scope', () => {
    resetTenant()
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)

    renderHook(() => ({
      list: useTaxConfigurations(),
      detail: useTaxConfiguration('tax-1'),
      documentTypes: useDocumentTypes(),
    }), { wrapper })

    expect(mockList).not.toHaveBeenCalled()
    expect(mockGet).not.toHaveBeenCalled()
    expect(mockGetDocumentTypes).not.toHaveBeenCalled()
  })

  it('keeps mutation invalidation inside the active tenant cache (.663-.667)', async () => {
    const queryClient = createPersistentQueryClient()
    const wrapper = makeWrapper(queryClient)
    let listCalls = 0
    let detailCalls = 0

    mockList.mockImplementation(async () => {
      listCalls += 1
      return [taxConfigurationFixture(`tax-list-${listCalls}`)]
    })
    mockGet.mockImplementation(async () => {
      detailCalls += 1
      return taxConfigurationFixture(`tax-detail-${detailCalls}`)
    })

    queryClient.setQueryData(
      ['tax-configurations', 'list', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-list-preserved' },
    )
    queryClient.setQueryData(
      ['tax-configurations', 'detail', 'tax-1', 'tenant-B', 'company-1'],
      { marker: 'tenant-B-detail-preserved' },
    )

    const { result: reads } = renderHook(() => ({
      list: useTaxConfigurations(),
      detail: useTaxConfiguration('tax-1'),
    }), { wrapper })
    await waitFor(() => {
      expect(reads.current.list.isSuccess).toBe(true)
      expect(reads.current.detail.isSuccess).toBe(true)
      expect(listCalls).toBe(1)
      expect(detailCalls).toBe(1)
    })

    const { result: mutations } = renderHook(() => ({
      create: useCreateTaxConfiguration(),
      update: useUpdateTaxConfiguration(),
      remove: useDeleteTaxConfiguration(),
      reorder: useReorderTaxConfigurations(),
    }), { wrapper })

    await act(async () => {
      await mutations.current.create.mutateAsync(createPayload)
    })
    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(detailCalls).toBe(1)
    })

    await act(async () => {
      await mutations.current.update.mutateAsync({
        id: 'tax-1',
        data: { name: 'VAT updated' },
      })
    })
    await waitFor(() => {
      expect(listCalls).toBe(3)
      expect(detailCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.remove.mutateAsync('tax-1')
    })
    await waitFor(() => {
      expect(listCalls).toBe(4)
      expect(detailCalls).toBe(2)
    })

    await act(async () => {
      await mutations.current.reorder.mutateAsync([{ id: 'tax-1', sequence_order: 1 }])
    })
    await waitFor(() => {
      expect(listCalls).toBe(5)
      expect(detailCalls).toBe(2)
    })

    expect(queryClient.getQueryData([
      'tax-configurations',
      'list',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-list-preserved' })
    expect(queryClient.getQueryData([
      'tax-configurations',
      'detail',
      'tax-1',
      'tenant-B',
      'company-1',
    ])).toEqual({ marker: 'tenant-B-detail-preserved' })
  })
})
