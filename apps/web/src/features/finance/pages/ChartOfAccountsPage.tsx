import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { useAccounts } from '../hooks/useAccounts'
import { AccountTreeView } from '../components/AccountTreeView'
import { AddAccountModal } from '../components/AddAccountModal'
import { EditAccountModal } from '../components/EditAccountModal'
import { QueryError } from '@/components/QueryError'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import type { Account } from '../types'

export function ChartOfAccountsPage() {
  const { t } = useTranslation(['finance', 'common'])
  const { data: accounts, isLoading, error, refetch } = useAccounts()
  const [isAddModalOpen, setIsAddModalOpen] = useState(false)
  const [editingAccount, setEditingAccount] = useState<Account | null>(null)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <p className={textColors.tertiary}>{t('common:common.loading')}</p>
      </div>
    )
  }

  if (error) {
    return (
      <QueryError
        error={error}
        onRetry={refetch}
        title={t('finance:chartOfAccounts.loadError')}
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>{t('finance:chartOfAccounts.title')}</h1>
          <p className={textColors.tertiary}>{t('finance:chartOfAccounts.description')}</p>
        </div>
        <button
          onClick={() => { setIsAddModalOpen(true); }}
          className={`inline-flex items-center gap-2 rounded-lg ${tokens.button.primary} px-4 py-2 text-sm font-medium transition-colors`}
        >
          <Plus className="h-4 w-4" />
          {t('finance:chartOfAccounts.addAccount')}
        </button>
      </div>

      <div className={`bg-white rounded-lg border ${borderColors.light}`}>
        <AccountTreeView
          accounts={accounts || []}
          onEdit={(account) => { setEditingAccount(account); }}
        />
      </div>

      <AddAccountModal
        open={isAddModalOpen}
        onClose={() => { setIsAddModalOpen(false); }}
        onSuccess={() => { setIsAddModalOpen(false); }}
      />

      {editingAccount && (
        <EditAccountModal
          account={editingAccount}
          open={!!editingAccount}
          onClose={() => { setEditingAccount(null); }}
          onSuccess={() => { setEditingAccount(null); }}
        />
      )}
    </div>
  )
}
