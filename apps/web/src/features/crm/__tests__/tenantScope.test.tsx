import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { waitFor } from '@testing-library/react'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'

import { contactKeys, contactsInvalidationPredicate } from '../api/contactApi'
import { ContactDetailPage } from '../pages/ContactDetailPage'
import { ContactListPage } from '../pages/ContactListPage'

// ─── api mock — module-level boundary ───────────────────────────────────────

const mockFetchContacts = vi.hoisted(() => vi.fn())
const mockFetchContact = vi.hoisted(() => vi.fn())
const mockCreateContact = vi.hoisted(() => vi.fn())
const mockUpdateContact = vi.hoisted(() => vi.fn())
const mockDeleteContact = vi.hoisted(() => vi.fn())
const mockLinkContactToParty = vi.hoisted(() => vi.fn())
const mockUnlinkContactFromParty = vi.hoisted(() => vi.fn())

vi.mock('../api/contactApi', async () => {
  const actual = await vi.importActual<typeof import('../api/contactApi')>('../api/contactApi')
  return {
    ...actual,
    fetchContacts: mockFetchContacts,
    fetchContact: mockFetchContact,
    createContact: mockCreateContact,
    updateContact: mockUpdateContact,
    deleteContact: mockDeleteContact,
    linkContactToParty: mockLinkContactToParty,
    unlinkContactFromParty: mockUnlinkContactFromParty,
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'c-123' }),
    useNavigate: () => vi.fn(),
    Link: ({ children }: { children: React.ReactNode }) => children,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// PartnerSelect transitively imports useAuthStore + useCompanyStore via lib path
// — keep it real so the production wrap is exercised.

// ─── Helpers ──────────────────────────────────────────────────────────────────

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test',
      email: 't@t',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function crmKeysFromCache(client: ReturnType<typeof createTestQueryClient>): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
    .filter((k) => Array.isArray(k) && (k[0] === 'contacts' || k[0] === 'partners'))
}

beforeEach(() => {
  mockFetchContacts.mockReset()
  mockFetchContacts.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } })
  mockFetchContact.mockReset()
  mockFetchContact.mockResolvedValue({
    id: 'c-123',
    first_name: 'Test',
    last_name: '',
    full_name: 'Test',
    phone: null,
    mobile: null,
    email: null,
    date_of_birth: null,
    gender: null,
    national_id: null,
    notes: null,
    is_active: true,
    parties: [],
  })
  mockCreateContact.mockReset()
  mockCreateContact.mockResolvedValue({ id: 'c-new' })
  mockUpdateContact.mockReset()
  mockUpdateContact.mockResolvedValue({ id: 'c-123' })
  mockDeleteContact.mockReset()
  mockDeleteContact.mockResolvedValue(undefined)
  mockLinkContactToParty.mockReset()
  mockLinkContactToParty.mockResolvedValue(undefined)
  mockUnlinkContactFromParty.mockReset()
  mockUnlinkContactFromParty.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

// ─── contactKeys factory contract ────────────────────────────────────────────

describe('contactKeys factory contract (wrap-at-callsite invariant)', () => {
  it('returns un-scoped structural prefixes', () => {
    expect(contactKeys.all).toEqual(['contacts'])
    expect(contactKeys.lists()).toEqual(['contacts', 'list'])
    expect(contactKeys.details()).toEqual(['contacts', 'detail'])
    expect(contactKeys.detail('c-1')).toEqual(['contacts', 'detail', 'c-1'])
    expect(contactKeys.list({ search: 'x' })).toEqual(['contacts', 'list', { search: 'x' }])
  })
})

// ─── Predicate unit tests ────────────────────────────────────────────────────

describe('contactsInvalidationPredicate', () => {
  it('matches list + detail leaf keys for the given t/c', () => {
    const pred = contactsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['contacts', 'list', { search: '' }, 'tenant-A', 'company-1'] })).toBe(true)
    expect(pred({ queryKey: ['contacts', 'detail', 'c-1', 'tenant-A', 'company-1'] })).toBe(true)
  })

  it('rejects non-contacts namespaces and wrong tenant/company', () => {
    const pred = contactsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['partners', 'search', '', 'tenant-A', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['contacts', 'list', { search: '' }, 'tenant-B', 'company-1'] })).toBe(false)
    expect(pred({ queryKey: ['contacts', 'detail', 'c-1', 'tenant-A', 'company-2'] })).toBe(false)
  })

  it('rejects degenerate keys with fewer than 3 elements', () => {
    const pred = contactsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['contacts'] })).toBe(false)
    expect(pred({ queryKey: ['contacts', 'tenant-A'] })).toBe(false)
  })
})

