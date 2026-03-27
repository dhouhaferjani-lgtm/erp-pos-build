import { StageSection } from './StageSection'
import type { ModuleReadiness, GrowthStage } from '../api/types'

interface ModulesRoadmapViewProps {
  modules: ModuleReadiness[]
  currentStage: GrowthStage
}

const STAGES: GrowthStage[] = ['launch', 'stabilize', 'optimize', 'expand']

export function ModulesRoadmapView({ modules, currentStage }: ModulesRoadmapViewProps) {
  const groupedByStage = STAGES.map((stage) => ({
    stage,
    modules: modules.filter((m) => m.stage === stage),
  }))

  return (
    <div className="space-y-0">
      {groupedByStage.map(({ stage, modules: stageModules }) => (
        <StageSection
          key={stage}
          stage={stage}
          modules={stageModules}
          isCurrentStage={stage === currentStage}
        />
      ))}
    </div>
  )
}
