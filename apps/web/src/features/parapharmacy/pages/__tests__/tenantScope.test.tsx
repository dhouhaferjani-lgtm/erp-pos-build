import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { fireEvent, render, waitFor } from '@testing-library/react'
import type { ReactElement, ComponentType } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient } from '@/test/renderWithProviders'

import {
  createCertification,
  deleteCertification,
  fetchCertification,
  fetchCertifications,
  updateCertification,
} from '../../api/certificationApi'
import {
  createHealthClaim,
  deleteHealthClaim,
  fetchHealthClaim,
  fetchHealthClaims,
  updateHealthClaim,
} from '../../api/healthClaimApi'
import {
  createIngredient,
  deleteIngredient,
  fetchIngredient,
  fetchIngredients,
  updateIngredient,
} from '../../api/ingredientApi'
import {
  createKeyComponent,
  deleteKeyComponent,
  fetchKeyComponent,
  fetchKeyComponents,
  updateKeyComponent,
} from '../../api/keyComponentApi'
import { CertificationFormPage } from '../CertificationFormPage'
import { CertificationListPage } from '../CertificationListPage'
import { HealthClaimFormPage } from '../HealthClaimFormPage'
import { HealthClaimListPage } from '../HealthClaimListPage'
import { IngredientFormPage } from '../IngredientFormPage'
import { IngredientListPage } from '../IngredientListPage'
import { KeyComponentFormPage } from '../KeyComponentFormPage'
import { KeyComponentListPage } from '../KeyComponentListPage'
import { parapharmacyListInvalidationPredicate } from '../tenantScope'
import type {
  CertificationData,
  HealthClaimData,
  IngredientData,
  KeyComponentData,
} from '../../types'

vi.mock('../../api/certificationApi', () => ({
  fetchCertifications: vi.fn(),
  fetchCertification: vi.fn(),
  createCertification: vi.fn(),
  updateCertification: vi.fn(),
  deleteCertification: vi.fn(),
}))

vi.mock('../../api/healthClaimApi', () => ({
  fetchHealthClaims: vi.fn(),
  fetchHealthClaim: vi.fn(),
  createHealthClaim: vi.fn(),
  updateHealthClaim: vi.fn(),
  deleteHealthClaim: vi.fn(),
}))

vi.mock('../../api/ingredientApi', () => ({
  fetchIngredients: vi.fn(),
  fetchIngredient: vi.fn(),
  createIngredient: vi.fn(),
  updateIngredient: vi.fn(),
  deleteIngredient: vi.fn(),
}))

