import { describe, it, expect, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useCatalogStore } from '../useCatalogStore'

describe('useCatalogStore', () => {
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

  it('starts with default state', () => {
    const { result } = renderHook(() => useCatalogStore())
    expect(result.current.searchMode).toBe('vehicle')
    expect(result.current.viewMode).toBe('grid')
    expect(result.current.filters.inStockOnly).toBe(false)
  })

  it('sets search mode', () => {
    const { result } = renderHook(() => useCatalogStore())
    act(() => {
      result.current.setSearchMode('partNumber')
    })
    expect(result.current.searchMode).toBe('partNumber')
  })

  it('toggles view mode', () => {
    const { result } = renderHook(() => useCatalogStore())
    expect(result.current.viewMode).toBe('grid')
    act(() => {
      result.current.setViewMode('list')
    })
    expect(result.current.viewMode).toBe('list')
  })

  it('updates filters partially', () => {
    const { result } = renderHook(() => useCatalogStore())
    act(() => {
      result.current.updateFilters({ inStockOnly: true, supplierIds: ['s-1'] })
    })
    expect(result.current.filters.inStockOnly).toBe(true)
    expect(result.current.filters.supplierIds).toEqual(['s-1'])
    expect(result.current.filters.sortBy).toBe('relevance') // unchanged
  })

  it('resets filters to defaults', () => {
    const { result } = renderHook(() => useCatalogStore())
    act(() => {
      result.current.updateFilters({ inStockOnly: true, sortBy: 'price_asc' })
    })
    act(() => {
      result.current.resetFilters()
    })
    expect(result.current.filters.inStockOnly).toBe(false)
    expect(result.current.filters.sortBy).toBe('relevance')
  })

  it('sets product group', () => {
    const { result } = renderHook(() => useCatalogStore())
    act(() => {
      result.current.setProductGroup('pg-1')
    })
    expect(result.current.activeProductGroupId).toBe('pg-1')
  })
})
