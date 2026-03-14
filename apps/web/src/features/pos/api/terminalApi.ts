import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

export interface Location {
  id: string
  name: string
  code: string
}

export interface Terminal {
  id: string
  type: 'web' | 'physical'
  code: string
  name: string
  description: string | null
  location_id: string
  location?: Location | undefined
  is_active: boolean
  activated_at: string | null
  deactivated_at: string | null
  deactivation_reason: string | null
  has_history: boolean
  current_sequence: number
  current_year: number
  created_at: string
  updated_at: string
}

export interface CreateTerminalInput {
  code?: string | undefined
  name: string
  location_id: string
  description?: string | undefined
}

export interface UpdateTerminalInput {
  name?: string | undefined
  location_id?: string | undefined
  description?: string | undefined
}

export interface DeactivateTerminalInput {
  reason?: string | undefined
}

/**
 * Fetch all terminals for the current company
 */
export async function fetchTerminals(): Promise<Terminal[]> {
  return apiGet<Terminal[]>('/pos/terminals')
}

/**
 * Fetch a single terminal by ID
 */
export async function fetchTerminal(id: string): Promise<Terminal> {
  return apiGet<Terminal>(`/pos/terminals/${id}`)
}

/**
 * Create a new terminal
 */
export async function createTerminal(data: CreateTerminalInput): Promise<Terminal> {
  return apiPost<Terminal>('/pos/terminals', data)
}

/**
 * Update an existing terminal
 */
export async function updateTerminal(id: string, data: UpdateTerminalInput): Promise<Terminal> {
  return apiPatch<Terminal>(`/pos/terminals/${id}`, data)
}

/**
 * Archive a terminal (soft delete — data preserved)
 */
export async function archiveTerminal(id: string): Promise<void> {
  return apiPatch(`/pos/terminals/${id}/archive`)
}

/**
 * Permanently delete a terminal (only when it has no receipts)
 */
export async function deleteTerminal(id: string): Promise<void> {
  return apiDelete(`/pos/terminals/${id}`)
}

/**
 * Activate a terminal
 */
export async function activateTerminal(id: string): Promise<Terminal> {
  return apiPatch<Terminal>(`/pos/terminals/${id}/activate`)
}

/**
 * Deactivate a terminal
 */
export async function deactivateTerminal(
  id: string,
  data?: DeactivateTerminalInput
): Promise<Terminal> {
  return apiPatch<Terminal>(`/pos/terminals/${id}/deactivate`, data)
}

/**
 * Get or create the web terminal for a given location.
 * Returns the existing web terminal if one exists, otherwise creates one.
 */
export async function getOrCreateWebTerminal(locationId: string): Promise<Terminal> {
  return apiPost<Terminal>('/pos/terminals/web', { location_id: locationId })
}
