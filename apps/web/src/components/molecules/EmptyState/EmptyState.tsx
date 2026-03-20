import { Construction } from 'lucide-react'
import { useTranslation } from 'react-i18next'

interface EmptyStateProps {
  title: string
  description?: string
  icon?: React.ReactNode
}

export function EmptyState({ title, description, icon }: EmptyStateProps) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-col items-center justify-center min-h-96 text-center">
      {icon ?? <Construction className="h-16 w-16 text-gray-400 mb-4" />}
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">{title}</h1>
      <p className="text-gray-500 max-w-md">
        {description ?? t('common.noData')}
      </p>
    </div>
  )
}

// Backwards compatibility alias
export { EmptyState as PlaceholderPage }
