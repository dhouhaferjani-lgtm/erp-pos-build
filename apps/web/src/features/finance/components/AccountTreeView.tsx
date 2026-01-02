import { useState } from 'react'
import { ChevronRight, ChevronDown, Edit } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCompany } from '@/hooks/useCompany'
import { formatCurrency } from '@/lib/formatCurrency'
import type { Account } from '../types'

interface AccountTreeViewProps {
  accounts: Account[]
  onEdit: (account: Account) => void
}

export function AccountTreeView({ accounts, onEdit }: AccountTreeViewProps) {
  const { i18n } = useTranslation()
  const { currentCompany } = useCompany()
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set())

  // Build hierarchy
  const rootAccounts = accounts.filter((a) => !a.parent_id)
  const childrenMap = new Map<string, Account[]>()

  accounts.forEach((account) => {
    if (account.parent_id) {
      const children = childrenMap.get(account.parent_id) || []
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
    const children = childrenMap.get(account.id) || []
    const hasChildren = children.length > 0
    const isExpanded = expandedIds.has(account.id)

    return (
      <div key={account.id}>
        <div
          className="flex items-center gap-2 px-4 py-3 hover:bg-gray-50 border-b border-gray-100"
          style={{ paddingLeft: `${String(level * 2 + 1)}rem` }}
        >
          <button
            onClick={() => {
              if (hasChildren) {
                toggleExpand(account.id)
              }
            }}
            className="flex items-center justify-center w-5 h-5"
            disabled={!hasChildren}
            data-testid={`expand-${account.id}`}
          >
            {hasChildren ? (
              isExpanded ? (
                <ChevronDown className="h-4 w-4 text-gray-400" />
              ) : (
                <ChevronRight className="h-4 w-4 text-gray-400" />
              )
            ) : (
              <span className="w-4" />
            )}
          </button>

          <div className="flex-1 flex items-center gap-4">
            <span className="font-mono text-sm text-gray-600 w-20">{account.code}</span>
            <span className="font-medium text-gray-900">{account.name}</span>
            <span className="text-sm text-gray-500 capitalize">{account.type}</span>
          </div>

          <div className="flex items-center gap-4">
            <span className="font-mono text-sm text-gray-900">
              {currentCompany ? formatCurrency(account.balance, currentCompany.currency, i18n.language) : account.balance}
            </span>
            {!account.is_system && (
              <button
                onClick={() => { onEdit(account); }}
                className="p-1.5 text-gray-400 hover:text-blue-600 transition-colors"
                data-testid={`edit-${account.id}`}
              >
                <Edit className="h-4 w-4" />
              </button>
            )}
          </div>
        </div>

        {hasChildren && isExpanded && children.map((child) => renderAccount(child, level + 1))}
      </div>
    )
  }

  if (accounts.length === 0) {
    return (
      <div className="px-6 py-12 text-center text-sm text-gray-500">
        No accounts found. Create your first account to get started.
      </div>
    )
  }

  return <div className="divide-y divide-gray-100">{rootAccounts.map((account) => renderAccount(account))}</div>
}
