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
 * The UI works in PascalCase identifiers ('HalfUp' | 'Floor' | 'Ceil') for
 * display, but the backend RoundingMethod enum is snake_case-backed
 * ('half_up' | 'floor' | 'ceil') — the same wire format used by the legacy
 * unit create/update flow. The mapping happens at the wire boundary below.
 */
export type RoundingMethod = 'HalfUp' | 'Floor' | 'Ceil'

const ROUNDING_METHOD_WIRE: Record<RoundingMethod, Unit['rounding_method']> = {
  HalfUp: 'half_up',
  Floor: 'floor',
  Ceil: 'ceil',
}

export interface UnitPrecisionPayload {
  decimal_places: number
  rounding_method: RoundingMethod
}

/**
 * Update only the precision fields (decimal_places + rounding_method) of a unit.
 * Distinct from updateUnit which updates all mutable fields. Maps the PascalCase
 * UI value to the snake_case enum the backend validates against.
 */
export async function updateUnitPrecision(
  id: string,
  payload: UnitPrecisionPayload
): Promise<Unit> {
  return apiPut<Unit>(`/uom/units/${id}`, {
    decimal_places: payload.decimal_places,
    rounding_method: ROUNDING_METHOD_WIRE[payload.rounding_method],
  })
}
