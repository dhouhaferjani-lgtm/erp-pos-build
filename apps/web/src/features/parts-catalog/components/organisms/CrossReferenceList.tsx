import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Copy } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { CrossReference, CrossReferenceType } from '../../types/catalog'

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
        <h3 className="text-sm font-semibold text-gray-900 mb-3">
          {t('parts-catalog:article.crossReferences')}
        </h3>
        <p className="text-sm text-gray-400">{t('parts-catalog:crossRef.noCrossRefs')}</p>
      </div>
    )
  }

  const handleCopy = (text: string) => {
    void navigator.clipboard.writeText(text)
  }

  return (
    <div className={cn('', className)}>
      <h3 className="text-sm font-semibold text-gray-900 mb-3">
        {t('parts-catalog:article.crossReferences')}
      </h3>
      <div className="space-y-4">
        {TYPE_ORDER.filter((type) => grouped.has(type)).map((type) => {
          const refs = grouped.get(type)
          if (!refs) return null

          return (
            <div key={type}>
              <h4 className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">
                {t(`parts-catalog:crossRef.${type}`)}
              </h4>
              <div className="space-y-1">
                {refs.map((ref, i) => (
                  <div
                    key={`${ref.reference_number}-${String(i)}`}
                    className="group flex items-center justify-between rounded-md bg-gray-50 px-3 py-2"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <code className="text-sm font-mono text-gray-800 truncate">
                        {ref.reference_number}
                      </code>
                      {ref.manufacturer_name && (
                        <span className="text-xs text-gray-400 truncate">
                          {ref.manufacturer_name}
                        </span>
                      )}
                    </div>
                    <button
                      type="button"
                      onClick={() => { handleCopy(ref.reference_number) }}
                      className="opacity-0 group-hover:opacity-100 p-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition-all"
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
