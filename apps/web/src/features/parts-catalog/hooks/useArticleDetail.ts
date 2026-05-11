import { useQuery, useQueries, type UseQueryResult } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getArticle, getArticleLinkages } from '../api/partsCatalog'
import { partsCatalogKeys } from './usePartsCatalog'
import { usePartsCatalogTenantScope } from './usePartsCatalogTenantScope'
import type { EnrichedArticle, CompatibleVehicle } from '../types/catalog'

const ONE_HOUR = 1000 * 60 * 60

export function useArticleDetail(articleId: string): UseQueryResult<EnrichedArticle> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.articleDetail(articleId)]),
    queryFn: () => getArticle(articleId),
    enabled: Boolean(articleId) && hasTenantScope,
    staleTime: ONE_HOUR,
  })
}

export function useArticleLinkages(
  articleId: string
): UseQueryResult<{ vehicles: CompatibleVehicle[] }> {
  const hasTenantScope = usePartsCatalogTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...partsCatalogKeys.articleLinkages(articleId)]),
    queryFn: () => getArticleLinkages(articleId),
    enabled: Boolean(articleId) && hasTenantScope,
    staleTime: ONE_HOUR,
  })
}

/**
 * Fires article detail + linkages in parallel (per spec section 6).
 */
export function useArticleDetailParallel(articleId: string) {
  const hasTenantScope = usePartsCatalogTenantScope()
  const results = useQueries({
    queries: [
      {
        queryKey: tenantScopedKey([...partsCatalogKeys.articleDetail(articleId)]),
        queryFn: () => getArticle(articleId),
        enabled: Boolean(articleId) && hasTenantScope,
        staleTime: ONE_HOUR,
      },
      {
        queryKey: tenantScopedKey([...partsCatalogKeys.articleLinkages(articleId)]),
        queryFn: () => getArticleLinkages(articleId),
        enabled: Boolean(articleId) && hasTenantScope,
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
