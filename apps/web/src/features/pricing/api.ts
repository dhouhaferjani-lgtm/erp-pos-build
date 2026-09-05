import { apiGet, apiPost, apiPatch, apiDelete } from '../../lib/api'
import type {
  PriceList,
  PriceListDetail,
  PriceListFormData,
  PriceListItemFormData,
  AssignPartnerFormData,
  PriceListItem,
} from './types'

/**
 * Fetch a page of price lists.
 *
 * `PricingController::index()` returns Laravel's RAW paginator
 * (`{ data: [...], current_page, total, ... }`; PricingController.php:68), and
 * `apiGet` already unwraps `response.data.data` (src/lib/api.ts:407-410,
 * docs/conventions/01) — so this resolves the PAGE ARRAY, not a
 * `{ data, meta }` wrapper. Typing it as a wrapper (the pre-fix shape) made
 * `PriceListListPage`'s `data?.data` permanently `undefined`.
 *
 * The paginator's own meta is dropped by that unwrap; the list page renders a
 * single page today and does not read it. Restoring meta needs `api.get` +
 * `response.data` (the standing double-unwrap pitfall) — filed as a residual in
 * the fix-round-1 handback, not done here.
 */
export async function fetchPriceLists(params?: {
  is_active?: boolean
  currency?: string
  search?: string
}): Promise<PriceList[]> {
  const searchParams = new URLSearchParams()
  if (params?.is_active !== undefined) {
    searchParams.append('is_active', String(params.is_active))
  }
  if (params?.currency) {
    searchParams.append('currency', params.currency)
  }
  if (params?.search) {
    searchParams.append('search', params.search)
  }
  const queryString = searchParams.toString()
  return apiGet<PriceList[]>(`/price-lists${queryString ? `?${queryString}` : ''}`)
}

/**
 * Fetch a single price list with items and partners.
 *
 * `PricingController::show()` emits `{ data: $priceList }`
 * (PricingController.php:88) and `apiGet` unwraps `response.data.data`, so this
 * resolves the `PriceListDetail` ITSELF — not a `{ data: PriceListDetail }`
 * wrapper. This is the read-path twin of the DEV-QA-047 write-path bug: while
 * it was typed as a wrapper, `PriceListForm`'s `existingPriceList?.data` and
 * `PriceListDetailPage`'s `data?.data` were always `undefined`, so the edit form
 * rendered empty and the detail page had no data (gate r1 F-3).
 */
export async function fetchPriceList(id: string): Promise<PriceListDetail> {
  return apiGet<PriceListDetail>(`/price-lists/${id}`)
}

/**
 * Create a new price list
 */
export async function createPriceList(data: PriceListFormData): Promise<PriceList> {
  // apiPost already unwraps the `{ data: ... }` envelope, so this resolves the
  // PriceList directly (id at the top level) — NOT a `{ data: PriceList }` wrapper.
  return apiPost<PriceList>('/price-lists', {
    code: data.code,
    name: data.name,
    description: data.description ?? null,
    currency: data.currency,
    is_active: data.is_active,
    is_default: data.is_default,
    valid_from: data.valid_from ?? null,
    valid_until: data.valid_until ?? null,
  })
}

/**
 * Update an existing price list
 */
export async function updatePriceList(
  id: string,
  data: Partial<PriceListFormData>
): Promise<PriceList> {
  const payload: Partial<{
    code: string
    name: string
    description: string | null
    currency: string
    is_active: boolean
    is_default: boolean
    valid_from: string | null
    valid_until: string | null
  }> = {}

  if (data.code !== undefined) payload.code = data.code
  if (data.name !== undefined) payload.name = data.name
  if (data.description !== undefined) payload.description = data.description ?? null
  if (data.currency !== undefined) payload.currency = data.currency
  if (data.is_active !== undefined) payload.is_active = data.is_active
  if (data.is_default !== undefined) payload.is_default = data.is_default
  if (data.valid_from !== undefined) payload.valid_from = data.valid_from ?? null
  if (data.valid_until !== undefined) payload.valid_until = data.valid_until ?? null

  return apiPatch<PriceList>(`/price-lists/${id}`, payload)
}

/**
 * Delete a price list
 */
export async function deletePriceList(id: string): Promise<void> {
  return apiDelete(`/price-lists/${id}`)
}

/**
 * Add an item to a price list
 */
export async function addPriceListItem(
  priceListId: string,
  data: PriceListItemFormData
): Promise<{ data: PriceListItem }> {
  return apiPost<{ data: PriceListItem }>(`/price-lists/${priceListId}/items`, {
    product_id: data.product_id,
    price: data.price,
    min_quantity: data.min_quantity,
    max_quantity: data.max_quantity ?? null,
  })
}

/**
 * Update a price list item
 */
export async function updatePriceListItem(
  priceListId: string,
  itemId: string,
  data: Partial<PriceListItemFormData>
): Promise<{ data: PriceListItem }> {
  const payload: Partial<{
    price: string
    min_quantity: number
    max_quantity: number | null
  }> = {}

  if (data.price !== undefined) payload.price = data.price
  if (data.min_quantity !== undefined) payload.min_quantity = data.min_quantity
  if (data.max_quantity !== undefined) payload.max_quantity = data.max_quantity ?? null

  return apiPatch<{ data: PriceListItem }>(`/price-lists/${priceListId}/items/${itemId}`, payload)
}

/**
 * Remove an item from a price list
 */
export async function removePriceListItem(priceListId: string, itemId: string): Promise<void> {
  return apiDelete(`/price-lists/${priceListId}/items/${itemId}`)
}

/**
 * Assign a price list to a partner
 */
export async function assignPriceListToPartner(
  priceListId: string,
  data: AssignPartnerFormData
): Promise<void> {
  return apiPost(`/price-lists/${priceListId}/partners`, {
    partner_id: data.partner_id,
    valid_from: data.valid_from ?? null,
    valid_until: data.valid_until ?? null,
    priority: data.priority ?? 0,
  })
}

/**
 * Remove a price list assignment from a partner
 */
export async function removePriceListFromPartner(
  priceListId: string,
  partnerId: string
): Promise<void> {
  return apiDelete(`/price-lists/${priceListId}/partners/${partnerId}`)
}
