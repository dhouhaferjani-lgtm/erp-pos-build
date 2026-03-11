import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type { EarningRule, CreateEarningRuleData, UpdateEarningRuleData } from '../types/loyalty'

export async function listEarningRules(programId: string): Promise<EarningRule[]> {
  return apiGet<EarningRule[]>(`/loyalty/programs/${programId}/earning-rules`)
}

export async function getEarningRule(id: string): Promise<EarningRule> {
  return apiGet<EarningRule>(`/loyalty/earning-rules/${id}`)
}

export async function createEarningRule(programId: string, data: CreateEarningRuleData): Promise<EarningRule> {
  return apiPost<EarningRule>(`/loyalty/programs/${programId}/earning-rules`, data)
}

export async function updateEarningRule(id: string, data: UpdateEarningRuleData): Promise<EarningRule> {
  return apiPatch<EarningRule>(`/loyalty/earning-rules/${id}`, data)
}

export async function deleteEarningRule(id: string): Promise<void> {
  return apiDelete(`/loyalty/earning-rules/${id}`)
}

export async function activateEarningRule(id: string): Promise<EarningRule> {
  return apiPost<EarningRule>(`/loyalty/earning-rules/${id}/activate`)
}

export async function deactivateEarningRule(id: string): Promise<EarningRule> {
  return apiPost<EarningRule>(`/loyalty/earning-rules/${id}/deactivate`)
}
