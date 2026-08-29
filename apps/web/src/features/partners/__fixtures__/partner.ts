/**
 * Fixture factories for Partner Management tests.
 *
 * Partner API fixtures are generated-type-first: tests may override the fields
 * relevant to a scenario, while the factory keeps every DTO field present.
 */

import type { OffsetPaginationMeta } from '@/types/pagination'

/**
 * Row shape returned by `/partners` list endpoint.
 *
 * Mirrors the `Partner` interface declared in `PartnerListPage.tsx`.
 */
export type PartnerData = App.Modules.Partner.Application.DTOs.PartnerData
export type PartnerListRow = PartnerData

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
  meta: OffsetPaginationMeta
  aggregates?: PartnersListAggregates
}

/**
 * Row shape rendered by `PartnerDetailPage`. The detail page fetches via
 * `api.get<{ data: Partner }>(`/partners/${id}`)` and reads `response.data.data`.
 */
export type PartnerDetail = PartnerData

export function makePartnerListRow(
  overrides: Partial<PartnerListRow> = {},
): PartnerListRow {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    name: 'Acme Corp',
    type: 'customer',
    customer_category: null,
    company_legal_name: null,
    business_registration_number: null,
    payment_terms: null,
    payment_terms_days: null,
    credit_limit: null,
    discount_percentage: null,
    invoice_consolidation: false,
    consolidation_frequency: null,
    code: null,
    email: 'contact@acme.com',
    phone: '+1234567890',
    country_code: null,
    vat_number: null,
    tax_status: 'REGISTERED',
    exemption_reason: null,
    exemption_valid_until: null,
    notes: null,
    is_active: true,
    receivable_balance: null,
    credit_balance: null,
    payable_balance: null,
    net_balance: '0.000',
    street_address: null,
    street_address_2: null,
    city: null,
    state: null,
    postal_code: null,
    country: null,
    account_status: 'active',
    account_status_version: 1,
    account_status_changed_at: null,
    account_status_changed_by: null,
    account_status_reason: null,
    contacts_count: 0,
    primary_contact_name: null,
    bank_accounts: [],
    created_at: '2025-01-01T00:00:00Z',
    updated_at: null,
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
  return makePartnerListRow({
    updated_at: '2025-01-02T00:00:00Z',
    ...overrides,
  })
}

export const makePartnerData = makePartnerListRow
