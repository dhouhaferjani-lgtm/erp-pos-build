import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, spacing, textColors } from '@/lib/designTokens'

interface OwnerTableFrameProps {
  title: string
  children: ReactNode
  isEmpty: boolean
}

export function OwnerTableFrame({ title, children, isEmpty }: OwnerTableFrameProps) {
  const { t } = useTranslation(['reports'])

  return (
    <section className={`rounded-lg border ${borderColors.light} ${colors.white} ${spacing.md}`}>
      <h3 className={`mb-4 text-lg font-medium ${textColors.primary}`}>{title}</h3>
      {isEmpty ? <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.noData')}</p> : children}
    </section>
  )
}
