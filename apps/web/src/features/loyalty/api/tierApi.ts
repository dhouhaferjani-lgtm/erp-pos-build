import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { Tier, CreateTierData, UpdateTierData } from '../types/loyalty'

export async function listTiers(programId: string): Promise<Tier[]> {
  return apiGet<Tier[]>(`/loyalty/programs/${programId}/tiers`)
}

export async function getTier(id: string): Promise<Tier> {
  return apiGet<Tier>(`/loyalty/tiers/${id}`)
}

export async function createTier(programId: string, data: CreateTierData): Promise<Tier> {
  return apiPost<Tier>(`/loyalty/programs/${programId}/tiers`, data)
}

export async function updateTier(id: string, data: UpdateTierData): Promise<Tier> {
  return apiPatch<Tier>(`/loyalty/tiers/${id}`, data)
}

export async function deleteTier(id: string): Promise<void> {
  return apiDelete(`/loyalty/tiers/${id}`)
}
