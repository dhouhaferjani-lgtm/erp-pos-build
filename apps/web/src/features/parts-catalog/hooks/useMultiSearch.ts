import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { multiSearch } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { MultiSearchResponse } from '../types/catalog'

export function useMultiSearch(query: string): UseQueryResult<MultiSearchResponse> {
  const trimmed = query.trim()
  return useQuery({
    queryKey: partsCatalogKeys.multiSearch(trimmed),
    queryFn: () => multiSearch(trimmed),
    enabled: trimmed.length >= 3,
    staleTime: 1000 * 60 * 30, // 30 minutes
  })
}
