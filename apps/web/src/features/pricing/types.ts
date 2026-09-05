/**
 * Price List types for the pricing module
 */

export interface PriceList {
  id: string
  code: string
  name: string
  description: string | null
  currency: string
  is_active: boolean
  is_default: boolean
  valid_from: string | null
  valid_until: string | null
  items_count?: number
  created_at: string
  updated_at?: string
}

export interface PriceListItem {
  id: string
  product_id: string
  product_name: string
  product_sku: string
  price: string
  min_quantity: number
  max_quantity: number | null
}

export interface PriceListPartner {
  id: string
  name: string
  valid_from?: string | null
  valid_until?: string | null
  priority?: number
}

export interface PriceListDetail extends PriceList {
  items: PriceListItem[]
  partners: PriceListPartner[]
}

export interface PriceListFormData {
  code: string
  name: string
  description?: string
  currency: string
  is_active: boolean
  is_default: boolean
  valid_from?: string | null
  valid_until?: string | null
}

export interface PriceListItemFormData {
  product_id: string
  price: string
  min_quantity: number
  max_quantity?: number | null
}

export interface AssignPartnerFormData {
  partner_id: string
  valid_from?: string | null
  valid_until?: string | null
  priority?: number
}

/**
 * NOTE (gate r1 F-3): there are deliberately NO `PriceListResponse` /
 * `PriceListsResponse` envelope types here. `apiGet` already unwraps
 * `response.data.data` (src/lib/api.ts:407-410, docs/conventions/01), so the
 * pricing fetchers resolve the payload itself — `PriceListDetail` for the show
 * endpoint and `PriceList[]` for the index page. Declaring a `{ data: ... }`
 * wrapper is what made every pricing read surface render empty.
 */
