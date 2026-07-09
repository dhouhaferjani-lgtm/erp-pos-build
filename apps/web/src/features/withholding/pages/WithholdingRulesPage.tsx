import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Edit2, Trash2, CheckCircle, XCircle, AlertCircle } from 'lucide-react'
import { useWithholdingRules, useDeleteWithholdingRule } from '../hooks/useWithholding'
import { WithholdingRuleFormModal } from '../components/WithholdingRuleFormModal'
import { cn } from '@/lib/utils'
import { tokens, textColors } from '@/lib/designTokens'
import { formatNumber, formatPercent } from '@/lib/format'
import { Button, Select, StatusBadge } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '@/components/molecules'
import type { WithholdingRule } from '../types'

export function WithholdingRulesPage() {
  const { t } = useTranslation(['withholding', 'common'])
  const [selectedCountry, setSelectedCountry] = useState('TN')
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<WithholdingRule | null>(null)

  const { data: rules, isLoading, isError } = useWithholdingRules(selectedCountry)
  const deleteMutation = useDeleteWithholdingRule()

  const handleCreate = () => {
    setEditingRule(null)
    setIsModalOpen(true)
  }

  const handleEdit = (rule: WithholdingRule) => {
    setEditingRule(rule)
    setIsModalOpen(true)
  }

  const handleDelete = async (rule: WithholdingRule) => {
    if (!confirm(t('rules.confirmDelete', { name: rule.name }))) {
      return
    }

    try {
      await deleteMutation.mutateAsync(rule.id)
    } catch {
      // Error handled by mutation hook
    }
  }

  const handleCloseModal = () => {
    setIsModalOpen(false)
    setEditingRule(null)
  }

  const columns: DataTableColumn<WithholdingRule>[] = [
    {
      key: 'code',
      header: t('rules.code'),
      cellClassName: 'font-mono',
      render: (rule) => <span className={textColors.primary}>{rule.code}</span>,
    },
    {
      key: 'name',
      header: t('rules.name'),
      render: (rule) => <span className={textColors.primary}>{rule.name}</span>,
    },
    {
      key: 'transactionType',
      header: t('rules.transactionType'),
      render: (rule) => (
        <span className={textColors.tertiary}>
          {rule.transaction_type ? t(`transactionTypes.${rule.transaction_type}`) : '-'}
        </span>
      ),
    },
    {
      key: 'partnerRegime',
      header: t('rules.partnerRegime'),
      render: (rule) => (
        <span className={textColors.tertiary}>
          {rule.partner_tax_status ? t(`taxRegimes.${rule.partner_tax_status}`) : '-'}
        </span>
      ),
    },
    {
      key: 'rate',
      header: t('rules.rate'),
      numeric: true,
      cellClassName: cn('font-mono font-semibold', textColors.primary),
      render: (rule) => formatPercent(rule.rate_percentage),
    },
    {
      key: 'minAmount',
      header: t('rules.minAmount'),
      numeric: true,
      cellClassName: cn('font-mono', textColors.tertiary),
      render: (rule) => (rule.min_amount ? `${formatNumber(rule.min_amount, 0)} TND` : '-'),
    },
    {
      key: 'status',
      header: t('rules.status'),
      align: 'center',
      render: (rule) =>
        rule.is_active ? (
          <StatusBadge tone="success" className="gap-1">
            <CheckCircle className="h-3 w-3" />
            {t('common:active')}
          </StatusBadge>
        ) : (
          <StatusBadge tone="neutral" className="gap-1">
            <XCircle className="h-3 w-3" />
            {t('common:inactive')}
          </StatusBadge>
        ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:actions')}</span>,
      align: 'center',
      render: (rule) => (
        <div className="flex items-center justify-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            className="p-1"
            onClick={() => { handleEdit(rule) }}
            title={t('common:edit')}
          >
            <Edit2 className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="p-1"
            onClick={() => { void handleDelete(rule) }}
            title={t('common:delete')}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  if (isError) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <EmptyState
          icon={<AlertCircle className={cn('mx-auto h-12 w-12', textColors.error)} />}
          title={t('common:error')}
        />
      </div>
    )
  }

  return (
    <ListPageLayout
      title={t('rules.title')}
      subtitle={t('rules.subtitle')}
      actions={
        <Button className="gap-2" onClick={handleCreate}>
          <Plus className="h-4 w-4" />
          {t('rules.addRule')}
        </Button>
      }
      filters={
        <div className="w-full">
          <label className={cn('mb-2', tokens.label.base)}>{t('rules.countryFilter')}</label>
          <Select
            value={selectedCountry}
            onChange={(e) => { setSelectedCountry(e.target.value) }}
            className="w-64"
          >
            <option value="TN">{t('countries.tunisia')}</option>
            <option value="FR">{t('countries.france')}</option>
            <option value="MA">{t('countries.morocco')}</option>
            <option value="DZ">{t('countries.algeria')}</option>
          </Select>
        </div>
      }
    >
      <DataTable
        columns={columns}
        data={rules ?? []}
        keyExtractor={(rule) => rule.id}
        isLoading={isLoading}
        emptyState={
          <EmptyState
            icon={<AlertCircle className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('rules.noRules')}
          />
        }
      />

      {/* Info Panel */}
      <div className={cn('mt-6', tokens.alert.base, tokens.alert.info)}>
        <h3 className={cn('font-medium', textColors.brand)}>{t('rules.infoTitle')}</h3>
        <p className="mt-1">{t('rules.infoText')}</p>
      </div>

      {/* Rule Form Modal */}
      <WithholdingRuleFormModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        rule={editingRule}
        countryCode={selectedCountry}
      />
    </ListPageLayout>
  )
}
