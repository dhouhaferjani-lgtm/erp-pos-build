import { api, apiDelete, apiGet, apiPatch, apiPost } from '@/lib/api'

export type LocationNode = App.Modules.Inventory.Application.DTOs.LocationNodeDto
export type ProductPlacement = App.Modules.Inventory.Application.DTOs.ProductPlacementDto
export type LocationNodeType = App.Modules.Inventory.Domain.Enums.LocationNodeType

export interface NodeInput {
  location_id: string
  parent_id: string | null
  node_type: LocationNodeType
  name: string
  code: string
  sort_order: number
  is_active: boolean
}

export interface NodeProductsPage {
  data: ProductPlacement[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export function listLocationNodes(locationId: string, includeDeleted = false): Promise<LocationNode[]> {
  return apiGet<LocationNode[]>(`/inventory/locations/${locationId}/nodes`, includeDeleted ? { include_deleted: 1 } : undefined)
}

export function createLocationNode(input: NodeInput): Promise<LocationNode> {
  return apiPost<LocationNode>('/inventory/nodes', input)
}

export function updateLocationNode(
  nodeId: string,
  input: Partial<Omit<NodeInput, 'location_id' | 'parent_id'>>,
): Promise<LocationNode> {
  return apiPatch<LocationNode>(`/inventory/nodes/${nodeId}`, input)
}

export function moveLocationNode(nodeId: string, parentId: string | null): Promise<LocationNode> {
  return apiPost<LocationNode>(`/inventory/nodes/${nodeId}/move`, { parent_id: parentId })
}

export function deleteLocationNode(nodeId: string, force = false): Promise<unknown> {
  return apiDelete<unknown>(`/inventory/nodes/${nodeId}${force ? '?force=1' : ''}`)
}

export function restoreLocationNode(nodeId: string): Promise<LocationNode> {
  return apiPost<LocationNode>(`/inventory/nodes/${nodeId}/restore`)
}

export async function listNodeProducts(
  nodeId: string,
  search: string,
  page: number,
): Promise<NodeProductsPage> {
  const response = await api.get<NodeProductsPage>(`/inventory/nodes/${nodeId}/products`, {
    params: { search, page, per_page: 25 },
  })
  return response.data
}

export function assignProducts(nodeId: string, productIds: string[]): Promise<ProductPlacement[]> {
  return apiPost<ProductPlacement[]>(`/inventory/nodes/${nodeId}/assign-products`, {
    product_ids: productIds,
  })
}

export function unassignProduct(nodeId: string, productId: string): Promise<unknown> {
  return apiDelete<unknown>(`/inventory/nodes/${nodeId}/products/${productId}`)
}

export function bulkMoveProducts(nodeId: string, productIds: string[]): Promise<{ moved: number }> {
  return apiPost<{ moved: number }>('/inventory/placements/bulk-move', {
    node_id: nodeId,
    product_ids: productIds,
  })
}
