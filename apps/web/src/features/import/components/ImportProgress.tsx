import { Check } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

interface Step {
  key: string
  label: string
}

interface ImportProgressProps {
  steps: Step[]
  currentStep: number
  completedSteps: number[]
}

export function ImportProgress({
  steps,
  currentStep,
  completedSteps,
}: ImportProgressProps) {
  const { t } = useTranslation('import')

  return (
    <nav aria-label={t('wizard.progressAriaLabel')}>
      <ol className="flex items-center">
        {steps.map((step, index) => {
          const isCompleted = completedSteps.includes(index)
          const isCurrent = index === currentStep
          const isPast = index < currentStep

          return (
            <li
              key={step.key}
              className={cn(
                'relative',
                index !== steps.length - 1 && 'flex-1'
              )}
            >
              {/* Connector Line */}
              {index !== steps.length - 1 && (
                <div
                  className="absolute top-4 w-full"
                  style={{ left: '50%' }}
                >
                  <div
                    className={cn(
                      'h-0.5 w-full',
                      isPast || isCompleted ? 'bg-blue-600' : 'bg-gray-200'
                    )}
                  />
                </div>
              )}

              {/* Step Circle & Label */}
              <div className="relative flex flex-col items-center">
                <span
                  className={cn(
                    'flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium',
                    isCompleted && 'bg-blue-600 text-white',
                    isCurrent && !isCompleted && 'border-2 border-blue-600 bg-white text-blue-600',
                    !isCurrent && !isCompleted && 'border-2 border-gray-300 bg-white text-gray-500'
                  )}
                >
                  {isCompleted ? (
                    <Check className="h-4 w-4" />
                  ) : (
                    index + 1
                  )}
                </span>
                <span
                  className={cn(
                    'mt-2 text-xs font-medium',
                    isCurrent ? 'text-blue-600' : 'text-gray-500'
                  )}
                >
                  {step.label}
                </span>
              </div>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
