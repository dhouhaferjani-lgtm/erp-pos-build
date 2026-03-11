import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { Reward, CreateRewardData, UpdateRewardData } from '../types/loyalty'

export async function listRewards(programId: string): Promise<Reward[]> {
  return apiGet<Reward[]>(`/loyalty/programs/${programId}/rewards`)
}

export async function getReward(id: string): Promise<Reward> {
  return apiGet<Reward>(`/loyalty/rewards/${id}`)
}

export async function createReward(programId: string, data: CreateRewardData): Promise<Reward> {
  return apiPost<Reward>(`/loyalty/programs/${programId}/rewards`, data)
}

export async function updateReward(id: string, data: UpdateRewardData): Promise<Reward> {
  return apiPatch<Reward>(`/loyalty/rewards/${id}`, data)
}

export async function deleteReward(id: string): Promise<void> {
  return apiDelete(`/loyalty/rewards/${id}`)
}

export async function activateReward(id: string): Promise<Reward> {
  return apiPost<Reward>(`/loyalty/rewards/${id}/activate`)
}

export async function deactivateReward(id: string): Promise<Reward> {
  return apiPost<Reward>(`/loyalty/rewards/${id}/deactivate`)
}
