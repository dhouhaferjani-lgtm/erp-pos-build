import { api, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { ApiResponse } from '@/lib/api'

export interface Contact {
  id: string
  first_name: string
  last_name: string | null
  full_name: string
  email: string | null
  phone: string | null
  mobile: string | null
  date_of_birth: string | null
  gender: string | null
  national_id: string | null
  notes: string | null
  is_active: boolean
  created_at: string
  updated_at: string | null
  parties?: ContactParty[]
}

export interface ContactParty {
  id: string
  name: string
  type: string
  job_title: string | null
  department: string | null
  is_primary: boolean
}

export interface ContactFilters {
  search?: string
  party_id?: string
  is_active?: boolean
  page?: number
  per_page?: number
}

export interface CreateContactData {
  first_name: string
  last_name?: string
  phone?: string
  email?: string
  mobile?: string
  date_of_birth?: string
  gender?: string
  national_id?: string
  notes?: string
  party_id?: string
  job_title?: string
  is_primary?: boolean
}

export interface LinkPartyData {
  party_id: string
  job_title?: string
  department?: string
  is_primary?: boolean
}

export interface ContactListResponse {
  data: Contact[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

/** React Query key factory for contacts */
export const contactKeys = {
  all: ['contacts'] as const,
  lists: () => [...contactKeys.all, 'list'] as const,
  list: (filters: ContactFilters) => [...contactKeys.lists(), filters] as const,
  details: () => [...contactKeys.all, 'detail'] as const,
  detail: (id: string) => [...contactKeys.details(), id] as const,
}

/**
 * Tenant-scoped predicate matching ANY [contacts, ...] queryKey for the
 * given tenant + company. tenantScopedKey() suffixes t/c on leaf keys,
 * so a fixed wrap tenantScopedKey([...contactKeys.all]) = [contacts, t, c]
 * is NOT a prefix of leaf list/detail keys [contacts, list, filters, t, c]
 * or [contacts, detail, id, t, c]. Predicate-based invalidation sidesteps
 * the positional mismatch.
 */
export function contactsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'contacts' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export async function fetchContacts(filters: ContactFilters = {}): Promise<ContactListResponse> {
  const params = new URLSearchParams()
  if (filters.search) params.set('search', filters.search)
  if (filters.party_id) params.set('party_id', filters.party_id)
  if (filters.is_active !== undefined) params.set('is_active', String(filters.is_active))
  if (filters.page) params.set('page', String(filters.page))
  if (filters.per_page) params.set('per_page', String(filters.per_page))
  const query = params.toString()
  const response = await api.get<ContactListResponse>(`/contacts${query ? `?${query}` : ''}`)
  return response.data
}

export async function fetchContact(id: string): Promise<Contact> {
  const response = await api.get<ApiResponse<Contact>>(`/contacts/${id}`)
  return response.data.data
}

export function createContact(data: CreateContactData) {
  return apiPost<Contact>('/contacts', data)
}

export function updateContact(id: string, data: Partial<CreateContactData>) {
  return apiPatch<Contact>(`/contacts/${id}`, data)
}

export function deleteContact(id: string) {
  return apiDelete(`/contacts/${id}`)
}

export function linkContactToParty(contactId: string, data: LinkPartyData) {
  return apiPost(`/contacts/${contactId}/link-party`, data)
}

export function unlinkContactFromParty(contactId: string, partyId: string) {
  return apiDelete(`/contacts/${contactId}/unlink-party/${partyId}`)
}