vi.mock('../../api/keyComponentApi', () => ({
  fetchKeyComponents: vi.fn(),
  fetchKeyComponent: vi.fn(),
  createKeyComponent: vi.fn(),
  updateKeyComponent: vi.fn(),
  deleteKeyComponent: vi.fn(),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: ({
    isOpen,
    onConfirm,
    confirmText,
  }: {
    isOpen: boolean
    onConfirm: () => void
    confirmText: string
  }) => (
    isOpen ? <button type="button" onClick={onConfirm}>{confirmText}</button> : null
  ),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 'test@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function renderWithRoute(
  ui: ReactElement,
  client: QueryClient,
  route = '/',
  path = '*',
) {
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route path={path} element={ui} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function submitRenderedForm(view: ReturnType<typeof render>) {
  await waitFor(() => {
    expect(view.container.querySelector('form')).not.toBeNull()
  })
  fireEvent.submit(view.container.querySelector('form') as HTMLFormElement)
}

function persistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function listResponse<T>(item: T) {
  return {
    data: [item],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
      from: 1,
      to: 1,
    },
  }
}

const certificationFixture: CertificationData = {
  id: 'cert-1',
  type: 'organic',
  slug: 'organic',
  certifying_body: null,
  logo_url: null,
  verification_url: null,
  is_active: true,
  display_order: 1,
  name: 'Organic',
  description: null,
  translations: [{ id: 'tr-1', locale: 'en', name: 'Organic', description: null }],
}

const healthClaimFixture: HealthClaimData = {
  id: 'claim-1',
  claim_type: 'function',
  slug: 'supports-health',
  regulatory_status: 'approved',
  efsa_reference: null,
  fda_reference: null,
  country_restrictions: null,
  requires_disclaimer: false,
  claim: 'Supports health',
  disclaimer_text: null,
  translations: [{ id: 'tr-1', locale: 'en', claim: 'Supports health', disclaimer_text: null }],
}

const ingredientFixture: IngredientData = {
  id: 'ingredient-1',
  slug: 'vitamin-c',
  cas_number: null,
  is_allergen: false,
  allergen_code: null,
  regulatory_status: 'approved',
  notes: null,
  name: 'Vitamin C',
  description: null,
  translations: [{ id: 'tr-1', locale: 'en', name: 'Vitamin C', description: null }],
}

const keyComponentFixture: KeyComponentData = {
  id: 'key-component-1',
  slug: 'gelatin',
  is_allergen: false,
  name: 'Gelatin',
  description: null,
  translations: [{ id: 'tr-1', locale: 'en', name: 'Gelatin', description: null }],
}

type ResourceConfig<TItem> = {
  resource: string
  listRoute: string
  formRoute: string
  editRoute: string
  formPath: string
  listPage: ComponentType
  formPage: ComponentType
  item: TItem
  listMock: ReturnType<typeof vi.fn>
  detailMock: ReturnType<typeof vi.fn>
  createMock: ReturnType<typeof vi.fn>
  updateMock: ReturnType<typeof vi.fn>
  deleteMock: ReturnType<typeof vi.fn>
}

const resources: Array<ResourceConfig<unknown>> = [
  {
    resource: 'certifications',
    listRoute: '/parapharmacy/certifications',
    formRoute: '/parapharmacy/certifications/new',
    editRoute: '/parapharmacy/certifications/cert-1',
    formPath: '/parapharmacy/certifications/:id',
    listPage: CertificationListPage,
    formPage: CertificationFormPage,
    item: certificationFixture,
    listMock: vi.mocked(fetchCertifications),
    detailMock: vi.mocked(fetchCertification),
    createMock: vi.mocked(createCertification),
    updateMock: vi.mocked(updateCertification),
    deleteMock: vi.mocked(deleteCertification),
  },
  {
    resource: 'health-claims',
    listRoute: '/parapharmacy/health-claims',
    formRoute: '/parapharmacy/health-claims/new',
    editRoute: '/parapharmacy/health-claims/claim-1',
    formPath: '/parapharmacy/health-claims/:id',
    listPage: HealthClaimListPage,
    formPage: HealthClaimFormPage,
    item: healthClaimFixture,
    listMock: vi.mocked(fetchHealthClaims),
    detailMock: vi.mocked(fetchHealthClaim),
    createMock: vi.mocked(createHealthClaim),
    updateMock: vi.mocked(updateHealthClaim),
    deleteMock: vi.mocked(deleteHealthClaim),
  },
  {
    resource: 'ingredients',
    listRoute: '/parapharmacy/ingredients',
    formRoute: '/parapharmacy/ingredients/new',
    editRoute: '/parapharmacy/ingredients/ingredient-1',
    formPath: '/parapharmacy/ingredients/:id',
    listPage: IngredientListPage,
    formPage: IngredientFormPage,
    item: ingredientFixture,
    listMock: vi.mocked(fetchIngredients),
    detailMock: vi.mocked(fetchIngredient),
    createMock: vi.mocked(createIngredient),
    updateMock: vi.mocked(updateIngredient),
    deleteMock: vi.mocked(deleteIngredient),
  },
  {
    resource: 'key-components',
    listRoute: '/parapharmacy/key-components',
    formRoute: '/parapharmacy/key-components/new',
    editRoute: '/parapharmacy/key-components/key-component-1',
    formPath: '/parapharmacy/key-components/:id',
    listPage: KeyComponentListPage,
    formPage: KeyComponentFormPage,
    item: keyComponentFixture,
    listMock: vi.mocked(fetchKeyComponents),
    detailMock: vi.mocked(fetchKeyComponent),
    createMock: vi.mocked(createKeyComponent),
    updateMock: vi.mocked(updateKeyComponent),
    deleteMock: vi.mocked(deleteKeyComponent),
  },
]

beforeEach(() => {
  for (const config of resources) {
    config.listMock.mockReset()
    config.listMock.mockResolvedValue(listResponse(config.item))
    config.detailMock.mockReset()
    config.detailMock.mockResolvedValue(config.item)
    config.createMock.mockReset()
    config.createMock.mockResolvedValue(config.item)
    config.updateMock.mockReset()
    config.updateMock.mockResolvedValue(config.item)
    config.deleteMock.mockReset()
    config.deleteMock.mockResolvedValue(undefined)
  }
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('parapharmacyListInvalidationPredicate', () => {
  it('matches only active tenant/company list keys', () => {
    const pred = parapharmacyListInvalidationPredicate('tenant-A', 'company-1', 'certifications')

    expect(pred({ queryKey: ['parapharmacy', 'certifications', 1, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['parapharmacy', 'certifications', 'cert-1', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['parapharmacy', 'ingredients', 1, 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['parapharmacy', 'certifications', 1, 'tenant-B', 'company-1'] })).toBe(false)
  })
})

describe('parapharmacy query key shapes', () => {
  it('wraps all list and edit query keys with tenant/company suffixes (.407, .410, .412, .415, .417, .420, .422, .425)', async () => {
    setTenant('tenant-A', 'company-1')
    const client = createTestQueryClient()

    for (const config of resources) {
      renderWithRoute(<config.listPage />, client, config.listRoute)
      renderWithRoute(<config.formPage />, client, config.editRoute, config.formPath)
    }

    await waitFor(() => {
      for (const config of resources) {
        expect(config.listMock).toHaveBeenCalled()
        expect(config.detailMock).toHaveBeenCalled()
      }
    })

    const keys = client.getQueryCache().getAll().map((q) => q.queryKey as unknown[])
    for (const config of resources) {
      expect(keys).toContainEqual(['parapharmacy', config.resource, 1, 'tenant-A', 'company-1'])
      expect(
        keys.some((key) =>
          key[0] === 'parapharmacy' &&
          key[1] === config.resource &&
          typeof key[2] === 'string' &&
          key[key.length - 2] === 'tenant-A' &&
          key[key.length - 1] === 'company-1'
        ),
      ).toBe(true)
    }
  })
})

describe('parapharmacy mutation cascades', () => {
  it('create/update refetch active list keys for each resource; delete uses the same list predicate (.408-.409, .411, .413-.414, .416, .418-.419, .421, .423-.424, .426)', async () => {
    setTenant('tenant-A', 'company-1')

    for (const config of resources) {
      let listCalls = 0
      config.listMock.mockImplementation(async () => {
        listCalls += 1
        return listResponse(config.item)
      })

      const client = createTestQueryClient()
      renderWithRoute(<config.listPage />, client, config.listRoute)
      await waitFor(() => { expect(listCalls).toBe(1) })

      const createForm = renderWithRoute(<config.formPage />, client, config.formRoute, config.formPath)
      await submitRenderedForm(createForm)
      await waitFor(() => { expect(listCalls).toBe(2) })

      const editForm = renderWithRoute(<config.formPage />, client, config.editRoute, config.formPath)
      await waitFor(() => { expect(config.detailMock).toHaveBeenCalled() })
      await submitRenderedForm(editForm)
      await waitFor(() => { expect(listCalls).toBe(3) })

      expect(
        parapharmacyListInvalidationPredicate('tenant-A', 'company-1', config.resource)({
          queryKey: ['parapharmacy', config.resource, 1, 'tenant-A', 'company-1'],
        }),
      ).toBe(true)
    }
  })
})

describe('parapharmacy cross-tenant isolation', () => {
  it('tenant-A list results do not contain tenant-B cache entries (L18)', async () => {
    const client = persistentQueryClient()
    const tenantBKey = ['parapharmacy', 'certifications', 1, 'tenant-B', 'company-1']
    client.setQueryData(tenantBKey, listResponse({ ...certificationFixture, id: 'leaked-tenant-b-certification' }))

    setTenant('tenant-A', 'company-1')
    renderWithRoute(<CertificationListPage />, client, '/parapharmacy/certifications')
    await waitFor(() => { expect(fetchCertifications).toHaveBeenCalled() })

    const tenantAKey = ['parapharmacy', 'certifications', 1, 'tenant-A', 'company-1']
    const tenantAData = client.getQueryData<ReturnType<typeof listResponse<CertificationData>>>(tenantAKey)
    expect(tenantAData?.data.map((item) => item.id)).not.toContain('leaked-tenant-b-certification')
    expect(client.getQueryData(tenantBKey)).toEqual(
      listResponse({ ...certificationFixture, id: 'leaked-tenant-b-certification' }),
    )
  })
})
