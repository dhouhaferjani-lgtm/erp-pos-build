/**
 * Fixture factories for UnitsSettingsPage tests.
 *
 * The `Unit` interface in `../api/uomApi.ts` carries BOTH snake_case (from
 * the backend DTO) AND camelCase (used by the component in render) fields.
 * Component code reads `unit.isBaseUnit` / `unit.isSystem` for badges, so
 * both pairs must be populated in fixtures or the rendered output is
 * missing the "Base Unit" / "System" labels.
 */

import type { Unit, UnitCategory } from '../api/uomApi'

export function makeUnit(overrides: Partial<Unit> = {}): Unit {
  const merged = {
    id: 'unit-1',
    category_id: 'cat-1',
    code: 'g',
    name: 'Gram',
    symbol: 'g',
    conversion_factor: '1',
    decimal_places: 2,
    rounding_method: 'half_up' as const,
    is_base_unit: true,
    is_active: true,
    is_system: true,
    ...overrides,
  }
  // Populate camelCase mirrors from the canonical snake_case fields so
  // component code that reads either form works without the caller having
  // to pass both.
  return {
    ...merged,
    categoryId: merged.category_id,
    conversionFactor: merged.conversion_factor,
    decimalPlaces: merged.decimal_places,
    roundingMethod: merged.rounding_method,
    isBaseUnit: merged.is_base_unit,
    isSystem: merged.is_system,
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
