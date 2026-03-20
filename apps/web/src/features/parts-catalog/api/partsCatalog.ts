import { apiGet, apiPost } from '@/lib/api'
import type {
  Manufacturer,
  ModelSeries,
  Vehicle,
  VehicleType,
  SearchTreeNode,
  EnrichedArticle,
  PaginatedArticles,
  MultiSearchResponse,
  CriteriaMetadata,
  CriteriaSearchRequest,
  Supplier,
  AddToInventoryRequest,
} from '../types/catalog'

const BASE = '/platform/catalog'

// ─── Vehicle Drill-Down ─────────────────────────────────────────────

export function getManufacturers(): Promise<Manufacturer[]> {
  return apiGet<Manufacturer[]>(`${BASE}/manufacturers`)
}

export function getModelSeries(manufacturerId: string): Promise<ModelSeries[]> {
  return apiGet<ModelSeries[]>(`${BASE}/manufacturers/${manufacturerId}/model-series`)
}

export function getVehicles(modelSeriesId: string, type: VehicleType = 'pc'): Promise<Vehicle[]> {
  return apiGet<Vehicle[]>(`${BASE}/model-series/${modelSeriesId}/vehicles`, { type })
}

export function getVehicle(type: VehicleType, vehicleId: string): Promise<Vehicle> {
  return apiGet<Vehicle>(`${BASE}/vehicles/${type}/${vehicleId}`)
}

// ─── Articles ───────────────────────────────────────────────────────

export function getVehicleArticles(
  type: VehicleType,
  vehicleId: string,
  params: { product_group_id?: string; cursor?: string; per_page?: number }
): Promise<PaginatedArticles> {
  return apiGet<PaginatedArticles>(`${BASE}/vehicles/${type}/${vehicleId}/articles`, params)
}

export function getArticle(articleId: string): Promise<EnrichedArticle> {
  return apiGet<EnrichedArticle>(`${BASE}/articles/${articleId}`)
}

export function getArticleLinkages(articleId: string): Promise<{ vehicles: EnrichedArticle['compatible_vehicles'] }> {
  return apiGet<{ vehicles: EnrichedArticle['compatible_vehicles'] }>(`${BASE}/articles/${articleId}/linkages`)
}

// ─── Search ─────────────────────────────────────────────────────────

export async function multiSearch(query: string): Promise<MultiSearchResponse> {
  const result = await apiGet<PaginatedArticles | EnrichedArticle[]>(`${BASE}/articles`, { article_number: query })
  const articles = Array.isArray(result) ? result : (result).data ?? []
  return {
    articles: articles,
    detected_brand: null,
    search_methods_used: ['article_number'],
  }
}

export function searchByCriteria(request: CriteriaSearchRequest): Promise<PaginatedArticles> {
  return apiPost<PaginatedArticles>(`${BASE}/articles/search-by-criteria`, request)
}

// ─── Search Tree ────────────────────────────────────────────────────

export function getSearchTreeRoots(treeType: VehicleType = 'pc'): Promise<SearchTreeNode[]> {
  return apiGet<SearchTreeNode[]>(`${BASE}/search-tree/roots`, { tree_type: treeType })
}

export function getSearchTreeChildren(nodeId: string): Promise<SearchTreeNode[]> {
  return apiGet<SearchTreeNode[]>(`${BASE}/search-tree/${nodeId}/children`)
}

export function getSearchTreeArticles(
  nodeId: string,
  params: { cursor?: string; per_page?: number }
): Promise<PaginatedArticles> {
  return apiGet<PaginatedArticles>(`${BASE}/search-tree/${nodeId}/articles`, params)
}

// ─── Metadata ───────────────────────────────────────────────────────

export function getCriteriaMetadata(): Promise<CriteriaMetadata[]> {
  return apiGet<CriteriaMetadata[]>(`${BASE}/criteria`)
}

export function getSupplierBrands(): Promise<Supplier[]> {
  return apiGet<Supplier[]>(`${BASE}/suppliers`, { per_page: 'all' })
}

// ─── Inventory Actions ──────────────────────────────────────────────

export function addArticleToInventory(request: AddToInventoryRequest): Promise<{ id: string }> {
  return apiPost<{ id: string }>('/products', request)
}
