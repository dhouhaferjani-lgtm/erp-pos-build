// Pages
export { GrowthPage } from './pages/GrowthPage'
export { ModulesPage } from './pages/ModulesPage'

// Components
export { GrowthDashboard } from './components/GrowthDashboard'
export { ModulesCatalog } from './components/ModulesCatalog'
export { StageIndicatorBadge } from './components/StageIndicatorBadge'
export { RecommendationCard } from './components/RecommendationCard'
export { MilestoneItem } from './components/MilestoneItem'
export { ModuleCard } from './components/ModuleCard'

// Hooks
export { useCompanyProfile, useMilestones, progressionKeys } from './hooks/useCompanyProgression'
export { useModules, useActivateModule } from './hooks/useModuleReadiness'
export { useRecommendations, useAcceptRecommendation, useDismissRecommendation } from './hooks/useRecommendations'

// Types
export type {
  GrowthStage,
  MilestoneStatus,
  ModuleReadinessStatus,
  RecommendationPriority,
  CompanyProfile,
  Milestone,
  ModuleReadiness,
  Recommendation,
} from './api/types'
