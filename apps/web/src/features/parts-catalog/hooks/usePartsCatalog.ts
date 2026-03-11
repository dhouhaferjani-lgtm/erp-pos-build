import type { VehicleType } from '../types/catalog'

/**
 * Query key factory for parts catalog.
 * All keys start with 'parts-catalog' to enable bulk invalidation.
 */
export const partsCatalogKeys = {
  all: ['parts-catalog'] as const,

  // Vehicle drill-down
  manufacturers: () => [...partsCatalogKeys.all, 'manufacturers'] as const,
  modelSeries: (manufacturerId: string) =>
    [...partsCatalogKeys.all, 'model-series', manufacturerId] as const,
  vehicles: (modelSeriesId: string, type: VehicleType) =>
    [...partsCatalogKeys.all, 'vehicles', modelSeriesId, type] as const,

  // Articles
  articles: (params: Record<string, unknown>) =>
    [...partsCatalogKeys.all, 'articles', params] as const,
  articleDetail: (articleId: string) =>
    [...partsCatalogKeys.all, 'article', articleId] as const,
  articleLinkages: (articleId: string) =>
    [...partsCatalogKeys.all, 'article', articleId, 'linkages'] as const,

  // Search
  multiSearch: (query: string) =>
    [...partsCatalogKeys.all, 'search', query] as const,
  criteriaSearch: (filters: Record<string, unknown>) =>
    [...partsCatalogKeys.all, 'criteria-search', filters] as const,

  // Search tree
  searchTreeRoots: (treeType: VehicleType) =>
    [...partsCatalogKeys.all, 'search-tree', 'roots', treeType] as const,
  searchTreeChildren: (nodeId: string) =>
    [...partsCatalogKeys.all, 'search-tree', nodeId, 'children'] as const,
  searchTreeArticles: (nodeId: string) =>
    [...partsCatalogKeys.all, 'search-tree', nodeId, 'articles'] as const,

  // Metadata (cached long-term)
  criteriaMetadata: () => [...partsCatalogKeys.all, 'criteria-metadata'] as const,
  supplierBrands: () => [...partsCatalogKeys.all, 'supplier-brands'] as const,
}
