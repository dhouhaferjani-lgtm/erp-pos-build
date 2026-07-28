import { useTranslation } from 'react-i18next'
import { Calculator, Package, Users, ArrowRight } from 'lucide-react'
import type { PostPreview, OpeningBatchType } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'

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
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
          <h3 className={`font-medium ${colorTokens.text.primary} mb-4 flex items-center gap-2`}>
            <Calculator className={`h-5 w-5 ${colorTokens.intent.primary.textSubtle}`} />
            {t('openingBalances.preview.glEntry')}
          </h3>
          <div className="grid grid-cols-3 gap-4 text-center">
            <div className={`rounded-lg ${colorTokens.intent.success.bgSubtle} p-3`}>
              <p className={`text-xs ${colorTokens.intent.success.text}`}>{t('openingBalances.preview.debit')}</p>
              <p className={`text-lg font-bold ${colorTokens.intent.success.textStronger}`}>{preview.gl_entry.debit_account}</p>
            </div>
            <div className="flex items-center justify-center">
              <ArrowRight className={`h-6 w-6 ${colorTokens.text.disabled}`} />
            </div>
            <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3`}>
              <p className={`text-xs ${colorTokens.intent.danger.text}`}>{t('openingBalances.preview.credit')}</p>
              <p className={`text-lg font-bold ${colorTokens.intent.danger.textStronger}`}>{preview.gl_entry.credit_account}</p>
            </div>
          </div>
          <p className={`mt-4 text-center text-xl font-bold ${colorTokens.text.primary}`}>
            {preview.gl_entry.amount}
          </p>
        </div>
      )}

      {/* Lines table */}
      {preview.lines && preview.lines.length > 0 && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.accountCode')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.accountName')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.debit')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.credit')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {preview.lines.slice(0, 20).map((line, index) => (
                  <tr key={index}>
                    <td className={`px-4 py-2 text-sm font-mono ${colorTokens.text.primary}`}>
                      {line.account_code}
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{line.account_name}</td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.intent.success.text}`}>
                      {line.debit !== '0.00' ? line.debit : ''}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.intent.danger.text}`}>
                      {line.credit !== '0.00' ? line.credit : ''}
                    </td>
                  </tr>
                ))}
                {/* OBE offset */}
                {preview.obe_offset && (
                  <tr className={colorTokens.intent.primary.bgSubtle}>
                    <td className={`px-4 py-2 text-sm font-mono font-medium ${colorTokens.intent.primary.textStrongest}`}>
                      {preview.obe_offset.account_code}
                    </td>
                    <td className={`px-4 py-2 text-sm font-medium ${colorTokens.intent.primary.textStrongest}`}>
                      {preview.obe_offset.account_name}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right font-medium ${colorTokens.intent.success.text}`}>
                      {preview.obe_offset.debit !== '0.00' ? preview.obe_offset.debit : ''}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right font-medium ${colorTokens.intent.danger.text}`}>
                      {preview.obe_offset.credit !== '0.00' ? preview.obe_offset.credit : ''}
                    </td>
                  </tr>
                )}
              </tbody>
              <tfoot className={colorTokens.surface.muted}>
                <tr>
                  <td colSpan={2} className={`px-4 py-2 text-sm font-medium ${colorTokens.text.primary}`}>
                    {t('openingBalances.preview.total')}
                  </td>
                  <td className={`px-4 py-2 text-sm text-right font-bold ${colorTokens.intent.success.textStrong}`}>
                    {preview.totals.total_debit}
                  </td>
                  <td className={`px-4 py-2 text-sm text-right font-bold ${colorTokens.intent.danger.textStrong}`}>
                    {preview.totals.total_credit}
                  </td>
                </tr>
              </tfoot>
            </DataTable>
          </div>
        </div>
      )}
    </div>
  )

  const renderInventoryPreview = () => (
    <div className="space-y-6">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <Package className={`mx-auto h-8 w-8 ${colorTokens.intent.success.textSubtle}`} />
          <p className={`mt-2 text-2xl font-bold ${colorTokens.text.primary}`}>
            {preview.totals.total_lines ?? 0}
          </p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.products')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.text.primary}`}>{preview.totals.total_quantity}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalQuantity')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.intent.primary.text}`}>{preview.totals.total_value}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalValue')}</p>
        </div>
      </div>

      {/* GL Entry */}
      {preview.gl_entry && (
        <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} border ${colorTokens.intent.primary.borderSubtle} p-4`}>
          <h3 className={`font-medium ${colorTokens.intent.primary.textStronger} mb-2`}>
            {t('openingBalances.preview.glEntryCreated')}
          </h3>
          <p className={`text-sm ${colorTokens.intent.primary.textStrong}`}>
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
        <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.sku')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.product')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.location')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.quantity')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.unitCost')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.value')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {preview.lines.slice(0, 20).map((line, index) => (
                  <tr key={index}>
                    <td className={`px-4 py-2 text-sm font-mono ${colorTokens.text.primary}`}>
                      {line.product_sku}
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{line.product_name}</td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.subtle}`}>{line.location_name}</td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.text.primary}`}>
                      {formatQuantity(line.quantity ?? '0', getQuantityDecimals(line))}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.text.muted}`}>{line.unit_cost}</td>
                    <td className={`px-4 py-2 text-sm text-right font-medium ${colorTokens.intent.primary.text}`}>
                      {line.line_value}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
          {preview.lines.length > 20 && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
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
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <Users className={`mx-auto h-8 w-8 ${colorTokens.intent.caution.textSubtle}`} />
          <p className={`mt-2 text-2xl font-bold ${colorTokens.text.primary}`}>
            {preview.totals.total_documents ?? 0}
          </p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.documents')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.text.primary}`}>{preview.totals.total_amount}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalAmount')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.intent.caution.text}`}>{preview.totals.total_open_amount}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.openAmount')}</p>
        </div>
      </div>

      {/* Note about GL */}
      {preview.note && (
        <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} border ${colorTokens.intent.primary.borderSubtle} p-4`}>
          <p className={`text-sm ${colorTokens.intent.primary.textStrong}`}>{preview.note}</p>
        </div>
      )}

      {/* Documents table */}
      {preview.documents && preview.documents.length > 0 && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.partner')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.externalRef')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.type')}
                  </th>
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.dueDate')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.total')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.open')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {preview.documents.slice(0, 20).map((doc, index) => (
                  <tr key={index}>
                    <td className="px-4 py-2 text-sm">
                      <span className={`font-medium ${colorTokens.text.primary}`}>{doc.partner_name}</span>
                      <span className={`${colorTokens.text.subtle} text-xs ms-1`}>({doc.partner_code})</span>
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.muted}`}>
                      {doc.external_invoice_number}
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.subtle}`}>{doc.document_type}</td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.subtle}`}>{doc.due_date}</td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.text.primary}`}>{doc.total}</td>
                    <td className={`px-4 py-2 text-sm text-right font-medium ${colorTokens.intent.caution.text}`}>
                      {doc.open_amount}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
          {preview.documents.length > 20 && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
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
      <div className={`mb-6 rounded-lg ${colorTokens.surface.page} p-4`}>
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt className={colorTokens.text.subtle}>{t('openingBalances.preview.cutoverDate')}</dt>
            <dd className={`font-medium ${colorTokens.text.primary}`}>{preview.batch.cutover_date}</dd>
          </div>
          <div>
            <dt className={colorTokens.text.subtle}>{t('openingBalances.preview.description')}</dt>
            <dd className={`font-medium ${colorTokens.text.primary}`}>{preview.batch.description}</dd>
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
