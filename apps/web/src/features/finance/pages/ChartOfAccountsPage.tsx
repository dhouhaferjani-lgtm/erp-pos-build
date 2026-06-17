import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { useAccounts } from '../hooks/useAccounts'
import { AccountTreeView } from '../components/AccountTreeView'
import { AddAccountModal } from '../components/AddAccountModal'
import { EditAccountModal } from '../components/EditAccountModal'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '@/components/molecules/PageHeader'
import { Button } from '@/components/atoms'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
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
      <PageHeader
        title={t('finance:chartOfAccounts.title')}
        subtitle={t('finance:chartOfAccounts.description')}
        className="mb-0"
        actions={
          <Button className="gap-2" onClick={() => { setIsAddModalOpen(true) }}>
            <Plus className="h-4 w-4" />
            {t('finance:chartOfAccounts.addAccount')}
          </Button>
        }
      />

      <div className={cn(colors.white, 'rounded-lg border', borderColors.light)}>
        <AccountTreeView
          accounts={accounts ?? []}
          onEdit={(account) => { setEditingAccount(account) }}
        />
      </div>

      <AddAccountModal
        open={isAddModalOpen}
        onClose={() => { setIsAddModalOpen(false) }}
        onSuccess={() => { setIsAddModalOpen(false) }}
      />

      {editingAccount && (
        <EditAccountModal
          account={editingAccount}
          open={!!editingAccount}
          onClose={() => { setEditingAccount(null) }}
          onSuccess={() => { setEditingAccount(null) }}
        />
      )}
    </div>
  )
}
