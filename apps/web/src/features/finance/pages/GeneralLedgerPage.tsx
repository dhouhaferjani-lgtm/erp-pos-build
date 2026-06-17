import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'
import { useLedger } from '../hooks/useLedger'
import { useAccounts } from '../hooks/useAccounts'
import { LedgerFilters } from '../components/LedgerFilters'
import { LedgerTable } from '../components/LedgerTable'
import { QueryError } from '@/components/QueryError'
import { Button } from '../../../components/atoms'
import { ListPageLayout } from '../../../components/molecules'
import type { LedgerFilters as LedgerFiltersType } from '../types'

export function GeneralLedgerPage() {
  const { t } = useTranslation(['finance'])
  const [filters, setFilters] = useState<LedgerFiltersType>({})
  const { data: ledgerData, isLoading, error, refetch } = useLedger(filters)
  const { data: accounts } = useAccounts()

  const handleExport = () => {
    // Export functionality to be implemented
  }

  if (error) {
    return (
      <QueryError
        error={error}
        onRetry={refetch}
        title={t('finance:ledger.loadError')}
      />
    )
  }

  return (
    <ListPageLayout
      title={t('finance:ledger.title')}
      subtitle={t('finance:ledger.description')}
      actions={
        <Button variant="secondary" className="gap-2" onClick={handleExport}>
          <Download className="h-4 w-4" />
          {t('finance:reports.common.export')}
        </Button>
      }
      filters={
        <LedgerFilters
          filters={filters}
          accounts={accounts ?? []}
          onFiltersChange={setFilters}
        />
      }
    >
      <LedgerTable lines={ledgerData?.lines ?? []} isLoading={isLoading} />
    </ListPageLayout>
  )
}
