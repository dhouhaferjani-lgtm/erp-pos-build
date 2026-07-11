/**
 * DocumentPartnerInfo Component
 *
 * Displays partner/customer information card for documents.
 * Shows partner name, email, and optional vehicle context.
 */

import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Building2, Car } from 'lucide-react'
import type { Document } from '../../../types/document'
import { colorClasses } from '@/lib/designTokens'

export interface DocumentPartnerInfoProps {
  /** The document containing partner info */
  document: Document
  /** Optional class name for the container */
  className?: string
}

export function DocumentPartnerInfo({
  document,
  className = '',
}: DocumentPartnerInfoProps) {
  const { t } = useTranslation(['sales', 'common'])

  return (
    <div
      className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6 ${className}`}
    >
      <h2 className={`mb-4 text-lg font-semibold ${colorClasses.textGray900}`}>
        {t('documents.partnerInfo')}
      </h2>
      <dl className="space-y-3">
        {/* Partner Name */}
        <div className="flex items-start gap-3">
          <Building2 className={`mt-0.5 h-5 w-5 ${colorClasses.textGray400}`} />
          <div>
            <dt className={`text-sm font-medium ${colorClasses.textGray500}`}>
              {t('documents.partner')}
            </dt>
            <dd>
              {document.partner_id ? (
                <Link
                  to={
                    document.type === 'purchase_order'
                      ? `/purchases/suppliers/${document.partner_id}`
                      : `/sales/customers/${document.partner_id}`
                  }
                  className={`font-medium ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800} hover:underline`}
                >
                  {document.partner_name ?? t('common:status.unknown')}
                </Link>
              ) : (
                <span className={`${colorClasses.textGray900}`}>
                  {document.partner_name ?? t('common:status.unknown')}
                </span>
              )}
            </dd>
          </div>
        </div>

        {/* Partner Email */}
        {document.partner_email && (
          <div>
            <dt className={`text-sm font-medium ${colorClasses.textGray500}`}>
              {t('documents.email')}
            </dt>
            <dd className={`${colorClasses.textGray900}`}>{document.partner_email}</dd>
          </div>
        )}

        {/* Vehicle Context */}
        {document.vehicle_context && (
          <div className="flex items-start gap-3">
            <Car className={`mt-0.5 h-5 w-5 ${colorClasses.textGray400}`} />
            <div className="flex-1">
              <dt className={`text-sm font-medium ${colorClasses.textGray500}`}>
                {t('documents.vehicle')}
              </dt>
              <dd>
                {document.vehicle_context.vehicle_id ? (
                  <Link
                    to={`/vehicles/${document.vehicle_context.vehicle_id}`}
                    className={`font-medium ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800} hover:underline`}
                  >
                    {document.vehicle_context.display ?? t('common:status.unknown')}
                  </Link>
                ) : (
                  <span className={`${colorClasses.textGray900}`}>
                    {document.vehicle_context.display ?? t('common:status.unknown')}
                  </span>
                )}
              </dd>
              {document.vehicle_context.mileage && (
                <dd className={`mt-1 text-sm ${colorClasses.textGray500}`}>
                  {t('documents.mileage')}:{' '}
                  {t('documents.mileageValue', { value: document.vehicle_context.mileage.toLocaleString() })}
                </dd>
              )}
            </div>
          </div>
        )}
      </dl>
    </div>
  )
}
