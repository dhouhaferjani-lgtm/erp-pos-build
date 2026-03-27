import { apiGet, apiPost } from '@/lib/api'
import type {
  CompanyProfile,
  Milestone,
  ModuleReadiness,
  Recommendation,
} from './types'

const BASE = '/progression'

export const progressionApi = {
  getProfile: () => apiGet<CompanyProfile>(`${BASE}/profile`),
  register: () => apiPost<CompanyProfile>(`${BASE}/register`),
  getMilestones: () => apiGet<Milestone[]>(`${BASE}/milestones`),
  getModules: () => apiGet<ModuleReadiness[]>(`${BASE}/modules`),
  activateModule: (moduleId: string) => apiPost<ModuleReadiness>(`${BASE}/modules/${moduleId}/activate`),
  getRecommendations: () => apiGet<Recommendation[]>(`${BASE}/recommendations`),
  acceptRecommendation: (id: string) => apiPost<Recommendation>(`${BASE}/recommendations/${id}/accept`),
  dismissRecommendation: (id: string) => apiPost<Recommendation>(`${BASE}/recommendations/${id}/dismiss`),
}
