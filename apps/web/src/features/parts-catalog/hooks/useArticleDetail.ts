import { useQuery, useQueries, type UseQueryResult } from '@tanstack/react-query'
import { getArticle, getArticleLinkages } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import type { EnrichedArticle, CompatibleVehicle } from '../types/catalog'

const ONE_HOUR = 1000 * 60 * 60

export function useArticleDetail(articleId: string): UseQueryResult<EnrichedArticle> {
  return useQuery({
    queryKey: partsCatalogKeys.articleDetail(articleId),
    queryFn: () => getArticle(articleId),
    enabled: Boolean(articleId),
    staleTime: ONE_HOUR,
  })
}

export function useArticleLinkages(
  articleId: string
): UseQueryResult<{ vehicles: CompatibleVehicle[] }> {
  return useQuery({
    queryKey: partsCatalogKeys.articleLinkages(articleId),
    queryFn: () => getArticleLinkages(articleId),
    enabled: Boolean(articleId),
    staleTime: ONE_HOUR,
  })
}

/**
 * Fires article detail + linkages in parallel (per spec section 6).
 */
export function useArticleDetailParallel(articleId: string) {
  const results = useQueries({
    queries: [
      {
        queryKey: partsCatalogKeys.articleDetail(articleId),
        queryFn: () => getArticle(articleId),
        enabled: Boolean(articleId),
        staleTime: ONE_HOUR,
      },
      {
        queryKey: partsCatalogKeys.articleLinkages(articleId),
        queryFn: () => getArticleLinkages(articleId),
        enabled: Boolean(articleId),
        staleTime: ONE_HOUR,
      },
    ],
  })

  return {
    article: results[0].data,
    linkages: results[1].data,
    isLoading: results[0].isLoading || results[1].isLoading,
    isError: results[0].isError || results[1].isError,
    error: results[0].error ?? results[1].error,
  }
}
