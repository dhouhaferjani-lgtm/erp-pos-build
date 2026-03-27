export type GrowthStage = 'launch' | 'stabilize' | 'optimize' | 'expand'
export type MilestoneStatus = 'pending' | 'in_progress' | 'completed' | 'skipped'
export type ModuleReadinessStatus = 'locked' | 'available' | 'ready' | 'active'
export type RecommendationPriority = 'high' | 'medium' | 'low'
export type RecommendationStatus = 'pending' | 'accepted' | 'dismissed'

export interface CompanyProfile {
  id: string
  tenant_id: string
  vertical: string
  country: string
  current_stage: GrowthStage
  stage_progress_percent: number
  total_milestones: number
  completed_milestones: number
}

export interface Milestone {
  id: string
  name: string
  description: string
  status: MilestoneStatus
  progress_percent: number
  stage: string
}

export interface ModuleReadiness {
  id: string
  name: string
  description: string
  icon: string
  status: ModuleReadinessStatus
  readiness_percent: number
  stage: string
  discount_percent: number
  requirements: string[]
}

export interface Recommendation {
  id: string
  title: string
  description: string
  priority: RecommendationPriority
  action_label: string
  action_route: string
  status: RecommendationStatus
}
