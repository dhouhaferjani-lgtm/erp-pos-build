import { useState } from 'react'
import { ChevronRight, ChevronDown, Edit } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCompany } from '@/hooks/useCompany'
import { formatCurrency } from '@/lib/formatCurrency'
import { Button, StatusBadge } from '@/components/atoms'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { Account } from '../types'

interface AccountTreeViewProps {
  accounts: Account[]
  onEdit: (account: Account) => void
}

export function AccountTreeView({ accounts, onEdit }: AccountTreeViewProps) {
  const { t, i18n } = useTranslation(['finance'])
  const { currentCompany } = useCompany()
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set())

  // Build hierarchy
  const rootAccounts = accounts.filter((a) => !a.parent_id)
  const childrenMap = new Map<string, Account[]>()

  accounts.forEach((account) => {
    if (account.parent_id) {
      const children = childrenMap.get(account.parent_id) ?? []
      children.push(account)
      childrenMap.set(account.parent_id, children)
    }
  })

  const toggleExpand = (id: string) => {
    const newExpanded = new Set(expandedIds)
    if (newExpanded.has(id)) {
      newExpanded.delete(id)
    } else {
      newExpanded.add(id)
    }
    setExpandedIds(newExpanded)
  }

  const renderAccount = (account: Account, level = 0) => {
    const children = childrenMap.get(account.id) ?? []
    const hasChildren = children.length > 0
    const isExpanded = expandedIds.has(account.id)

    return (
      <div key={account.id}>
        <div
          className={cn('flex items-center gap-2 px-4 py-3 border-b', tokens.table.rowHover, borderColors.light)}
          style={{ paddingLeft: `${String(level * 2 + 1)}rem` }}
        >
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              if (hasChildren) {
                toggleExpand(account.id)
              }
            }}
            className="h-5 w-5 p-0"
            disabled={!hasChildren}
            data-testid={`expand-${account.id}`}
          >
            {hasChildren ? (
              isExpanded ? (
                <ChevronDown className={cn('h-4 w-4', textColors.disabled)} />
              ) : (
                <ChevronRight className={cn('h-4 w-4', textColors.disabled)} />
              )
            ) : (
              <span className="w-4" />
            )}
          </Button>

          <div className="flex-1 flex items-center gap-4">
            <span className={cn('font-mono text-sm w-20', textColors.tertiary)}>{account.code}</span>
            <span className={cn('font-medium', textColors.primary)}>{account.name}</span>
            <StatusBadge tone="neutral">
              {t(`finance:chartOfAccounts.account.types.${account.type}`)}
            </StatusBadge>
          </div>

          <div className="flex items-center gap-4">
            <span className={cn('font-mono text-sm text-right tabular-nums', textColors.primary)}>
              {currentCompany ? formatCurrency(account.balance, currentCompany.currency, i18n.language) : account.balance}
            </span>
            {!account.is_system && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => { onEdit(account) }}
                className="p-1.5"
                data-testid={`edit-${account.id}`}
              >
                <Edit className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>

        {hasChildren && isExpanded && children.map((child) => renderAccount(child, level + 1))}
      </div>
    )
  }

  if (accounts.length === 0) {
    return (
      <div className={cn('px-6 py-12 text-center text-sm', textColors.tertiary)}>
        {t('finance:chartOfAccounts.emptyState')}
      </div>
    )
  }

  return <div className={cn('divide-y', borderColors.divideLight)}>{rootAccounts.map((account) => renderAccount(account))}</div>
}
