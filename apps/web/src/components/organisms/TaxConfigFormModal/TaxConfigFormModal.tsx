import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { getErrorMessage } from '../../../lib/api'
import {
  useCreateTaxConfiguration,
  useUpdateTaxConfiguration,
  useDocumentTypes,
} from '../../../hooks/useTaxConfigurations'
import type { TaxConfiguration, TaxConfigurationFormData, TaxType, TaxApplicationLevel } from '../../../features/settings/types/tax'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface TaxConfigFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSaved: (tax: TaxConfiguration) => void
  editingTax?: TaxConfiguration | null
}

const getDocumentTypeTranslationKey = (value: string): string => {
  const mapping: Record<string, string> = {
    'QUOTATION': 'quotation',
    'SALES_ORDER': 'salesOrder',
    'DELIVERY_NOTE': 'deliveryNote',
    'TAX_INVOICE': 'taxInvoice',
    'FISCAL_RECEIPT': 'fiscalReceipt',
    'CREDIT_NOTE': 'creditNote',
    'PURCHASE_ORDER': 'purchaseOrder',
    'PURCHASE_INVOICE': 'purchaseInvoice',
  }
  return mapping[value] ?? value.toLowerCase()
}

const defaultFormData: TaxConfigurationFormData = {
  name: '',
  code: '',
  tax_type: 'PERCENTAGE',
  percentage_rate: '',
  applies_to: 'LINE_ITEMS',
  stacks_on: 'BASE_AMOUNT',
  applicable_document_types: [],
  is_active: true,
  is_recoverable: true,
  is_stamp_duty: false,
  effective_from: null,
  effective_to: null,
}

