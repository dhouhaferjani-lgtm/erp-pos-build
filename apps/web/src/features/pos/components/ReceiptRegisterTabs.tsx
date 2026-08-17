import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface ReceiptRegisterTabsProps {
  active: 'receipts' | 'refunds'
}

export function ReceiptRegisterTabs({ active }: ReceiptRegisterTabsProps) {
  const { t } = useTranslation('pos')
  const tabs = [
    { key: 'receipts' as const, to: '/pos/receipts', label: t('receiptReporting.tabs.receipts') },
    { key: 'refunds' as const, to: '/pos/receipts/refunds', label: t('receiptReporting.tabs.refunds') },
  ]

  return (
    <nav
      aria-label={t('receiptReporting.tabs.label')}
      className={cn('mb-5 flex border-b', colorTokens.border.subtle)}
      role="tablist"
    >
      {tabs.map((tab) => {
        const selected = active === tab.key
        return (
          <NavLink
            key={tab.key}
            to={tab.to}
            role="tab"
            aria-selected={selected}
            className={cn(
              'border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
              selected
                ? `${colorTokens.intent.primary.borderStrong} ${colorTokens.intent.primary.textStrong}`
                : `border-transparent ${colorTokens.text.subtle} ${colorTokens.variants.hoverTextGray700}`,
            )}
          >
            {tab.label}
          </NavLink>
        )
      })}
    </nav>
  )
}
