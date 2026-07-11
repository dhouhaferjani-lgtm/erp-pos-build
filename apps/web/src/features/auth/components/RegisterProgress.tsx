import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface RegisterProgressProps {
  currentStep: number
  totalSteps: number
}

export function RegisterProgress({ currentStep, totalSteps }: RegisterProgressProps) {
  return (
    <div
      role="progressbar"
      aria-valuenow={currentStep}
      aria-valuemin={1}
      aria-valuemax={totalSteps}
      className="mb-8 flex gap-1.5"
    >
      {Array.from({ length: totalSteps }, (_, i) => (
        <div
          key={i}
          className={cn(
            'h-1 flex-1 rounded-full transition-colors',
            i < currentStep ? `${colorTokens.intent.primary.bg}` : `${colorTokens.surface.subdued}`
          )}
        />
      ))}
    </div>
  )
}