export function TaxConfigFormModal({ isOpen, onClose, onSaved, editingTax }: TaxConfigFormModalProps) {
  const { t } = useTranslation(['settings', 'common', 'sales'])
  const { data: documentTypes = [] } = useDocumentTypes()
  const createTax = useCreateTaxConfiguration()
  const updateTax = useUpdateTaxConfiguration()

  const initialFormData = useMemo((): TaxConfigurationFormData => {
    if (editingTax) {
      return {
        name: editingTax.name,
        code: editingTax.code,
        tax_type: editingTax.tax_type,
        percentage_rate: editingTax.percentage_rate ?? '',
        fixed_amount: editingTax.fixed_amount ?? '',
        applies_to: editingTax.applies_to,
        stacks_on: editingTax.stacks_on,
        applicable_document_types: editingTax.applicable_document_types,
        is_active: editingTax.is_active,
        is_recoverable: editingTax.is_recoverable,
        is_stamp_duty: editingTax.is_stamp_duty,
        is_default: editingTax.is_default,
        effective_from: editingTax.effective_from,
        effective_to: editingTax.effective_to,
        sequence_order: editingTax.sequence_order,
      }
    }
    return { ...defaultFormData }
  }, [editingTax])

  const [taxFormData, setTaxFormData] = useState<TaxConfigurationFormData>(initialFormData)

  const handleTaxFormChange = (field: keyof TaxConfigurationFormData, value: unknown) => {
    setTaxFormData(prev => ({ ...prev, [field]: value }))
  }

  const handleSave = async () => {
    try {
      let savedTax: TaxConfiguration
      if (editingTax) {
        savedTax = await updateTax.mutateAsync({ id: editingTax.id, data: taxFormData })
        toast.success(t('settings:tax.configurations.messages.updated'))
      } else {
        savedTax = await createTax.mutateAsync(taxFormData)
        toast.success(t('settings:tax.configurations.messages.created'))
      }
      onSaved(savedTax)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleClose = () => {
    setTaxFormData({ ...defaultFormData })
    onClose()
  }

  if (!isOpen) return null

  return (
    <div className={`fixed inset-0 z-50 flex items-center justify-center ${colorTokens.surface.overlay} overflow-y-auto`}>
      <div className={`${colorTokens.surface.base} rounded-lg shadow-xl max-w-2xl w-full mx-4 my-8`}>
        <div className={`px-6 py-4 border-b ${colorTokens.border.subtle}`}>
          <h3 className={`text-lg font-medium ${colorTokens.text.primary}`}>
            {editingTax ? t('settings:tax.configurations.form.editTitle') : t('settings:tax.configurations.form.addTitle')}
          </h3>
        </div>
        <div className="px-6 py-4 space-y-4">
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('settings:tax.configurations.form.name')}
            </label>
            <input
              type="text"
              value={taxFormData.name}
              onChange={(e) => { handleTaxFormChange('name', e.target.value); }}
              className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
              placeholder={t('settings:tax.configurations.form.namePlaceholder')}
            />
          </div>
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('settings:tax.configurations.form.typeLabel')}
            </label>
            <select
              value={taxFormData.tax_type}
              onChange={(e) => { handleTaxFormChange('tax_type', e.target.value as TaxType); }}
              className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
            >
              <option value="PERCENTAGE">{t('settings:tax.configurations.form.typePercentage')}</option>
              <option value="FIXED_AMOUNT">{t('settings:tax.configurations.form.typeFixed')}</option>
            </select>
          </div>
          {taxFormData.tax_type === 'PERCENTAGE' ? (
            <div>
              <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('settings:tax.configurations.form.rate')}
              </label>
              <input
                type="number"
                step="0.01"
                value={taxFormData.percentage_rate}
                onChange={(e) => { handleTaxFormChange('percentage_rate', e.target.value); }}
                className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
              />
            </div>
          ) : (
            <div>
              <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('settings:tax.configurations.form.amount')}
              </label>
              <input
                type="number"
                step="0.01"
                value={taxFormData.fixed_amount ?? ''}
                onChange={(e) => { handleTaxFormChange('fixed_amount', e.target.value); }}
                className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
              />
            </div>
          )}
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
              {t('settings:tax.configurations.form.appliesTo')}
            </label>
            <select
              value={taxFormData.applies_to}
              onChange={(e) => { handleTaxFormChange('applies_to', e.target.value as TaxApplicationLevel); }}
              className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
            >
              <option value="LINE_ITEMS">{t('settings:tax.configurations.form.appliesToLineItems')}</option>
              <option value="DOCUMENT_TOTAL">{t('settings:tax.configurations.form.appliesToDocument')}</option>
            </select>
          </div>
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('settings:tax.configurations.form.documentTypes')}
            </label>
            <p className={`text-xs ${colorTokens.text.subtle} mb-2`}>
              {t('settings:tax.configurations.form.documentTypesHelp')}
            </p>
            <div className={`space-y-2 max-h-48 overflow-y-auto border ${colorTokens.border.default} rounded-md p-3`}>
              <label className={`flex items-center pb-2 border-b ${colorTokens.border.subtle}`}>
                <input
                  type="checkbox"
                  checked={taxFormData.applicable_document_types.length === 0}
                  onChange={(e) => {
                    if (e.target.checked) {
                      handleTaxFormChange('applicable_document_types', [])
                    }
                  }}
                  className={`h-4 w-4 ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing} ${colorTokens.border.default} rounded`}
                />
                <span className={`ms-2 text-sm ${colorTokens.text.secondary} font-medium`}>
                  {t('settings:tax.configurations.form.allDocumentTypes')}
                </span>
              </label>
              <div className="pt-1">
                <p className={`text-xs ${colorTokens.text.subtle} mb-2 italic`}>
                  {t('settings:tax.configurations.form.orSelectSpecific')}
                </p>
                {documentTypes.map((docType) => (
                  <label key={docType.value} className="flex items-center mb-1">
                    <input
                      type="checkbox"
                      checked={taxFormData.applicable_document_types.includes(docType.value)}
                      onChange={(e) => {
                        const current = taxFormData.applicable_document_types
                        if (e.target.checked) {
                          const newTypes = current.length === 0 ? [docType.value] : [...current, docType.value]
                          handleTaxFormChange('applicable_document_types', newTypes)
                        } else {
                          const newTypes = current.filter(dt => dt !== docType.value)
                          handleTaxFormChange('applicable_document_types', newTypes)
                        }
                      }}
                      className={`h-4 w-4 ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing} ${colorTokens.border.default} rounded`}
                    />
                    <span className={`ms-2 text-sm ${colorTokens.text.secondary}`}>
                      {t(`sales:documents.types.${getDocumentTypeTranslationKey(docType.value)}`, { defaultValue: docType.label })}
                    </span>
                  </label>
                ))}
              </div>
            </div>
          </div>
          <div>
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={taxFormData.is_active}
                onChange={(e) => { handleTaxFormChange('is_active', e.target.checked); }}
                className={`h-4 w-4 ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing} ${colorTokens.border.default} rounded`}
              />
              <span className={`ms-2 text-sm ${colorTokens.text.secondary}`}>{t('settings:tax.configurations.form.active')}</span>
            </label>
          </div>
          <div>
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={taxFormData.is_recoverable ?? true}
                onChange={(e) => { handleTaxFormChange('is_recoverable', e.target.checked); }}
                className={`h-4 w-4 ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing} ${colorTokens.border.default} rounded`}
              />
              <span className={`ms-2 text-sm ${colorTokens.text.secondary}`}>{t('settings:tax.configurations.form.recoverable')}</span>
            </label>
            <p className={`text-xs ${colorTokens.text.subtle} mt-1 ms-6`}>
              {t('settings:tax.configurations.form.recoverableHelp')}
            </p>
          </div>
          <div>
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={taxFormData.is_stamp_duty ?? false}
                onChange={(e) => { handleTaxFormChange('is_stamp_duty', e.target.checked); }}
                className={`h-4 w-4 ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing} ${colorTokens.border.default} rounded`}
              />
              <span className={`ms-2 text-sm ${colorTokens.text.secondary}`}>{t('settings:tax.configurations.form.stampDuty')}</span>
            </label>
            <p className={`text-xs ${colorTokens.text.subtle} mt-1 ms-6`}>
              {t('settings:tax.configurations.form.stampDutyHelp')}
            </p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label htmlFor="tax-effective-from" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('settings:tax.configurations.form.effectiveFrom')}
              </label>
              <input
                id="tax-effective-from"
                type="date"
                value={taxFormData.effective_from ?? ''}
                onChange={(e) => { handleTaxFormChange('effective_from', e.target.value || null); }}
                className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
              />
            </div>
            <div>
              <label htmlFor="tax-effective-to" className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}>
                {t('settings:tax.configurations.form.effectiveTo')}
              </label>
              <input
                id="tax-effective-to"
                type="date"
                value={taxFormData.effective_to ?? ''}
                onChange={(e) => { handleTaxFormChange('effective_to', e.target.value || null); }}
                className={`block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing} sm:text-sm`}
              />
            </div>
          </div>
        </div>
        <div className={`px-6 py-4 border-t ${colorTokens.border.subtle} flex justify-end gap-3`}>
          <button
            type="button"
            onClick={handleClose}
            className={`px-4 py-2 border ${colorTokens.border.default} rounded-md text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} ${colorTokens.variants.hoverBgGray50}`}
          >
            {t('common:cancel')}
          </button>
          <button
            type="button"
            onClick={() => { void handleSave(); }}
            disabled={createTax.isPending || updateTax.isPending}
            className={`px-4 py-2 ${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse} rounded-md text-sm font-medium ${colorTokens.variants.hoverBgBlue700} disabled:opacity-50`}
          >
            {t('common:save')}
          </button>
        </div>
      </div>
    </div>
  )
}
