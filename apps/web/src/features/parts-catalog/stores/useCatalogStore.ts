import { create } from 'zustand'
import type { SearchMode } from '../types/catalog'

type SortOption = 'relevance' | 'price_asc' | 'price_desc' | 'brand_asc' | 'confidence_desc'
type ViewMode = 'grid' | 'list'

export interface CatalogFilters {
  supplierIds: string[]
  inStockOnly: boolean
  priceMin?: number
  priceMax?: number
  confidenceMin: number
  sortBy: SortOption
}

const DEFAULT_FILTERS: CatalogFilters = {
  supplierIds: [],
  inStockOnly: false,
  confidenceMin: 0,
  sortBy: 'relevance',
}

interface CatalogState {
  searchMode: SearchMode
  activeProductGroupId: string | null
  viewMode: ViewMode
  filters: CatalogFilters
}

interface CatalogActions {
  setSearchMode: (mode: SearchMode) => void
  setProductGroup: (id: string | null) => void
  setViewMode: (mode: ViewMode) => void
  updateFilters: (filters: Partial<CatalogFilters>) => void
  resetFilters: () => void
}

export const useCatalogStore = create<CatalogState & CatalogActions>()((set) => ({
  searchMode: 'vehicle',
  activeProductGroupId: null,
  viewMode: 'grid',
  filters: { ...DEFAULT_FILTERS },

  setSearchMode: (mode) => {
    set({ searchMode: mode, activeProductGroupId: null })
  },

  setProductGroup: (id) => {
    set({ activeProductGroupId: id })
  },

  setViewMode: (mode) => {
    set({ viewMode: mode })
  },

  updateFilters: (partial) => {
    set((state) => ({
      filters: { ...state.filters, ...partial },
    }))
  },

  resetFilters: () => {
    set({ filters: { ...DEFAULT_FILTERS } })
  },
}))
