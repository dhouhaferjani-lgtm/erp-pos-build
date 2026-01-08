import { useState, useCallback, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'

export interface TableStateOptions {
  defaultSort?: { column: string; direction: 'asc' | 'desc' }
  defaultFilters?: Record<string, unknown>
  defaultPerPage?: number
  syncToURL?: boolean
}

export interface TableState {
  // Sorting
  sortColumn: string | null
  sortDirection: 'asc' | 'desc'
  setSorting: (column: string) => void

  // Filtering
  filters: Record<string, unknown>
  setFilter: (key: string, value: unknown) => void
  removeFilter: (key: string) => void
  clearFilters: () => void
  hasActiveFilters: boolean

  // Pagination
  page: number
  perPage: number
  setPage: (page: number) => void
  setPerPage: (perPage: number) => void
  resetPage: () => void

  // Query params (for API calls)
  getQueryParams: () => Record<string, string>
}

/**
 * Hook for managing table state (sorting, filtering, pagination).
 * Optionally syncs state with URL query parameters for bookmarking and sharing.
 */
export function useTableState(options: TableStateOptions = {}): TableState {
  const {
    defaultSort,
    defaultFilters = {},
    defaultPerPage = 25,
    syncToURL = true,
  } = options

  const [searchParams, setSearchParams] = useSearchParams()

  // Parse initial state from URL or use defaults
  const parseInitialSort = (): { column: string | null; direction: 'asc' | 'desc' } => {
    if (syncToURL) {
      const urlSortBy = searchParams.get('sort_by')
      const urlSortDir = searchParams.get('sort_dir')

      if (urlSortBy) {
        return {
          column: urlSortBy,
          direction: (urlSortDir === 'desc' ? 'desc' : 'asc') as 'asc' | 'desc',
        }
      }
    }

    return {
      column: defaultSort?.column ?? null,
      direction: defaultSort?.direction ?? 'asc',
    }
  }

  const parseInitialFilters = (): Record<string, unknown> => {
    if (syncToURL) {
      const filters: Record<string, unknown> = {}
      searchParams.forEach((value, key) => {
        // Skip pagination and sort params
        if (['page', 'per_page', 'sort_by', 'sort_dir'].includes(key)) {
          return
        }
        filters[key] = value
      })
      return Object.keys(filters).length > 0 ? filters : defaultFilters
    }
    return defaultFilters
  }

  const parseInitialPage = (): number => {
    if (syncToURL) {
      const urlPage = searchParams.get('page')
      if (urlPage) {
        const parsed = parseInt(urlPage, 10)
        if (!isNaN(parsed) && parsed > 0) {
          return parsed
        }
      }
    }
    return 1
  }

  const parseInitialPerPage = (): number => {
    if (syncToURL) {
      const urlPerPage = searchParams.get('per_page')
      if (urlPerPage) {
        const parsed = parseInt(urlPerPage, 10)
        if (!isNaN(parsed) && parsed > 0) {
          return parsed
        }
      }
    }
    return defaultPerPage
  }

  // State
  const initialSort = parseInitialSort()
  const [sortColumn, setSortColumn] = useState<string | null>(initialSort.column)
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>(initialSort.direction)
  const [filters, setFilters] = useState<Record<string, unknown>>(parseInitialFilters())
  const [page, setPageState] = useState<number>(parseInitialPage())
  const [perPage, setPerPageState] = useState<number>(parseInitialPerPage())

  // Sync state to URL
  useEffect(() => {
    if (!syncToURL) return

    const params = new URLSearchParams()

    // Add sort params
    if (sortColumn) {
      params.set('sort_by', sortColumn)
      if (sortDirection !== 'asc') {
        params.set('sort_dir', sortDirection)
      }
    }

    // Add filter params
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params.set(key, String(value))
      }
    })

    // Add pagination params
    if (page > 1) {
      params.set('page', String(page))
    }
    if (perPage !== defaultPerPage) {
      params.set('per_page', String(perPage))
    }

    // Update URL without navigating
    setSearchParams(params, { replace: true })
  }, [sortColumn, sortDirection, filters, page, perPage, syncToURL, setSearchParams, defaultPerPage])

  // Sorting
  const setSorting = useCallback((column: string) => {
    setSortColumn((current) => {
      if (current === column) {
        // Toggle direction
        setSortDirection((dir) => (dir === 'asc' ? 'desc' : 'asc'))
        return current
      } else {
        // New column, reset to asc
        setSortDirection('asc')
        return column
      }
    })
    // Reset to page 1 when sort changes
    setPageState(1)
  }, [])

  // Filtering
  const setFilter = useCallback((key: string, value: unknown) => {
    setFilters((current) => ({
      ...current,
      [key]: value,
    }))
    // Reset to page 1 when filters change
    setPageState(1)
  }, [])

  const removeFilter = useCallback((key: string) => {
    setFilters((current) => {
      const updated = { ...current }
      delete updated[key]
      return updated
    })
    // Reset to page 1 when filters change
    setPageState(1)
  }, [])

  const clearFilters = useCallback(() => {
    setFilters({})
    // Reset to page 1 when filters cleared
    setPageState(1)
  }, [])

  // Pagination
  const setPage = useCallback((newPage: number) => {
    setPageState(newPage)
  }, [])

  const setPerPage = useCallback((newPerPage: number) => {
    setPerPageState(newPerPage)
    // Reset to page 1 when per page changes
    setPageState(1)
  }, [])

  const resetPage = useCallback(() => {
    setPageState(1)
  }, [])

  // Query params for API
  const getQueryParams = useCallback((): Record<string, string> => {
    const params: Record<string, string> = {}

    // Sort
    if (sortColumn) {
      params.sort_by = sortColumn
      params.sort_dir = sortDirection
    }

    // Filters
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params[key] = String(value)
      }
    })

    // Pagination
    params.page = String(page)
    params.per_page = String(perPage)

    return params
  }, [sortColumn, sortDirection, filters, page, perPage])

  return {
    sortColumn,
    sortDirection,
    setSorting,
    filters,
    setFilter,
    removeFilter,
    clearFilters,
    hasActiveFilters: Object.keys(filters).length > 0,
    page,
    perPage,
    setPage,
    setPerPage,
    resetPage,
    getQueryParams,
  }
}
