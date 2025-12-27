import { useState, useCallback } from 'react'

interface PaginationState {
  cursor: string | null
  perPage: number
  hasMore: boolean
  isLoading: boolean
}

interface PaginationLinks {
  next: string | null
  prev: string | null
}

interface UsePaginationOptions {
  initialPerPage?: number
}

interface PaginationMeta {
  per_page: number
  has_more: boolean
  total?: number
}

export function usePagination(options: UsePaginationOptions = {}) {
  const { initialPerPage = 25 } = options

  const [state, setState] = useState<PaginationState>({
    cursor: null,
    perPage: initialPerPage,
    hasMore: false,
    isLoading: false,
  })

  const [links, setLinks] = useState<PaginationLinks>({
    next: null,
    prev: null,
  })

  const [history, setHistory] = useState<string[]>([])

  const updateFromResponse = useCallback((meta: PaginationMeta, responseLinks: PaginationLinks) => {
    setState(prev => ({
      ...prev,
      hasMore: meta.has_more ?? false,
      isLoading: false,
    }))
    setLinks(responseLinks)
  }, [])

  const goToNext = useCallback(() => {
    if (links.next) {
      setHistory(prev => [...prev, state.cursor || ''])
      setState(prev => ({ ...prev, cursor: links.next, isLoading: true }))
    }
  }, [links.next, state.cursor])

  const goToPrev = useCallback(() => {
    if (history.length > 0) {
      const newHistory = [...history]
      const prevCursor = newHistory.pop() || null
      setHistory(newHistory)
      setState(prev => ({ ...prev, cursor: prevCursor, isLoading: true }))
    }
  }, [history])

  const reset = useCallback(() => {
    setState(prev => ({ ...prev, cursor: null, isLoading: true }))
    setHistory([])
  }, [])

  const setPerPage = useCallback((perPage: number) => {
    setState(prev => ({ ...prev, perPage, cursor: null, isLoading: true }))
    setHistory([])
  }, [])

  return {
    cursor: state.cursor,
    perPage: state.perPage,
    hasMore: state.hasMore,
    isLoading: state.isLoading,
    hasPrev: history.length > 0,
    hasNext: !!links.next,
    goToNext,
    goToPrev,
    reset,
    setPerPage,
    updateFromResponse,
    // For building query params
    getQueryParams: () => ({
      per_page: state.perPage,
      ...(state.cursor && { cursor: state.cursor }),
    }),
  }
}
