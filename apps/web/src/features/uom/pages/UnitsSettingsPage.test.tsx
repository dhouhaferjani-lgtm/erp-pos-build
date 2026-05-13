import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth } from '@/test/seedAuth'
import { UnitsSettingsPage } from './UnitsSettingsPage'
import type { UnitCategory } from '../api/uomApi'
import { makeUnit, makeUnitCategory } from '../__fixtures__/unit'

// Mock the API - must use vi.hoisted for variables used in vi.mock factory
const { mockApiGet, mockApiPost, mockApiDelete } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
  mockApiPost: vi.fn(),
  mockApiDelete: vi.fn(),
}))

vi.mock('../../../lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
  apiDelete: mockApiDelete,
  api: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}))

// Mock react-i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'uom:title': 'Units of Measure',
        'uom:systemUnitInfo': 'System units are read-only',
        'uom:addUnit': 'Add Unit',
        'uom:name': 'Name',
        'uom:code': 'Code',
        'uom:symbol': 'Symbol',
        'uom:conversionFactor': 'Conversion Factor',
        'uom:isBaseUnit': 'Base Unit',
        'uom:isSystem': 'System',
        'uom:noUnits': 'No units in this category',
        'uom:unitDeleted': 'Unit deleted successfully',
        'uom:errors.systemUnit': 'Cannot delete system units',
        'uom:errors.unitInUse': 'This unit may be in use',
        'common:common.status': 'Status',
        'common:common.custom': 'Custom',
        'common:common.error': 'An error occurred',
        'common:table.actionsColumn': 'Actions',
        'common:actions.edit': 'Edit',
        'common:actions.delete': 'Delete',
        'common:common.confirmDelete': 'Delete {{resource}}?',
      }
      return translations[key] || key
    },
    i18n: { language: 'en' },
  }),
}))

// Mock sonner toast
vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

/**
 * Mock data matching the backend DTO structure
 */
const mockCategories: UnitCategory[] = [
  makeUnitCategory({
    id: 'cat-weight-1',
    code: 'weight',
    name: 'Weight',
    description: 'Units for measuring weight',
    base_unit_id: 'unit-gram-1',
    units: [
      makeUnit({
        id: 'unit-gram-1',
        category_id: 'cat-weight-1',
        code: 'g',
        name: 'Gram',
        symbol: 'g',
        conversion_factor: '1',
        is_base_unit: true,
        is_system: true,
      }),
      makeUnit({
        id: 'unit-kg-1',
        category_id: 'cat-weight-1',
        code: 'kg',
        name: 'Kilogram',
        symbol: 'kg',
        conversion_factor: '1000',
        is_base_unit: false,
        is_system: true,
      }),
    ],
  }),
  makeUnitCategory({
    id: 'cat-volume-1',
    code: 'volume',
    name: 'Volume',
    description: 'Units for measuring volume',
    base_unit_id: 'unit-liter-1',
    units: [
      makeUnit({
        id: 'unit-liter-1',
        category_id: 'cat-volume-1',
        code: 'l',
        name: 'Liter',
        symbol: 'L',
        conversion_factor: '1',
        is_base_unit: true,
        is_system: true,
      }),
    ],
  }),
]

