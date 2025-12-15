import { useTranslation } from 'react-i18next'
import { Calculator, Package, Users, ArrowRight } from 'lucide-react'
import type { PostPreview, OpeningBatchType } from '../types'

interface BatchPreviewProps {
  preview: PostPreview
  batchType: OpeningBatchType
}

export function BatchPreview({ preview, batchType }: BatchPreviewProps) {
  const { t } = useTranslation()

  const renderAccountingPreview = () => (
    <div className="space-y-6">
      {/* GL Entry Summary */}
      {preview.gl_entry && (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <h3 className="font-medium text-gray-900 mb-4 flex items-center gap-2">
            <Calculator className="h-5 w-5 text-blue-500" />
            {t('openingBalances.preview.glEntry')}
          </h3>
          <div className="grid grid-cols-3 gap-4 text-center">
            <div className="rounded-lg bg-green-50 p-3">
              <p className="text-xs text-green-600">{t('openingBalances.preview.debit')}</p>
              <p className="text-lg font-bold text-green-800">{preview.gl_entry.debit_account}</p>
            </div>
            <div className="flex items-center justify-center">
              <ArrowRight className="h-6 w-6 text-gray-400" />
            </div>
            <div className="rounded-lg bg-red-50 p-3">
              <p className="text-xs text-red-600">{t('openingBalances.preview.credit')}</p>
              <p className="text-lg font-bold text-red-800">{preview.gl_entry.credit_account}</p>
            </div>
          </div>
          <p className="mt-4 text-center text-xl font-bold text-gray-900">
            {preview.gl_entry.amount}
          </p>
        </div>
      )}

      {/* Lines table */}
      {preview.lines && preview.lines.length > 0 && (
        <div className="rounded-lg border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.accountCode')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.accountName')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.debit')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.credit')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {preview.lines.slice(0, 20).map((line, index) => (
                  <tr key={index}>
                    <td className="px-4 py-2 text-sm font-mono text-gray-900">
                      {line.account_code}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{line.account_name}</td>
                    <td className="px-4 py-2 text-sm text-right text-green-600">
                      {line.debit !== '0.00' ? line.debit : ''}
                    </td>
                    <td className="px-4 py-2 text-sm text-right text-red-600">
                      {line.credit !== '0.00' ? line.credit : ''}
                    </td>
                  </tr>
                ))}
                {/* OBE offset */}
                {preview.obe_offset && (
                  <tr className="bg-blue-50">
                    <td className="px-4 py-2 text-sm font-mono font-medium text-blue-900">
                      {preview.obe_offset.account_code}
                    </td>
                    <td className="px-4 py-2 text-sm font-medium text-blue-900">
                      {preview.obe_offset.account_name}
                    </td>
                    <td className="px-4 py-2 text-sm text-right font-medium text-green-600">
                      {preview.obe_offset.debit !== '0.00' ? preview.obe_offset.debit : ''}
                    </td>
                    <td className="px-4 py-2 text-sm text-right font-medium text-red-600">
                      {preview.obe_offset.credit !== '0.00' ? preview.obe_offset.credit : ''}
                    </td>
                  </tr>
                )}
              </tbody>
              <tfoot className="bg-gray-100">
                <tr>
                  <td colSpan={2} className="px-4 py-2 text-sm font-medium text-gray-900">
                    {t('openingBalances.preview.total')}
                  </td>
                  <td className="px-4 py-2 text-sm text-right font-bold text-green-700">
                    {preview.totals.total_debit}
                  </td>
                  <td className="px-4 py-2 text-sm text-right font-bold text-red-700">
                    {preview.totals.total_credit}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}
    </div>
  )

  const renderInventoryPreview = () => (
    <div className="space-y-6">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <Package className="mx-auto h-8 w-8 text-green-500" />
          <p className="mt-2 text-2xl font-bold text-gray-900">
            {preview.totals.total_lines ?? 0}
          </p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.products')}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <p className="text-2xl font-bold text-gray-900">{preview.totals.total_quantity}</p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.totalQuantity')}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <p className="text-2xl font-bold text-blue-600">{preview.totals.total_value}</p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.totalValue')}</p>
        </div>
      </div>

      {/* GL Entry */}
      {preview.gl_entry && (
        <div className="rounded-lg bg-blue-50 border border-blue-200 p-4">
          <h3 className="font-medium text-blue-800 mb-2">
            {t('openingBalances.preview.glEntryCreated')}
          </h3>
          <p className="text-sm text-blue-700">
            {t('openingBalances.preview.debitCredit', {
              debit: preview.gl_entry.debit_account,
              credit: preview.gl_entry.credit_account,
              amount: preview.gl_entry.amount,
            })}
          </p>
        </div>
      )}

      {/* Lines table */}
      {preview.lines && preview.lines.length > 0 && (
        <div className="rounded-lg border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.sku')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.product')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.location')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.quantity')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.unitCost')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.value')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {preview.lines.slice(0, 20).map((line, index) => (
                  <tr key={index}>
                    <td className="px-4 py-2 text-sm font-mono text-gray-900">
                      {line.product_sku}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{line.product_name}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{line.location_name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{line.quantity}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-600">{line.unit_cost}</td>
                    <td className="px-4 py-2 text-sm text-right font-medium text-blue-600">
                      {line.line_value}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {preview.lines.length > 20 && (
            <div className="bg-gray-50 px-4 py-2 text-xs text-gray-500">
              {t('openingBalances.preview.moreRows', { count: preview.lines.length - 20 })}
            </div>
          )}
        </div>
      )}
    </div>
  )

  const renderArApPreview = () => (
    <div className="space-y-6">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <Users className="mx-auto h-8 w-8 text-amber-500" />
          <p className="mt-2 text-2xl font-bold text-gray-900">
            {preview.totals.total_documents ?? 0}
          </p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.documents')}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <p className="text-2xl font-bold text-gray-900">{preview.totals.total_amount}</p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.totalAmount')}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4 text-center">
          <p className="text-2xl font-bold text-amber-600">{preview.totals.total_open_amount}</p>
          <p className="text-sm text-gray-500">{t('openingBalances.preview.openAmount')}</p>
        </div>
      </div>

      {/* Note about GL */}
      {preview.note && (
        <div className="rounded-lg bg-blue-50 border border-blue-200 p-4">
          <p className="text-sm text-blue-700">{preview.note}</p>
        </div>
      )}

      {/* Documents table */}
      {preview.documents && preview.documents.length > 0 && (
        <div className="rounded-lg border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.partner')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.externalRef')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.type')}
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.dueDate')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.total')}
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
                    {t('openingBalances.preview.open')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {preview.documents.slice(0, 20).map((doc, index) => (
                  <tr key={index}>
                    <td className="px-4 py-2 text-sm">
                      <span className="font-medium text-gray-900">{doc.partner_name}</span>
                      <span className="text-gray-500 text-xs ms-1">({doc.partner_code})</span>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-600">
                      {doc.external_invoice_number}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-500">{doc.document_type}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{doc.due_date}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{doc.total}</td>
                    <td className="px-4 py-2 text-sm text-right font-medium text-amber-600">
                      {doc.open_amount}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {preview.documents.length > 20 && (
            <div className="bg-gray-50 px-4 py-2 text-xs text-gray-500">
              {t('openingBalances.preview.moreRows', { count: preview.documents.length - 20 })}
            </div>
          )}
        </div>
      )}
    </div>
  )

  return (
    <div>
      {/* Batch info */}
      <div className="mb-6 rounded-lg bg-gray-50 p-4">
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">{t('openingBalances.preview.cutoverDate')}</dt>
            <dd className="font-medium text-gray-900">{preview.batch.cutover_date}</dd>
          </div>
          <div>
            <dt className="text-gray-500">{t('openingBalances.preview.description')}</dt>
            <dd className="font-medium text-gray-900">{preview.batch.description}</dd>
          </div>
        </dl>
      </div>

      {/* Type-specific preview */}
      {batchType === 'ACCOUNTING' && renderAccountingPreview()}
      {batchType === 'INVENTORY' && renderInventoryPreview()}
      {(batchType === 'AR_OPEN_ITEMS' || batchType === 'AP_OPEN_ITEMS') && renderArApPreview()}
    </div>
  )
}
