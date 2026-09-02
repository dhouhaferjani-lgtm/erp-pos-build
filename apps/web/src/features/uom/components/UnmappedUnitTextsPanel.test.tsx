import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import type { Unit } from '../api/uomApi'
import { UnmappedUnitTextsPanel } from './UnmappedUnitTextsPanel'

const { mockApply, mockToastSuccess, mockUseUnmappedUnitTexts, mockUseUnits } = vi.hoisted(() => ({
  mockApply: vi.fn(),
  mockToastSuccess: vi.fn(),
  mockUseUnmappedUnitTexts: vi.fn(),
  mockUseUnits: vi.fn(),
}))

vi.mock('../hooks/useUnits', () => ({
  useApplyUnitTextMapping: () => ({ mutateAsync: mockApply, isPending: false }),
  useUnmappedUnitTexts: mockUseUnmappedUnitTexts,
  useUnits: mockUseUnits,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, values?: Record<string, string | number>) => {
      const translations: Record<string, string> = {
        'actions.cancel': 'Cancel',
        'actions.confirm': 'Confirm',
        'actions.processing': 'Processing',
        'uom:unmapped.blank': '(blank)',
        'uom:unmapped.columns.count': 'Occurrences',
        'uom:unmapped.columns.source': 'Source text',
        'uom:unmapped.columns.target': 'Map to',
        'uom:unmapped.confirm': 'Map {{source}} to exact unit code {{code}}?',
        'uom:unmapped.confirmTitle': 'Confirm unit mapping',
        'uom:unmapped.description': 'Resolve legacy and preview unit texts for this company.',
        'uom:unmapped.empty': 'No unmapped unit texts.',
        'uom:unmapped.importRows': '{{count}} preview rows',
        'uom:unmapped.map': 'Map',
        'uom:unmapped.pendingImports': 'across {{count}} pending imports',
        'uom:unmapped.products': '{{count}} products',
        'uom:unmapped.selectTarget': 'Select exact code',
        'uom:unmapped.success': 'Unit text mapped.',
        'uom:unmapped.successAliasStored': 'Unit text mapping saved for future imports.',
        'uom:unmapped.title': 'Unmapped unit texts',
      }
      return (translations[key] ?? key).replace(/{{(\w+)}}/g, (_, name: string) => String(values?.[name] ?? ''))
    },
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: mockToastSuccess, error: vi.fn() },
}))

// Recorded GET /api/v1/uom/units response shape. Keep this fixture camelCase
// and complete so API-contract drift cannot be hidden by test-only aliases.
const units = [
  {
    id: 'unit-pc',
    categoryId: 'category-pieces',
    code: 'pc',
    name: 'Piece',
    symbol: 'pc',
    conversionFactor: '1.0000000000',
    decimalPlaces: 0,
    roundingMethod: 'half_up',
    isBaseUnit: true,
    isSystem: true,
    isActive: true,
    category: null,
  },
  {
    id: 'unit-kg',
    categoryId: 'category-weight',
    code: 'kg',
    name: 'Kilogram',
    symbol: 'kg',
    conversionFactor: '1000.0000000000',
    decimalPlaces: 3,
    roundingMethod: 'half_up',
    isBaseUnit: false,
    isSystem: true,
    isActive: true,
    category: null,
  },
] satisfies Unit[]

describe('UnmappedUnitTextsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseUnits.mockReturnValue({ data: units, isLoading: false, error: null })
    mockUseUnmappedUnitTexts.mockReturnValue({
      data: [
        { sourceText: null, productCount: 2, importRowCount: 0, pendingImportCount: 0, totalCount: 2 },
        { sourceText: 'piece', productCount: 0, importRowCount: 1718, pendingImportCount: 2, totalCount: 1718 },
      ],
      isLoading: false,
      error: null,
    })
    mockApply.mockResolvedValue({
      sourceText: 'piece',
      targetUnitId: 'unit-pc',
      targetUnitCode: 'pc',
      productCount: 0,
      importRowCount: 0,
      applied: false,
      aliasStored: true,
    })
  })

  it('shows company source counts and restricts blank to pc', () => {
    renderWithProviders(<UnmappedUnitTextsPanel />)

    const blankRow = screen.getByTestId('unit-text-source-blank')
    expect(within(blankRow).getByText('(blank)')).toBeInTheDocument()
    expect(within(blankRow).getByText('2')).toBeInTheDocument()
    expect(within(blankRow).getByRole('combobox')).toHaveValue('unit-pc')
    expect(within(blankRow).getByRole('combobox')).toBeDisabled()

    const pieceRow = screen.getByTestId('unit-text-source-piece')
    expect(within(pieceRow).getByText('1718')).toBeInTheDocument()
    expect(pieceRow).toHaveTextContent('0 products · 1718 preview rows across 2 pending imports')
    expect(within(pieceRow).getByRole('option', { name: 'pc — Piece' })).toBeInTheDocument()
    expect(within(pieceRow).getByRole('option', { name: 'kg — Kilogram' })).toBeInTheDocument()
  })

  it('requires confirmation before applying the exact mapping', async () => {
    const user = userEvent.setup()
    renderWithProviders(<UnmappedUnitTextsPanel />)

    const pieceRow = screen.getByTestId('unit-text-source-piece')
    await user.selectOptions(within(pieceRow).getByRole('combobox'), 'unit-pc')
    await user.click(within(pieceRow).getByRole('button', { name: 'Map' }))

    expect(screen.getByText('Map piece to exact unit code pc?')).toBeInTheDocument()
    expect(mockApply).not.toHaveBeenCalled()

    await user.click(screen.getByTestId('confirm-dialog-confirm'))
    await waitFor(() => {
      expect(mockApply).toHaveBeenCalledWith({ sourceText: 'piece', targetUnitId: 'unit-pc' })
    })
  })

  it('reports when an alias was stored for future imports without retro-applying rows', async () => {
    const user = userEvent.setup()
    renderWithProviders(<UnmappedUnitTextsPanel />)

    const pieceRow = screen.getByTestId('unit-text-source-piece')
    await user.selectOptions(within(pieceRow).getByRole('combobox'), 'unit-pc')
    await user.click(within(pieceRow).getByRole('button', { name: 'Map' }))
    await user.click(screen.getByTestId('confirm-dialog-confirm'))

    await waitFor(() => {
      expect(mockToastSuccess).toHaveBeenCalledWith('Unit text mapping saved for future imports.')
    })
  })

  it('renders an honest empty state', () => {
    mockUseUnmappedUnitTexts.mockReturnValue({ data: [], isLoading: false, error: null })

    renderWithProviders(<UnmappedUnitTextsPanel />)

    expect(screen.getByText('No unmapped unit texts.')).toBeInTheDocument()
  })
})