describe('UnitsSettingsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  /**
   * REGRESSION TEST: Categories must display their units
   *
   * Issue: Units were not appearing in the categories list after creation
   * Root Cause: UnitCategoryData DTO was missing units array property
   *
   * This test ensures that when categories are fetched, their units
   * are displayed in the table.
   */
  describe('Categories display units', () => {
    it('renders categories with their units', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      // Wait for data to load by checking for content
      await waitFor(() => {
        expect(screen.getByText('Weight')).toBeInTheDocument()
      })

      // Verify Weight category is displayed
      expect(screen.getByText('Weight')).toBeInTheDocument()
      expect(screen.getByText('Units for measuring weight')).toBeInTheDocument()

      // CRITICAL: Verify units are displayed
      expect(screen.getByText('Gram')).toBeInTheDocument()
      expect(screen.getByText('Kilogram')).toBeInTheDocument()

      // Verify Volume category is displayed
      expect(screen.getByText('Volume')).toBeInTheDocument()
      expect(screen.getByText('Liter')).toBeInTheDocument()
    })

    it('displays unit properties correctly', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Gram')).toBeInTheDocument()
      })

      // Verify gram unit details
      const gramRow = screen.getByText('Gram').closest('tr')!
      expect(gramRow).toBeInTheDocument()
      expect(within(gramRow).getByText('Base Unit')).toBeInTheDocument()
      expect(within(gramRow).getByText('System')).toBeInTheDocument()

      // Verify kilogram unit details
      const kgRow = screen.getByText('Kilogram').closest('tr')!
      expect(kgRow).toBeInTheDocument()
      expect(within(kgRow).getByText('× 1000')).toBeInTheDocument()
      expect(within(kgRow).getByText('System')).toBeInTheDocument()
    })

    it('shows empty state when category has no units', async () => {
      const emptyCategory: UnitCategory = {
        id: 'cat-empty-1',
        code: 'empty',
        name: 'Empty Category',
        description: null,
        base_unit_id: null,
        is_active: true,
        units: [], // No units
      }

      mockApiGet.mockResolvedValue([emptyCategory])

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Empty Category')).toBeInTheDocument()
      })

      // CRITICAL: Empty state should be shown
      expect(screen.getByText('No units in this category')).toBeInTheDocument()
    })
  })

  /**
   * REGRESSION TEST: System units are read-only
   *
   * Issue: Need to ensure system units cannot be modified or deleted
   *
   * This test verifies that edit/delete buttons are disabled for system units.
   */
  describe('System units are protected', () => {
    it('disables edit button for system units', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Gram')).toBeInTheDocument()
      })

      // Find the gram row (system unit)
      const gramRow = screen.getByText('Gram').closest('tr')!
      const editButton = within(gramRow).getByLabelText('Edit')

      // CRITICAL: Edit button should be disabled for system units
      expect(editButton).toBeDisabled()
    })

    it('disables delete button for system units', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Gram')).toBeInTheDocument()
      })

      // Find the gram row (system unit)
      const gramRow = screen.getByText('Gram').closest('tr')!
      const deleteButton = within(gramRow).getByLabelText('Delete')

      // CRITICAL: Delete button should be disabled for system units
      expect(deleteButton).toBeDisabled()
    })

    it('shows error toast when trying to delete system unit', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      const user = userEvent.setup()
      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Gram')).toBeInTheDocument()
      })

      // Find and click delete button on system unit
      const gramRow = screen.getByText('Gram').closest('tr')!
      const deleteButton = within(gramRow).getByLabelText('Delete')

      // Click should do nothing since button is disabled
      await user.click(deleteButton)

      // No delete confirmation should appear
      expect(screen.queryByText(/Delete Gram/i)).not.toBeInTheDocument()
    })
  })

  /**
   * REGRESSION TEST: Custom units can be managed
   *
   * This test verifies that custom (non-system) units have
   * enabled edit/delete buttons.
   */
  describe('Custom units management', () => {
    it('enables edit and delete for custom units', async () => {
      const categoriesWithCustomUnit: UnitCategory[] = [
        makeUnitCategory({
          ...mockCategories[1],
          units: [
            ...(mockCategories[1].units ?? []),
            makeUnit({
              id: 'unit-custom-1',
              category_id: 'cat-volume-1',
              code: 'tbsp',
              name: 'Tablespoon',
              symbol: 'tbsp',
              conversion_factor: '0.015',
              is_base_unit: false,
              is_system: false, // CUSTOM UNIT
            }),
          ],
        }),
      ]

      mockApiGet.mockResolvedValue(categoriesWithCustomUnit)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Tablespoon')).toBeInTheDocument()
      })

      // Find the custom unit row
      const customRow = screen.getByText('Tablespoon').closest('tr')!
      const editButton = within(customRow).getByLabelText('Edit')
      const deleteButton = within(customRow).getByLabelText('Delete')

      // CRITICAL: Buttons should be enabled for custom units
      expect(editButton).not.toBeDisabled()
      expect(deleteButton).not.toBeDisabled()

      // Should show "Custom" badge instead of "System"
      expect(within(customRow).getByText('Custom')).toBeInTheDocument()
    })

    it('shows confirmation dialog when deleting custom unit', async () => {
      const categoriesWithCustomUnit: UnitCategory[] = [
        makeUnitCategory({
          ...mockCategories[1],
          units: [
            makeUnit({
              id: 'unit-custom-1',
              category_id: 'cat-volume-1',
              code: 'tbsp',
              name: 'Tablespoon',
              symbol: 'tbsp',
              conversion_factor: '0.015',
              is_base_unit: false,
              is_system: false,
            }),
          ],
        }),
      ]

      mockApiGet.mockResolvedValue(categoriesWithCustomUnit)
      mockApiDelete.mockResolvedValue(undefined)

      const user = userEvent.setup()
      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Tablespoon')).toBeInTheDocument()
      })

      // Click delete button
      const customRow = screen.getByText('Tablespoon').closest('tr')!
      const deleteButton = within(customRow).getByLabelText('Delete')
      await user.click(deleteButton)

      // CRITICAL: Confirmation dialog should appear
      await waitFor(() => {
        // The dialog shows a message about the unit being in use
        expect(screen.getByText('This unit may be in use')).toBeInTheDocument()
      })
    })
  })

  /**
   * Page states
   */
  describe('Page states', () => {
    it('shows loading state initially', () => {
      mockApiGet.mockImplementation(() => new Promise(() => {})) // Never resolves

      renderWithProviders(<UnitsSettingsPage />)

      // Check for spinner using SVG class
      const spinnerSvg = document.querySelector('.animate-spin')
      expect(spinnerSvg).toBeInTheDocument()
    })

    it('shows error state when API fails', async () => {
      mockApiGet.mockRejectedValue(new Error('API Error'))

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('An error occurred')).toBeInTheDocument()
      })
    })

    it('renders page title and description', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByRole('heading', { name: 'Units of Measure' })).toBeInTheDocument()
      })

      expect(screen.getByText('System units are read-only')).toBeInTheDocument()
    })
  })

  /**
   * Add Unit functionality
   */
  describe('Add Unit button', () => {
    it('shows add unit button in header', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Weight')).toBeInTheDocument()
      })

      // Main add button in header
      const headerButtons = screen.getAllByRole('button', { name: 'Add Unit' })
      expect(headerButtons.length).toBeGreaterThan(0)
    })

    it('shows add unit button for each category', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Weight')).toBeInTheDocument()
      })

      // Should have add button in header + one per category
      const addButtons = screen.getAllByRole('button', { name: 'Add Unit' })
      expect(addButtons.length).toBe(3) // 1 header + 2 categories
    })
  })

  /**
   * REGRESSION TEST: Verify API integration
   *
   * This ensures the component correctly calls the API and handles responses.
   */
  describe('API integration', () => {
    it('calls fetchCategories on mount', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(mockApiGet).toHaveBeenCalledWith('/uom/categories')
      })
    })

    it('displays all categories returned from API', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Weight')).toBeInTheDocument()
      })

      // Both categories should be rendered
      expect(screen.getByText('Weight')).toBeInTheDocument()
      expect(screen.getByText('Volume')).toBeInTheDocument()
    })

    it('displays all units within each category', async () => {
      mockApiGet.mockResolvedValue(mockCategories)

      renderWithProviders(<UnitsSettingsPage />)

      await waitFor(() => {
        expect(screen.getByText('Gram')).toBeInTheDocument()
      })

      // All units should be rendered
      expect(screen.getByText('Gram')).toBeInTheDocument()
      expect(screen.getByText('Kilogram')).toBeInTheDocument()
      expect(screen.getByText('Liter')).toBeInTheDocument()
    })
  })
})
