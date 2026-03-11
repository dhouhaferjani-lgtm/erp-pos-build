import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { LoyaltyProgram, CreateProgramData, UpdateProgramData } from '../types/loyalty'

export async function listPrograms(): Promise<LoyaltyProgram[]> {
  return apiGet<LoyaltyProgram[]>('/loyalty/programs')
}

export async function getProgram(id: string): Promise<LoyaltyProgram> {
  return apiGet<LoyaltyProgram>(`/loyalty/programs/${id}`)
}

export async function createProgram(data: CreateProgramData): Promise<LoyaltyProgram> {
  return apiPost<LoyaltyProgram>('/loyalty/programs', data)
}

export async function updateProgram(id: string, data: UpdateProgramData): Promise<LoyaltyProgram> {
  return apiPatch<LoyaltyProgram>(`/loyalty/programs/${id}`, data)
}

export async function deleteProgram(id: string): Promise<void> {
  return apiDelete(`/loyalty/programs/${id}`)
}

export async function activateProgram(id: string): Promise<LoyaltyProgram> {
  return apiPost<LoyaltyProgram>(`/loyalty/programs/${id}/activate`)
}

export async function deactivateProgram(id: string): Promise<LoyaltyProgram> {
  return apiPost<LoyaltyProgram>(`/loyalty/programs/${id}/deactivate`)
}

export async function listActivePrograms(): Promise<LoyaltyProgram[]> {
  return apiGet<LoyaltyProgram[]>('/loyalty/programs/active')
}
