import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

interface PasswordStrengthProps {
  password: string
}

export function PasswordStrength({ password }: PasswordStrengthProps) {
  const { t } = useTranslation(['auth'])

  if (!password) return null

  const length = password.length
  let level: 1 | 2 | 3
  let color: string
  let label: string

  if (length < 8) {
    level = 1
    color = 'bg-red-500'
    label = t('auth:passwordStrength.weak')
  } else if (length < 12) {
    level = 2
    color = 'bg-yellow-500'
    label = t('auth:passwordStrength.fair')
  } else {
    level = 3
    color = 'bg-green-500'
    label = t('auth:passwordStrength.strong')
  }

  return (
    <div className="mt-2">
      <div className="flex gap-1">
        {[1, 2, 3].map((segment) => (
          <div
            key={segment}
            className={cn(
              'h-1 flex-1 rounded-full transition-colors',
              segment <= level ? color : 'bg-gray-200'
            )}
          />
        ))}
      </div>
      <p className="mt-1 text-xs text-gray-500">{label}</p>
    </div>
  )
}
