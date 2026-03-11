import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import { FilterSidebar } from '../FilterSidebar'
import { useCatalogStore } from '../../../stores/useCatalogStore'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'parts-catalog:filters.title': 'Filters',
        'parts-catalog:filters.sortBy': 'Sort by',
        'parts-catalog:filters.inStockOnly': 'In stock only',
        'parts-catalog:filters.supplierBrand': 'Supplier Brand',
        'parts-catalog:filters.confidenceMin': 'Min. confidence',
        'parts-catalog:filters.reset': 'Reset Filters',
        'parts-catalog:filters.sort.relevance': 'Relevance',
        'parts-catalog:filters.sort.price_asc': 'Price: Low to High',
        'parts-catalog:filters.sort.price_desc': 'Price: High to Low',
        'parts-catalog:filters.sort.brand_asc': 'Brand: A to Z',
        'parts-catalog:filters.sort.confidence_desc': 'Confidence: High to Low',
      }
      return translations[key] ?? key
    },
  }),
}))

vi.mock('../../../hooks/useSupplierBrands', () => ({
  useSupplierBrands: () => ({
    data: [
      { id: 's-1', brand: 'Bosch', slug: 'bosch' },
      { id: 's-2', brand: 'Continental', slug: 'continental' },
    ],
  }),
}))

describe('FilterSidebar', () => {
  beforeEach(() => {
    useCatalogStore.setState({
      searchMode: 'vehicle',
      activeProductGroupId: null,
      viewMode: 'grid',
      filters: {
        supplierIds: [],
        inStockOnly: false,
        confidenceMin: 0,
        sortBy: 'relevance',
      },
    })
  })

  it('renders filter title', () => {
    render(<FilterSidebar />)
    expect(screen.getByText('Filters')).toBeInTheDocument()
  })

  it('renders sort dropdown with all options', () => {
    render(<FilterSidebar />)
    expect(screen.getByText('Sort by')).toBeInTheDocument()
    const select = screen.getByRole('combobox')
    expect(select).toBeInTheDocument()
    expect(screen.getByText('Relevance')).toBeInTheDocument()
  })

  it('renders in-stock toggle', () => {
    render(<FilterSidebar />)
    expect(screen.getByText('In stock only')).toBeInTheDocument()
  })

  it('renders supplier brand checkboxes', () => {
    render(<FilterSidebar />)
    expect(screen.getByText('Bosch')).toBeInTheDocument()
    expect(screen.getByText('Continental')).toBeInTheDocument()
  })

  it('toggles in-stock filter', () => {
    render(<FilterSidebar />)
    const checkbox = screen.getByRole('checkbox', { name: 'In stock only' })
    fireEvent.click(checkbox)
    expect(useCatalogStore.getState().filters.inStockOnly).toBe(true)
  })

  it('toggles supplier brand filter', () => {
    render(<FilterSidebar />)
    const boschCheckbox = screen.getByRole('checkbox', { name: 'Bosch' })
    fireEvent.click(boschCheckbox)
    expect(useCatalogStore.getState().filters.supplierIds).toEqual(['s-1'])

    // Toggle off
    fireEvent.click(boschCheckbox)
    expect(useCatalogStore.getState().filters.supplierIds).toEqual([])
  })

  it('changes sort option', () => {
    render(<FilterSidebar />)
    const select = screen.getByRole('combobox')
    fireEvent.change(select, { target: { value: 'price_asc' } })
    expect(useCatalogStore.getState().filters.sortBy).toBe('price_asc')
  })

  it('does not show reset button when no filters are active', () => {
    render(<FilterSidebar />)
    expect(screen.queryByText('Reset Filters')).not.toBeInTheDocument()
  })

  it('shows reset button when filters are active', () => {
    useCatalogStore.setState({
      filters: {
        supplierIds: ['s-1'],
        inStockOnly: true,
        confidenceMin: 0,
        sortBy: 'relevance',
      },
    })
    render(<FilterSidebar />)
    expect(screen.getByText('Reset Filters')).toBeInTheDocument()
  })

  it('resets filters when reset button clicked', () => {
    useCatalogStore.setState({
      filters: {
        supplierIds: ['s-1'],
        inStockOnly: true,
        confidenceMin: 50,
        sortBy: 'price_asc',
      },
    })
    render(<FilterSidebar />)
    fireEvent.click(screen.getByText('Reset Filters'))

    const state = useCatalogStore.getState().filters
    expect(state.supplierIds).toEqual([])
    expect(state.inStockOnly).toBe(false)
    expect(state.sortBy).toBe('relevance')
  })
})
