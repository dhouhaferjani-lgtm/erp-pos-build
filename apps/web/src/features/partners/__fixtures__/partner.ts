/**
 * Fixture factories for Partner Management tests.
 *
 * There are three slightly different `Partner` interface shapes declared
 * across the feature (one per page file): the list page uses balance fields,
 * the detail page uses legacy `address` + `updated_at`, and the form uses
 * the full B2B profile. This module exposes structural types that match the
 * wire payload each page expects, plus factories that produce valid fixture
 * data. Tests mock `api.get` / `apiGet` against these shapes.
 */

/**
 * Row shape returned by `/partners` list endpoint.
 *
 * Mirrors the `Partner` interface declared in `PartnerListPage.tsx`.
 */
export interface PartnerListRow {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email: string | null
  phone: string | null
  tax_id?: string | null
  is_active?: boolean
  receivable_balance?: string | null
  credit_balance?: string | null
  payable_balance?: string | null
  created_at: string
}

export interface PartnersListMeta {
  total: number
  current_page: number
  per_page: number
  last_page: number
  from: number | null
  to: number | null
}

export interface PartnersListAggregates {
  total_partners: number
  total_active: number
  total_receivable: string
  total_payable: string
}

/**
 * Wire shape returned by `api.get<PartnersResponse>('/partners?...')`.
 */
export interface PartnersListResponse {
  data: PartnerListRow[]
  meta: PartnersListMeta
  aggregates?: PartnersListAggregates
}

/**
 * Row shape rendered by `PartnerDetailPage`. The detail page fetches via
 * `api.get<{ data: Partner }>(`/partners/${id}`)` and reads `response.data.data`.
 */
export interface PartnerDetail {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email: string | null
  phone: string | null
  address?: string | null
  city?: string | null
  postal_code?: string | null
  country?: string | null
  tax_id?: string | null
  notes?: string | null
  is_active?: boolean
  created_at: string
  updated_at?: string
}

export function makePartnerListRow(
  overrides: Partial<PartnerListRow> = {},
): PartnerListRow {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    name: 'Acme Corp',
    type: 'customer',
    email: 'contact@acme.com',
    phone: '+1234567890',
    tax_id: null,
    is_active: true,
    receivable_balance: null,
    credit_balance: null,
    payable_balance: null,
    created_at: '2025-01-01T00:00:00Z',
    ...overrides,
  }
}

export function makePartnersListResponse(
  overrides: Partial<PartnersListResponse> = {},
): PartnersListResponse {
  const data = overrides.data ?? [makePartnerListRow()]
  const base: PartnersListResponse = {
    data,
    meta: overrides.meta ?? {
      total: data.length,
      current_page: 1,
      per_page: 25,
      last_page: 1,
      from: data.length > 0 ? 1 : null,
      to: data.length > 0 ? data.length : null,
    },
  }
  // Only attach `aggregates` when the caller provided one — the field is
  // optional in the wire shape and `exactOptionalPropertyTypes: true`
  // rejects `aggregates: undefined`.
  return overrides.aggregates
    ? { ...base, aggregates: overrides.aggregates }
    : base
}

export function makePartnerDetail(
  overrides: Partial<PartnerDetail> = {},
): PartnerDetail {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    name: 'Acme Corp',
    type: 'customer',
    email: 'contact@acme.com',
    phone: '+1234567890',
    address: null,
    city: null,
    postal_code: null,
    country: null,
    tax_id: null,
    notes: null,
    is_active: true,
    created_at: '2025-01-01T00:00:00Z',
    updated_at: '2025-01-02T00:00:00Z',
    ...overrides,
  }
}
