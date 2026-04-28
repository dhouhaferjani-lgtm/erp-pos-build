import { ModuleCard } from './ModuleCard'
import type { ModuleReadiness } from '../api/types'

interface ModulesGridViewProps {
  modules: ModuleReadiness[]
}

export function ModulesGridView({ modules }: ModulesGridViewProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      {modules.map((mod) => (
        <ModuleCard key={mod.id} module={mod} />
      ))}
    </div>
  )
}
