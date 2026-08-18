import { Construction } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface EmptyStateProps {
  title: string
  description?: string
  icon?: React.ReactNode
  action?: React.ReactNode
}

export function EmptyState({ title, description, icon, action }: EmptyStateProps) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-col items-center justify-center min-h-96 text-center">
      {icon ?? <Construction className={`h-16 w-16 ${colorTokens.text.disabled} mb-4`} />}
      <h1 className={`text-2xl font-semibold ${colorTokens.text.primary} mb-2`}>{title}</h1>
      <p className={`${colorTokens.text.subtle} max-w-md`}>
        {description ?? t('common.noData')}
      </p>
      {action ? <div className="mt-4">{action}</div> : null}
    </div>
  )
}

// Backwards compatibility alias
export { EmptyState as PlaceholderPage }
