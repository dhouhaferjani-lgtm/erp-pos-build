import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

// ─── Types ───────────────────────────────────────────────────────────────────

export interface FloorData {
  id: string
  name: string
  position: number
  is_active: boolean
  tables?: TableData[]
  created_at: string
  updated_at: string
}

export interface TableData {
  id: string
  floor_id: string | null
  table_number: string
  label: string | null
  seats: number
  status: 'available' | 'occupied' | 'reserved' | 'cleaning'
  shape: string | null
  position_x: string | null
  position_y: string | null
  width: string | null
  height: string | null
  current_order_id: string | null
  floor?: FloorData
  created_at: string
  updated_at: string
}

export interface CreateFloorRequest {
  name: string
  position?: number
}

export interface UpdateFloorRequest {
  name?: string
  position?: number
  is_active?: boolean
}

export interface CreateTableRequest {
  floor_id?: string | null
  table_number: string
  label?: string | null
  seats?: number
  shape?: string | null
}

export interface UpdateTableRequest {
  floor_id?: string | null
  table_number?: string
  label?: string | null
  seats?: number
  shape?: string | null
}

export interface TableListParams {
  floor_id?: string
  status?: string
}

// ─── API Functions ───────────────────────────────────────────────────────────

export async function getFloors(): Promise<FloorData[]> {
  return apiGet<FloorData[]>('/pos/floors')
}

export async function createFloor(data: CreateFloorRequest): Promise<FloorData> {
  return apiPost<FloorData>('/pos/floors', data)
}

export async function updateFloor(id: string, data: UpdateFloorRequest): Promise<FloorData> {
  return apiPatch<FloorData>(`/pos/floors/${id}`, data)
}

export async function deleteFloor(id: string): Promise<void> {
  await apiDelete(`/pos/floors/${id}`)
}

export async function getTables(params?: TableListParams): Promise<TableData[]> {
  const searchParams = new URLSearchParams()
  if (params?.floor_id) searchParams.set('floor_id', params.floor_id)
  if (params?.status) searchParams.set('status', params.status)

  const queryString = searchParams.toString()
  const url = queryString ? `/pos/tables?${queryString}` : '/pos/tables'

  return apiGet<TableData[]>(url)
}

export async function createTable(data: CreateTableRequest): Promise<TableData> {
  return apiPost<TableData>('/pos/tables', data)
}

export async function updateTable(id: string, data: UpdateTableRequest): Promise<TableData> {
  return apiPatch<TableData>(`/pos/tables/${id}`, data)
}

export async function deleteTable(id: string): Promise<void> {
  await apiDelete(`/pos/tables/${id}`)
}

export async function releaseTable(id: string): Promise<TableData> {
  return apiPost<TableData>(`/pos/tables/${id}/release`)
}

export async function setTableStatus(
  id: string,
  status: string
): Promise<TableData> {
  return apiPost<TableData>(`/pos/tables/${id}/status`, { status })
}
