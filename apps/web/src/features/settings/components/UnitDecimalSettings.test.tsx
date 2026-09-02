import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { UnitDecimalSettings } from './UnitDecimalSettings'
import type { Unit } from '../../uom/api/uomApi'

// ─── Hoisted mocks (must be before vi.mock calls that reference them) ─────────
const { mockUpdateUnitPrecision, mockInvalidateQueries } = vi.hoisted(() => ({
  mockUpdateUnitPrecision: vi.fn(),
  mockInvalidateQueries: vi.fn(),
}))

// ─── i18n mock ────────────────────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const map: Record<string, string> = {
        'uom:precisionSettings.title': 'Unit Precision Settings',
        'uom:precisionSettings.description': 'Configure decimal places and rounding method.',
        'uom:precisionSettings.columns.code': 'Code',
        'uom:precisionSettings.columns.name': 'Name',
        'uom:precisionSettings.columns.decimalPlaces': 'Decimal Places',
        'uom:precisionSettings.columns.roundingMethod': 'Rounding Method',
        'uom:precisionSettings.roundingOptions.HalfUp': 'Half Up',
        'uom:precisionSettings.roundingOptions.Floor': 'Floor',
        'uom:precisionSettings.roundingOptions.Ceil': 'Ceiling',
        'uom:precisionSettings.save': 'Save',
        'uom:precisionSettings.saving': 'Saving...',
        'uom:precisionSettings.saved': 'Precision settings saved.',
        'uom:precisionSettings.saveError': 'Failed to save precision settings.',
        'uom:precisionSettings.narrowingWarning':
          'Changing this may render existing quantity values inexact. Review the impact report after save.',
        'uom:noUnits': 'No units found',
      }
      return map[key] ?? key
    },
  }),
}))

// ─── sonner mock ──────────────────────────────────────────────────────────────
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// ─── Stores mocks ─────────────────────────────────────────────────────────────
vi.mock('../../../stores/authStore', () => ({
  useAuthStore: (sel: (s: { user: { tenant_id: string } | null }) => unknown) =>
    sel({ user: { tenant_id: 'tenant-1' } }),
}))

vi.mock('../../../stores/companyStore', () => ({
  useCompanyStore: (sel: (s: { currentCompanyId: string | null }) => unknown) =>
    sel({ currentCompanyId: 'company-1' }),
}))

// ─── UOM API mock ─────────────────────────────────────────────────────────────
vi.mock('../../uom/api/uomApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../uom/api/uomApi')>()
  return {
    ...actual,
    updateUnitPrecision: mockUpdateUnitPrecision,
  }
})

// ─── TanStack Query mock ──────────────────────────────────────────────────────
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: mockUnits, isLoading: false }),
    useMutation: ({
      mutationFn,
      onSuccess,
    }: {
      mutationFn: (...args: unknown[]) => unknown
      onSuccess?: () => unknown
    }) => ({
      mutateAsync: async (...args: unknown[]) => {
        // Call the real mutationFn so the API call is exercised
        const result = await mutationFn(...args)
        // Also fire onSuccess so the component's invalidation logic runs
        if (onSuccess) await onSuccess()
        return result
      },
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: mockInvalidateQueries }),
  }
})

// ─── useUnits mock ────────────────────────────────────────────────────────────
// The component imports useUnits from the UOM hook. We mock the hook module so
// the query result is controlled here without needing QueryClientProvider.
vi.mock('../../uom/hooks/useUnits', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../uom/hooks/useUnits')>()
  return {
    ...actual,
    useUnits: () => ({ data: mockUnits, isLoading: false }),
    uomUnitsInvalidationPredicate: actual.uomUnitsInvalidationPredicate,
    uomCategoriesInvalidationPredicate: actual.uomCategoriesInvalidationPredicate,
  }
})

// ─── Mock data ────────────────────────────────────────────────────────────────

const mockUnits: Unit[] = [
  {
    id: 'unit-kg-1',
    categoryId: 'cat-weight-1',
    code: 'kg',
    name: 'Kilogram',
    symbol: 'kg',
    conversionFactor: '1000',
    decimalPlaces: 2,
    roundingMethod: 'half_up',
    isBaseUnit: false,
    isActive: true,
    isSystem: true,
    category: null,
  },
  {
    id: 'unit-liter-1',
    categoryId: 'cat-volume-1',
    code: 'l',
    name: 'Liter',
    symbol: 'L',
    conversionFactor: '1',
    decimalPlaces: 3,
    roundingMethod: 'floor',
    isBaseUnit: true,
    isActive: true,
    isSystem: true,
    category: null,
  },
]

// ─── Helpers ──────────────────────────────────────────────────────────────────

