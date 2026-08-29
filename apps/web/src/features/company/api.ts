import { apiPost } from '../../lib/api'
import type { Company } from '../../stores/companyStore'

/**
 * Input for creating a new company
 */
export interface CreateCompanyInput {
  name: string
  legalName?: string | undefined
  countryCode: string
  currency: string
  locale: string
  timezone: string
  taxId?: string | undefined
  email?: string | undefined
  phone?: string | undefined
  addressStreet?: string | undefined
  addressCity?: string | undefined
  addressPostalCode?: string | undefined
}

/**
 * API response for company creation
 */
interface CreateCompanyResponse {
  id: string
  tenant_id: string
  name: string
  legal_name: string | null
  code: string | null
  country_code: string
  tax_id: string | null
  registration_number: string | null
  vat_number: string | null
  email: string | null
  phone: string | null
  website: string | null
  currency: string
  locale: string
  timezone: string
  status: string
  address_street: string | null
  address_street_2: string | null
  address_city: string | null
  address_state: string | null
  address_postal_code: string | null
  default_tax_rate: string | null
  default_tax_configuration_id: string | null
  tax_status: string
  default_target_margin: string | null
  default_minimum_margin: string | null
  default_max_discount_percent: string | null
  discount_floor_mode: string
  price_entry_mode: string
  created_at: string
  updated_at: string
}

/**
 * API payload format (snake_case)
 */
interface CreateCompanyPayload {
  name: string
  legal_name?: string | undefined
  country_code: string
  currency: string
  locale: string
  timezone: string
  tax_id?: string | undefined
  email?: string | undefined
  phone?: string | undefined
  address_street?: string | undefined
  address_city?: string | undefined
  address_postal_code?: string | undefined
}

/**
 * Creates a new company
 *
 * This will also:
 * - Create a default location for the company
 * - Create owner membership for the current user
 * - Initialize hash chains for fiscal compliance
 */
export async function createCompany(input: CreateCompanyInput): Promise<Company> {
  const payload: CreateCompanyPayload = {
    name: input.name,
    legal_name: input.legalName,
    country_code: input.countryCode,
    currency: input.currency,
    locale: input.locale,
    timezone: input.timezone,
    tax_id: input.taxId,
    email: input.email,
    phone: input.phone,
    address_street: input.addressStreet,
    address_city: input.addressCity,
    address_postal_code: input.addressPostalCode,
  }

  const company = await apiPost<CreateCompanyResponse>('/companies', payload)

  return {
    id: company.id,
    name: company.name,
    legalName: company.legal_name ?? company.name,
    taxId: company.tax_id,
    countryCode: company.country_code,
    currency: company.currency,
    locale: company.locale,
    timezone: company.timezone,
    isPrimary: false,
  }
}
