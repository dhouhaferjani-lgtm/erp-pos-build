import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Copy } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { CrossReference, CrossReferenceType } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CrossReferenceListProps {
  crossReferences: CrossReference[]
  className?: string
}

const TYPE_ORDER: CrossReferenceType[] = ['oe', 'oem', 'iam', 'ean']

export function CrossReferenceList({ crossReferences, className }: CrossReferenceListProps) {
  const { t } = useTranslation(['parts-catalog'])

  const grouped = useMemo(() => {
    const groups = new Map<CrossReferenceType, CrossReference[]>()
    for (const ref of crossReferences) {
      const existing = groups.get(ref.reference_type) ?? []
      existing.push(ref)
      groups.set(ref.reference_type, existing)
    }
    return groups
  }, [crossReferences])

  if (crossReferences.length === 0) {
    return (
      <div className={className}>
        <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
          {t('parts-catalog:article.crossReferences')}
        </h3>
        <p className={`text-sm ${colorTokens.text.disabled}`}>{t('parts-catalog:crossRef.noCrossRefs')}</p>
      </div>
    )
  }

  const handleCopy = (text: string) => {
    void navigator.clipboard.writeText(text)
  }

  return (
    <div className={cn('', className)}>
      <h3 className={`text-sm font-semibold ${colorTokens.text.primary} mb-3`}>
        {t('parts-catalog:article.crossReferences')}
      </h3>
      <div className="space-y-4">
        {TYPE_ORDER.filter((type) => grouped.has(type)).map((type) => {
          const refs = grouped.get(type)
          if (!refs) return null

          return (
            <div key={type}>
              <h4 className={`text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wide mb-1.5`}>
                {t(`parts-catalog:crossRef.${type}`)}
              </h4>
              <div className="space-y-1">
                {refs.map((ref, i) => (
                  <div
                    key={`${ref.reference_number}-${String(i)}`}
                    className={`group flex items-center justify-between rounded-md ${colorTokens.surface.page} px-3 py-2`}
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <code className={`text-sm font-mono ${colorTokens.text.strong} truncate`}>
                        {ref.reference_number}
                      </code>
                      {ref.manufacturer_name && (
                        <span className={`text-xs ${colorTokens.text.disabled} truncate`}>
                          {ref.manufacturer_name}
                        </span>
                      )}
                    </div>
                    <button
                      type="button"
                      onClick={() => { handleCopy(ref.reference_number) }}
                      className={`opacity-0 group-hover:opacity-100 p-1 rounded ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover} ${colorTokens.intent.neutral.bgHoverStrong} transition-all`}
                      title={t('parts-catalog:crossRef.copy')}
                    >
                      <Copy className="h-3.5 w-3.5" />
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
