import { useTranslation } from 'react-i18next'
import { Check, X } from 'lucide-react'
import { cn } from '@/lib/utils'

interface PasswordStrengthProps {
  password: string
}

interface Requirement {
  key: string
  test: (pw: string) => boolean
}

const REQUIREMENTS: Requirement[] = [
  { key: 'minLength', test: (pw) => pw.length >= 10 },
  { key: 'lowercase', test: (pw) => /[a-z]/.test(pw) },
  { key: 'uppercase', test: (pw) => /[A-Z]/.test(pw) },
  { key: 'number', test: (pw) => /[0-9]/.test(pw) },
  { key: 'symbol', test: (pw) => /[^a-zA-Z0-9]/.test(pw) },
]

export function PasswordStrength({ password }: PasswordStrengthProps) {
  const { t } = useTranslation(['auth'])

  if (!password) return null

  const met = REQUIREMENTS.filter((r) => r.test(password)).length
  const total = REQUIREMENTS.length

  let color: string
  if (met <= 1) {
    color = 'bg-red-500'
  } else if (met <= 3) {
    color = 'bg-yellow-500'
  } else {
    color = 'bg-green-500'
  }

  return (
    <div className="mt-2 space-y-2">
      {/* Strength bar */}
      <div className="flex gap-1">
        {Array.from({ length: total }, (_, i) => (
          <div
            key={i}
            className={cn(
              'h-1 flex-1 rounded-full transition-colors',
              i < met ? color : 'bg-gray-200'
            )}
          />
        ))}
      </div>

      {/* Requirements checklist */}
      <ul className="space-y-0.5">
        {REQUIREMENTS.map((req) => {
          const passed = req.test(password)
          return (
            <li key={req.key} className="flex items-center gap-1.5 text-xs">
              {passed ? (
                <Check className="h-3 w-3 text-green-500" />
              ) : (
                <X className="h-3 w-3 text-gray-300" />
              )}
              <span className={passed ? 'text-green-600' : 'text-gray-400'}>
                {t(`auth:passwordStrength.${req.key}`)}
              </span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
