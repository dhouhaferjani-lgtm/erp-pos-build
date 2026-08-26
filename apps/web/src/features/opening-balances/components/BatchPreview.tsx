import { useTranslation } from 'react-i18next'
import { Package, Users } from 'lucide-react'
import type {
  AccountingPostPreview,
  ArApPostPreview,
  InventoryPostPreview,
  PostPreview,
} from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { bccomp, formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { useCurrency } from '@/hooks/useCurrency'

interface BatchPreviewProps {
  preview: PostPreview
}

/**
 * N-3: the variant is read from the payload's own `batch_type` discriminator.
 * It used to come from a separate `batchType` prop threaded down from the
 * wizard — a second source of truth that could disagree with the payload, and
 * did: an ACCOUNTING payload was rendered through a header that reads
 * `preview.batch.cutover_date`, a key ACCOUNTING has never carried.
 */
export function BatchPreview({ preview }: BatchPreviewProps) {
  const { t } = useTranslation()
  // Gate r1 F-5: the API returns unscaled decimal strings here (the reducer
  // seeds '0'), so a TND tenant was shown a bare `0` / `10000.000` with no
  // grouping and no millimes while every sibling screen formats through the
  // company currency. Same class as campaign N-8.
  const { format: formatMoney } = useCurrency()

  /** Blank a zero amount, else format it — never parsing it as a float (rule 19). */
  const amountOrBlank = (value: string): string =>
    bccomp(value, '0') === 0 ? '' : formatMoney(value, { symbol: false })

  /** True when at least one line seeds a treasury repository (W4-2). */
  const hasRepositoryLine = (accounting: AccountingPostPreview): boolean =>
    accounting.lines.some((line) => line.repository_code !== null)

  const renderAccountingPreview = (accounting: AccountingPostPreview) => (
    <div className="space-y-6">
      {/* Lines table */}
      {accounting.lines.length > 0 && (
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
                  {/* W4-2: the till a cash/bank line seeds. Rendered only when
                      the batch actually names one, so an ordinary GL batch keeps
                      its four-column shape. */}
                  {hasRepositoryLine(accounting) && (
                    <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                      {t('openingBalances.preview.repository')}
                    </th>
                  )}
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {accounting.lines.slice(0, 20).map((line) => (
                  <tr key={line.row_number}>
                    <td className={`px-4 py-2 text-sm font-mono ${colorTokens.text.primary}`}>
                      {line.account_code}
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{line.account_name}</td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.intent.success.text}`}>
                      {amountOrBlank(line.debit)}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.intent.danger.text}`}>
                      {amountOrBlank(line.credit)}
                    </td>
                    {hasRepositoryLine(accounting) && (
                      <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>
                        {line.repository_code === null
                          ? ''
                          : `${line.repository_name ?? ''} (${line.repository_code})`}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
              <tfoot className={colorTokens.surface.muted}>
                <tr>
                  <td colSpan={2} className={`px-4 py-2 text-sm font-medium ${colorTokens.text.primary}`}>
                    {t('openingBalances.preview.total')}
                  </td>
                  <td className={`px-4 py-2 text-sm text-right font-bold ${colorTokens.intent.success.textStrong}`}>
                    {formatMoney(accounting.totals.debit, { symbol: false })}
                  </td>
                  <td className={`px-4 py-2 text-sm text-right font-bold ${colorTokens.intent.danger.textStrong}`}>
                    {formatMoney(accounting.totals.credit, { symbol: false })}
                  </td>
                  {hasRepositoryLine(accounting) && <td className="px-4 py-2" />}
                </tr>
              </tfoot>
            </DataTable>
          </div>
          {/* W4-2: name the till column's meaning where the operator is about
              to lock the batch. Rendered only when a line actually seeds one. */}
          {hasRepositoryLine(accounting) && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.preview.repositoryHint')}
            </div>
          )}
          {accounting.lines.length > 20 && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.preview.moreRows', { count: accounting.lines.length - 20 })}
            </div>
          )}
        </div>
      )}
    </div>
  )

  const renderInventoryPreview = (inventory: InventoryPostPreview) => (
    <div className="space-y-6">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <Package className={`mx-auto h-8 w-8 ${colorTokens.intent.success.textSubtle}`} />
          <p className={`mt-2 text-2xl font-bold ${colorTokens.text.primary}`}>
            {inventory.totals.total_lines}
          </p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.products')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.text.primary}`}>{inventory.totals.total_quantity}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalQuantity')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.intent.primary.text}`}>{inventory.totals.total_value}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalValue')}</p>
        </div>
      </div>

      {/* GL Entry */}
      {(
        <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} border ${colorTokens.intent.primary.borderSubtle} p-4`}>
          <h3 className={`font-medium ${colorTokens.intent.primary.textStronger} mb-2`}>
            {t('openingBalances.preview.glEntryCreated')}
          </h3>
          <p className={`text-sm ${colorTokens.intent.primary.textStrong}`}>
            {t('openingBalances.preview.debitCredit', {
              debit: inventory.gl_entry.debit_account,
              credit: inventory.gl_entry.credit_account,
              amount: inventory.gl_entry.amount,
            })}
          </p>
        </div>
      )}

      {/* Lines table */}
      {inventory.lines.length > 0 && (
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
                  <th className={`px-4 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.expiryDate')}
                  </th>
                  <th className={`px-4 py-2 text-right text-xs font-medium ${colorTokens.text.subtle}`}>
                    {t('openingBalances.preview.value')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {inventory.lines.slice(0, 20).map((line) => (
                  <tr key={line.row_number}>
                    <td className={`px-4 py-2 text-sm font-mono ${colorTokens.text.primary}`}>
                      {line.product_sku}
                    </td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{line.product_name}</td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.subtle}`}>{line.location_name}</td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.text.primary}`}>
                      {formatQuantity(line.quantity, getQuantityDecimals(line))}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right ${colorTokens.text.muted}`}>{line.unit_cost}</td>
                    <td className={`px-4 py-2 text-sm ${colorTokens.text.subtle}`}>
                      {line.expiry_date ?? t('openingBalances.preview.noExpiry')}
                      {line.expiry_is_past && (
                        <span
                          data-testid={`expiry-past-${String(line.row_number)}`}
                          className={`ms-2 rounded px-1.5 py-0.5 text-xs font-medium ${colorTokens.intent.warning.bgSubtle} ${colorTokens.intent.warning.text}`}
                        >
                          {t('openingBalances.preview.expiryInPast')}
                        </span>
                      )}
                      {line.expiry_conflicts_with_existing_lot && (
                        <span
                          data-testid={`expiry-conflict-${String(line.row_number)}`}
                          className={`ms-2 rounded px-1.5 py-0.5 text-xs font-medium ${colorTokens.intent.danger.bgSubtle} ${colorTokens.intent.danger.text}`}
                        >
                          {t('openingBalances.preview.expiryConflict')}
                        </span>
                      )}
                    </td>
                    <td className={`px-4 py-2 text-sm text-right font-medium ${colorTokens.intent.primary.text}`}>
                      {line.line_value}
                    </td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
          {inventory.lines.some((line) => line.expiry_date === null) && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.preview.noExpiryHint')}
            </div>
          )}
          {inventory.lines.some((line) => line.expiry_is_past) && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.intent.warning.text}`}>
              {t('openingBalances.preview.expiryInPastHint')}
            </div>
          )}
          {inventory.lines.some((line) => line.expiry_conflicts_with_existing_lot) && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.intent.danger.text}`}>
              {t('openingBalances.preview.expiryConflictHint')}
            </div>
          )}
          {inventory.lines.length > 20 && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.preview.moreRows', { count: inventory.lines.length - 20 })}
            </div>
          )}
        </div>
      )}
    </div>
  )

  const renderArApPreview = (arAp: ArApPostPreview) => (
    <div className="space-y-6">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <Users className={`mx-auto h-8 w-8 ${colorTokens.intent.caution.textSubtle}`} />
          <p className={`mt-2 text-2xl font-bold ${colorTokens.text.primary}`}>
            {arAp.totals.total_documents}
          </p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.documents')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.text.primary}`}>{arAp.totals.total_amount}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.totalAmount')}</p>
        </div>
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4 text-center`}>
          <p className={`text-2xl font-bold ${colorTokens.intent.caution.text}`}>{arAp.totals.total_open_amount}</p>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('openingBalances.preview.openAmount')}</p>
        </div>
      </div>

      {/* Note about GL */}
      {arAp.note !== '' && (
        <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} border ${colorTokens.intent.primary.borderSubtle} p-4`}>
          <p className={`text-sm ${colorTokens.intent.primary.textStrong}`}>{arAp.note}</p>
        </div>
      )}

      {/* Documents table */}
      {arAp.documents.length > 0 && (
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
                {arAp.documents.slice(0, 20).map((doc) => (
                  <tr key={doc.row_number}>
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
          {arAp.documents.length > 20 && (
            <div className={`${colorTokens.surface.page} px-4 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.preview.moreRows', { count: arAp.documents.length - 20 })}
            </div>
          )}
        </div>
      )}
    </div>
  )

  // N-3: ACCOUNTING's header is `entry` (an `entry_date`); INVENTORY and AR/AP
  // carry a `batch` (a `cutover_date`). There is no key common to all three, so
  // the header is built inside each branch of the discriminated union — never
  // from a single assumed-shared shape.
  const header =
    preview.batch_type === 'ACCOUNTING'
      ? {
          dateLabel: t('openingBalances.preview.entryDate'),
          date: preview.entry.entry_date,
          description: preview.entry.description,
        }
      : {
          dateLabel: t('openingBalances.preview.cutoverDate'),
          date: preview.batch.cutover_date,
          description: preview.batch.description,
        }

  return (
    <div>
      {/* Batch info */}
      <div className={`mb-6 rounded-lg ${colorTokens.surface.page} p-4`}>
        <dl className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt className={colorTokens.text.subtle}>{header.dateLabel}</dt>
            <dd className={`font-medium ${colorTokens.text.primary}`}>{header.date}</dd>
          </div>
          <div>
            <dt className={colorTokens.text.subtle}>{t('openingBalances.preview.description')}</dt>
            <dd className={`font-medium ${colorTokens.text.primary}`}>{header.description}</dd>
          </div>
        </dl>
      </div>

      {/* Type-specific preview */}
      {preview.batch_type === 'ACCOUNTING' && renderAccountingPreview(preview)}
      {preview.batch_type === 'INVENTORY' && renderInventoryPreview(preview)}
      {(preview.batch_type === 'AR_OPEN_ITEMS' || preview.batch_type === 'AP_OPEN_ITEMS') &&
        renderArApPreview(preview)}
    </div>
  )
}
