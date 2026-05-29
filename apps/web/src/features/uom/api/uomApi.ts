import { apiGet, apiPost, apiPut, apiDelete } from '../../../lib/api'

export interface UnitCategory {
  id: string
  name: string
  code: string
  description: string | null
  base_unit_id: string | null
  is_active: boolean
  units?: Unit[]
}

export interface Unit {
  id: string
  category_id: string
  categoryId?: string
  code: string
  name: string
  symbol: string
  conversion_factor: string
  conversionFactor?: string
  decimal_places: number
  decimalPlaces?: number
  rounding_method: 'half_up' | 'floor' | 'ceil'
  roundingMethod?: 'half_up' | 'floor' | 'ceil'
  is_base_unit: boolean
  isBaseUnit?: boolean
  is_active: boolean
  is_system: boolean
  isSystem?: boolean
  category?: {
    id: string
    name: string
    code: string
  }
}

export interface ConversionResult {
  from_unit_id: string
  to_unit_id: string
  input_quantity: number
  converted_quantity: string
  convertedQuantity?: string
  conversion_factor: string
  conversionFactor?: string
}

/**
 * Create Unit Input
 */
export interface CreateUnitInput {
  categoryId: string
  code: string
  name: string
  symbol: string
  conversionFactor: string
  decimalPlaces?: number
  roundingMethod?: 'half_up' | 'floor' | 'ceil'
}

/**
 * Fetch all categories with their units
 */
export async function fetchCategories(): Promise<UnitCategory[]> {
  return apiGet<UnitCategory[]>('/uom/categories')
}

/**
 * Fetch all units (optionally filtered by category)
 */
export async function fetchUnits(categoryId?: string): Promise<Unit[]> {
  const params = categoryId ? `?category_id=${categoryId}` : ''
  return apiGet<Unit[]>(`/uom/units${params}`)
}

/**
 * Fetch a single unit
 */
export async function fetchUnit(id: string): Promise<Unit> {
  return apiGet<Unit>(`/uom/units/${id}`)
}

/**
 * Create a custom unit
 */
export async function createUnit(input: CreateUnitInput): Promise<Unit> {
  return apiPost<Unit>('/uom/units', {
    category_id: input.categoryId,
    code: input.code,
    name: input.name,
    symbol: input.symbol,
    conversion_factor: input.conversionFactor,
    decimal_places: input.decimalPlaces,
    rounding_method: input.roundingMethod,
  })
}

/**
 * Update a custom unit
 */
export async function updateUnit(id: string, input: Partial<CreateUnitInput>): Promise<Unit> {
  return apiPut<Unit>(`/uom/units/${id}`, {
    code: input.code,
    name: input.name,
    symbol: input.symbol,
    conversion_factor: input.conversionFactor,
    decimal_places: input.decimalPlaces,
    rounding_method: input.roundingMethod,
  })
}

/**
 * Deactivate a unit
 */
export async function deleteUnit(id: string): Promise<void> {
  return apiDelete(`/uom/units/${id}`)
}

/**
 * Convert quantity between units
 */
export async function convertUnits(
  quantity: number,
  fromUnitId: string,
  toUnitId: string
): Promise<ConversionResult> {
  return apiPost<ConversionResult>('/uom/convert', {
    quantity,
    from_unit_id: fromUnitId,
    to_unit_id: toUnitId,
  })
}

/**
 * Rounding method values used by the precision-settings endpoint.
 * Note: the backend for PUT uom/units/{id} precision payload uses PascalCase
 * identifiers ('HalfUp' | 'Floor' | 'Ceil') distinct from the legacy snake_case
 * used by the full unit create/update flow.
 */
export type RoundingMethod = 'HalfUp' | 'Floor' | 'Ceil'

export interface UnitPrecisionPayload {
  decimal_places: number
  rounding_method: RoundingMethod
}

/**
 * Update only the precision fields (decimal_places + rounding_method) of a unit.
 * Distinct from updateUnit which updates all mutable fields.
 */
export async function updateUnitPrecision(
  id: string,
  payload: UnitPrecisionPayload
): Promise<Unit> {
  return apiPut<Unit>(`/uom/units/${id}`, payload)
}
