import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { StampCard, CreateStampCardData, UpdateStampCardData } from '../types/loyalty'

export async function listStampCards(programId: string): Promise<StampCard[]> {
  return apiGet<StampCard[]>(`/loyalty/programs/${programId}/stamp-cards`)
}

export async function getStampCard(id: string): Promise<StampCard> {
  return apiGet<StampCard>(`/loyalty/stamp-cards/${id}`)
}

export async function createStampCard(programId: string, data: CreateStampCardData): Promise<StampCard> {
  return apiPost<StampCard>(`/loyalty/programs/${programId}/stamp-cards`, data)
}

export async function updateStampCard(id: string, data: UpdateStampCardData): Promise<StampCard> {
  return apiPatch<StampCard>(`/loyalty/stamp-cards/${id}`, data)
}

export async function deleteStampCard(id: string): Promise<void> {
  return apiDelete(`/loyalty/stamp-cards/${id}`)
}