function renderComponent() {
  return render(<UnitDecimalSettings />)
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('UnitDecimalSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUpdateUnitPrecision.mockResolvedValue({ ...mockUnits[0], decimalPlaces: 4 })
  })

  it('renders the table with unit rows', () => {
    renderComponent()
    expect(screen.getByTestId('unit-precision-table')).toBeInTheDocument()
    expect(screen.getByTestId('unit-row-unit-kg-1')).toBeInTheDocument()
    expect(screen.getByTestId('unit-row-unit-liter-1')).toBeInTheDocument()
  })

  it('renders code and name columns for each unit', () => {
    renderComponent()
    expect(screen.getByText('kg')).toBeInTheDocument()
    expect(screen.getByText('Kilogram')).toBeInTheDocument()
    expect(screen.getByText('l')).toBeInTheDocument()
    expect(screen.getByText('Liter')).toBeInTheDocument()
  })

  it('renders decimal_places input seeded from unit data', () => {
    renderComponent()
    const kgInput = screen.getByTestId<HTMLInputElement>('decimal-places-unit-kg-1')
    expect(kgInput.value).toBe('2')
    const literInput = screen.getByTestId<HTMLInputElement>('decimal-places-unit-liter-1')
    expect(literInput.value).toBe('3')
  })

  it('renders rounding_method select seeded from unit data (maps half_up → HalfUp)', () => {
    renderComponent()
    const kgSelect = screen.getByTestId<HTMLSelectElement>('rounding-method-unit-kg-1')
    expect(kgSelect.value).toBe('HalfUp')
  })

  it('renders rounding_method select seeded for floor', () => {
    renderComponent()
    const literSelect = screen.getByTestId<HTMLSelectElement>('rounding-method-unit-liter-1')
    expect(literSelect.value).toBe('Floor')
  })

  it('renders a Save button per row', () => {
    renderComponent()
    expect(screen.getByTestId('save-unit-kg-1')).toBeInTheDocument()
    expect(screen.getByTestId('save-unit-liter-1')).toBeInTheDocument()
  })

  // ── Acceptance criterion 4: edit a row and click Save ─────────────────────

  it('calls updateUnitPrecision with { decimal_places, rounding_method } on Save click', async () => {
    renderComponent()

    // Edit decimal_places to 4
    const kgInput = screen.getByTestId('decimal-places-unit-kg-1')
    fireEvent.change(kgInput, { target: { value: '4' } })

    // Click Save
    fireEvent.click(screen.getByTestId('save-unit-kg-1'))

    await waitFor(() => {
      expect(mockUpdateUnitPrecision).toHaveBeenCalledWith('unit-kg-1', {
        decimal_places: 4,
        rounding_method: 'HalfUp',
      })
    })
  })

  it('calls updateUnitPrecision with updated rounding_method', async () => {
    renderComponent()

    // Change rounding method to Floor
    const kgSelect = screen.getByTestId('rounding-method-unit-kg-1')
    fireEvent.change(kgSelect, { target: { value: 'Floor' } })

    fireEvent.click(screen.getByTestId('save-unit-kg-1'))

    await waitFor(() => {
      expect(mockUpdateUnitPrecision).toHaveBeenCalledWith('unit-kg-1', {
        decimal_places: 2,
        rounding_method: 'Floor',
      })
    })
  })

  // ── Acceptance criterion 3: narrowing warning ──────────────────────────────

  it('does NOT show a narrowing warning when decimal_places stays the same', () => {
    renderComponent()
    expect(screen.queryByTestId('narrowing-warning')).not.toBeInTheDocument()
  })

  it('shows narrowing warning when new decimal_places < original', () => {
    renderComponent()

    // Liter starts at 3 — reduce to 1
    const literInput = screen.getByTestId('decimal-places-unit-liter-1')
    fireEvent.change(literInput, { target: { value: '1' } })

    expect(screen.getByTestId('narrowing-row-unit-liter-1')).toBeInTheDocument()
    expect(
      screen.getByText(
        'Changing this may render existing quantity values inexact. Review the impact report after save.'
      )
    ).toBeInTheDocument()
  })

  it('does NOT show narrowing warning when new decimal_places > original (widening)', () => {
    renderComponent()

    // kg starts at 2 — increase to 4
    const kgInput = screen.getByTestId('decimal-places-unit-kg-1')
    fireEvent.change(kgInput, { target: { value: '4' } })

    expect(screen.queryByTestId('narrowing-row-unit-kg-1')).not.toBeInTheDocument()
  })

  // ── Acceptance criterion 2: query invalidation ─────────────────────────────

  it('invalidates uom queries after a successful save', async () => {
    renderComponent()

    fireEvent.click(screen.getByTestId('save-unit-kg-1'))

    await waitFor(() => {
      expect(mockInvalidateQueries).toHaveBeenCalled()
    })
  })
})
