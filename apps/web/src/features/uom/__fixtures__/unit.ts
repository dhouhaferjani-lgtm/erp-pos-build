/** Fixture factories matching the generated UoM API DTOs. */

import type { Unit, UnitCategory } from '../api/uomApi'

export function makeUnit(overrides: Partial<Unit> = {}): Unit {
  return {
    id: 'unit-1',
    categoryId: 'cat-1',
    code: 'g',
    name: 'Gram',
    symbol: 'g',
    conversionFactor: '1',
    decimalPlaces: 2,
    roundingMethod: 'half_up',
    isBaseUnit: true,
    isActive: true,
    isSystem: true,
    category: null,
    ...overrides,
  }
}

export function makeUnitCategory(
  overrides: Partial<UnitCategory> = {},
): UnitCategory {
  return {
    id: 'cat-weight-1',
    code: 'weight',
    name: 'Weight',
    description: 'Units for measuring weight',
    base_unit_id: 'unit-gram-1',
    is_active: true,
    units: [],
    ...overrides,
  }
}