// ─── useQuery shape probes (callsites .128, .135) ────────────────────────────

describe('crm page queryKey shapes', () => {
  it('ContactDetailPage useQuery carries tenant + company at the suffix (.128)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ContactDetailPage />, { queryClient })
    await waitFor(() => {
      expect(mockFetchContact).toHaveBeenCalled()
    })
    const keys = crmKeysFromCache(queryClient)
    const detail = keys.find((k) => k[0] === 'contacts' && k[1] === 'detail')
    expect(detail).toEqual(['contacts', 'detail', 'c-123', 'tenant-A', 'company-1'])
  })

  it('ContactListPage useQuery carries tenant + company at the suffix (.135)', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()
    renderWithProviders(<ContactListPage />, { queryClient })
    await waitFor(() => {
      expect(mockFetchContacts).toHaveBeenCalled()
    })
    const keys = crmKeysFromCache(queryClient)
    const list = keys.find((k) => k[0] === 'contacts' && k[1] === 'list')
    // List uses contactKeys.list({...filters, search, page}) = [contacts, list, {filters}, t, c]
    expect(list?.[0]).toBe('contacts')
    expect(list?.[1]).toBe('list')
    expect(list?.[list.length - 2]).toBe('tenant-A')
    expect(list?.[list.length - 1]).toBe('company-1')
  })

  it('queryKeys differ across tenants', async () => {
    setTenant('tenant-A', 'company-1')
    const cA = createTestQueryClient()
    renderWithProviders(<ContactListPage />, { queryClient: cA })
    await waitFor(() => {
      expect(mockFetchContacts).toHaveBeenCalled()
    })
    const kA = JSON.stringify(crmKeysFromCache(cA))

    mockFetchContacts.mockClear()
    setTenant('tenant-B', 'company-1')
    const cB = createTestQueryClient()
    renderWithProviders(<ContactListPage />, { queryClient: cB })
    await waitFor(() => {
      expect(mockFetchContacts).toHaveBeenCalled()
    })
    const kB = JSON.stringify(crmKeysFromCache(cB))

    expect(kA).toContain('tenant-A')
    expect(kB).toContain('tenant-B')
    expect(kA).not.toEqual(kB)
  })
})

// ─── Cross-tenant isolation ──────────────────────────────────────────────────

describe('cross-tenant isolation (predicate-based contacts cascade)', () => {
  it('predicate-based invalidate rejects tenant-B contacts cache entries', async () => {
    setTenant('tenant-A', 'company-1')
    const queryClient = createTestQueryClient()

    const tenantBListKey = ['contacts', 'list', { search: '' }, 'tenant-B', 'company-1']
    const tenantBDetailKey = ['contacts', 'detail', 'c-other', 'tenant-B', 'company-1']
    queryClient.setQueryData(tenantBListKey, { data: [{ id: 'c-tenant-b' }] })
    queryClient.setQueryData(tenantBDetailKey, { id: 'c-tenant-b-detail' })

    const pred = contactsInvalidationPredicate('tenant-A', 'company-1')
    await queryClient.invalidateQueries({ predicate: pred })

    const tBList = queryClient.getQueryCache().find({ queryKey: tenantBListKey, exact: true })
    expect(tBList?.state.data).toEqual({ data: [{ id: 'c-tenant-b' }] })
    expect(tBList?.state.isInvalidated).toBe(false)

    const tBDetail = queryClient.getQueryCache().find({ queryKey: tenantBDetailKey, exact: true })
    expect(tBDetail?.state.data).toEqual({ id: 'c-tenant-b-detail' })
    expect(tBDetail?.state.isInvalidated).toBe(false)
  })

  it('predicate must NOT match the partners namespace (PartnerSelect cache)', () => {
    // Sibling-namespace assertion: 'partners' (used by PartnerSelect) must not
    // be invalidated by contact mutations.
    const pred = contactsInvalidationPredicate('tenant-A', 'company-1')
    expect(pred({ queryKey: ['partners', 'search', '', 'tenant-A', 'company-1'] })).toBe(false)
  })
})
