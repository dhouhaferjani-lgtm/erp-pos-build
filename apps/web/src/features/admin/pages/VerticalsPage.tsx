import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useVerticals } from '../hooks/useVerticals'
import { VerticalConfigModal } from '../components/VerticalConfigModal'
import type { AdminVerticalConfig } from '../types'
import { QueryError } from '@/components/QueryError'
import { tokens, textColors, borderColors } from '@/lib/designTokens'

export function VerticalsPage() {
  const { t } = useTranslation('admin')
  const [selectedVertical, setSelectedVertical] =
    useState<AdminVerticalConfig | null>(null)

  const { data, isLoading, error, refetch } = useVerticals()

  const verticals = data?.data ?? []
  const availableModules = data?.available_modules ?? []

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className={textColors.disabled}>{t('verticals.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <QueryError
          error={error}
          onRetry={() => {
            void refetch()
          }}
          title={t('verticals.loadError')}
        />
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <h1 className={`mb-8 text-3xl font-bold ${textColors.primary}`}>
          {t('verticals.title')}
        </h1>

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className={`min-w-full divide-y ${borderColors.divideDefault}`}>
            <thead className={tokens.table.header}>
              <tr>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.label')}
                </th>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.product')}
                </th>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.defaultModules')}
                </th>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.compatibleExtras')}
                </th>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.status')}
                </th>
                <th
                  className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}
                >
                  {t('verticals.table.actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideDefault} bg-white`}>
              {verticals.map((vertical) => (
                <tr key={vertical.vertical}>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-sm font-medium ${textColors.primary}`}
                  >
                    {vertical.label}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    <span
                      className={`${tokens.badge.base} ${
                        vertical.product === 'otospex'
                          ? tokens.badge.purple
                          : tokens.badge.blue
                      }`}
                    >
                      {vertical.product}
                    </span>
                  </td>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}
                  >
                    {vertical.default_modules.length}
                  </td>
                  <td
                    className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}
                  >
                    {vertical.compatible_extras.length}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    {vertical.is_overridden && (
                      <span className={`${tokens.badge.base} ${tokens.badge.yellow}`}>
                        {t('verticals.table.customized')}
                      </span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    <button
                      type="button"
                      className={`${textColors.brand} hover:underline`}
                      onClick={() => {
                        setSelectedVertical(vertical)
                      }}
                    >
                      {t('verticals.table.edit')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {verticals.length === 0 && (
            <div className={`p-8 text-center ${textColors.disabled}`}>
              {t('verticals.empty')}
            </div>
          )}
        </div>
      </div>

      {selectedVertical !== null && (
        <VerticalConfigModal
          key={selectedVertical.vertical}
          vertical={selectedVertical}
          availableModules={availableModules}
          onClose={() => {
            setSelectedVertical(null)
          }}
        />
      )}
    </div>
  )
}
