import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { EditorSectionCard } from '../editor/components/EditorSectionCard'
import type { ProductSectionMode } from './types'

export interface ProductSuppliersAdapter {
  mode: ProductSectionMode
}

export function ProductSuppliersSection({ adapter: _adapter }: { adapter: ProductSuppliersAdapter }) {
  const { t } = useTranslation('catalog')

  return (
    <EditorSectionCard
      id="section-suppliers"
      title={t('catalog:editor.sectionLabels.suppliers')}
      contentClassName="sm:grid-cols-1"
    >
      <p className={cn('text-sm', textColors.tertiary)}>
        {t('catalog:editor.suppliers.managedHint')}
      </p>
      <Link
        to="/purchases/suppliers"
        className={cn('inline-flex items-center gap-1 text-sm', textColors.brand)}
      >
        {t('catalog:editor.suppliers.manageLink')}
      </Link>
    </EditorSectionCard>
  )
}
