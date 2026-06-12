import { Fragment, useState } from 'react'
import { Dialog, Transition } from '@headlessui/react'
import { X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useTenant } from '../hooks/useTenants'
import { useUpdateTenantExtras } from '../hooks/useTenants'
import type { PlanSummary, UsageStat } from '../types'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { tokens, textColors } from '@/lib/designTokens'

interface TenantDetailModalProps {
  tenantId: string | null
  onClose: () => void
  onRefresh?: () => void
}

function UsageBar({ stat, label }: { stat: UsageStat; label: string }) {
  const isUnlimited = stat.limit >= 2147483647 // PHP_INT_MAX
  const percent = isUnlimited ? 0 : stat.percent

  return (
    <div className="mb-3">
      <div className="mb-1 flex justify-between text-sm">
        <span className="font-medium text-gray-700">{label}</span>
        <span className="text-gray-500">
          {stat.current} / {isUnlimited ? '∞' : stat.limit}
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-gray-200">
        <div
          className={`h-full transition-all ${
            percent >= 90
              ? 'bg-red-500'
              : percent >= 70
                ? 'bg-yellow-500'
                : 'bg-blue-500'
          }`}
          style={{ width: `${Math.min(percent, 100)}%` }}
        />
      </div>
    </div>
  )
}

function ModuleBadge({
  name,
  enabled,
}: {
  name: string
  enabled: boolean
}) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
        enabled
          ? 'bg-green-100 text-green-800'
          : 'bg-gray-100 text-gray-500'
      }`}
    >
      {name}
    </span>
  )
}

function FeatureBadge({
  name,
  enabled,
}: {
  name: string
  enabled: boolean
}) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs ${
        enabled
          ? 'bg-blue-50 text-blue-700'
          : 'bg-gray-50 text-gray-400 line-through'
      }`}
    >
      {name}
    </span>
  )
}

function PlanUsageSection({ planSummary }: { planSummary: PlanSummary }) {
  const { plan, subscription, usage, modules, features, overage } = planSummary

  const moduleLabels: Record<string, string> = {
    sales: 'Sales',
    inventory: 'Inventory',
    treasury: 'Treasury',
    accounting: 'Accounting',
    partners: 'Partners',
    workshop: 'Workshop',
    vehicles: 'Vehicles',
    reporting: 'Reporting',
    multi_location: 'Multi-Location',
    ecommerce: 'E-Commerce',
    hr: 'HR',
  }

  const featureLabels: Record<string, string> = {
    credit_notes: 'Credit Notes',
    delivery_notes: 'Delivery Notes',
    document_conversion: 'Doc Conversion',
    pdf_export: 'PDF Export',
    excel_export: 'Excel Export',
    email_notifications: 'Email Alerts',
    sms_notifications: 'SMS Alerts',
    api_access: 'API Access',
    webhooks: 'Webhooks',
    custom_branding: 'Custom Branding',
    priority_support: 'Priority Support',
    audit_trail: 'Audit Trail',
    backup: 'Backup',
    multi_currency: 'Multi-Currency',
    landed_cost: 'Landed Cost',
    margin_analysis: 'Margin Analysis',
    bank_reconciliation: 'Bank Reconciliation',
    fiscal_compliance: 'Fiscal Compliance',
  }

  return (
    <div className="space-y-6">
      {/* Plan Info */}
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div className="flex items-center justify-between">
          <div>
            <h4 className="font-semibold text-gray-900">
              {plan?.name ?? 'No Plan'}
            </h4>
            <p className="text-sm text-gray-500">{plan?.description}</p>
          </div>
          {plan?.price_monthly && (
            <div className="text-right">
              <span className="text-2xl font-bold text-gray-900">
                {plan.price_monthly}
              </span>
              <span className="text-sm text-gray-500">
                {' '}
                {plan.currency}/mo
              </span>
            </div>
          )}
        </div>
        {subscription && (
          <div className="mt-3 flex gap-4 border-t border-gray-200 pt-3 text-sm">
            <span>
              Status:{' '}
              <span
                className={`font-medium ${
                  subscription.status === 'active'
                    ? 'text-green-600'
                    : subscription.status === 'trial'
                      ? 'text-blue-600'
                      : 'text-red-600'
                }`}
              >
                {subscription.status}
              </span>
            </span>
            <span>Billing: {subscription.billing_cycle}</span>
            {subscription.is_on_trial && subscription.trial_ends_at && (
              <span className="text-orange-600">
                Trial ends:{' '}
                {new Date(subscription.trial_ends_at).toLocaleDateString()}
              </span>
            )}
          </div>
        )}
      </div>

      {/* Resource Usage */}
      <div>
        <h4 className="mb-3 font-semibold text-gray-900">Resource Usage</h4>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-lg border border-gray-200 p-3">
            <UsageBar stat={usage.companies} label="Companies" />
            <UsageBar stat={usage.locations} label="Locations" />
            <UsageBar stat={usage.users} label="Users" />
          </div>
          <div className="rounded-lg border border-gray-200 p-3">
            <UsageBar stat={usage.products} label="Products" />
            <UsageBar stat={usage.partners} label="Partners" />
            <UsageBar
              stat={usage.documents_this_month}
              label="Documents (this month)"
            />
          </div>
        </div>
      </div>

      {/* User Overage */}
      {overage.extra_users > 0 && (
        <div className="rounded-lg border border-orange-200 bg-orange-50 p-4">
          <h4 className="font-semibold text-orange-800">User Overage</h4>
          <p className="mt-1 text-sm text-orange-700">
            {overage.extra_users} extra user(s) @ {overage.price_per_user}{' '}
            {plan?.currency}/user = {overage.total_overage} {plan?.currency}
            /mo
          </p>
        </div>
      )}

      {/* Modules */}
      <div>
        <h4 className="mb-2 font-semibold text-gray-900">Enabled Modules</h4>
        <div className="flex flex-wrap gap-2">
          {Object.entries(modules).map(([key, enabled]) => (
            <ModuleBadge
              key={key}
              name={moduleLabels[key] ?? key}
              enabled={enabled}
            />
          ))}
        </div>
      </div>

      {/* Features */}
      <div>
        <h4 className="mb-2 font-semibold text-gray-900">Features</h4>
        <div className="flex flex-wrap gap-2">
          {Object.entries(features).map(([key, enabled]) => (
            <FeatureBadge
              key={key}
              name={featureLabels[key] ?? key}
              enabled={enabled}
            />
          ))}
        </div>
      </div>
    </div>
  )
}

interface ManageModulesSectionProps {
  tenantId: string
  vertical: string | null
  defaultModules: string[]
  compatibleExtras: string[]
  enabledExtras: string[]
  onRefresh: (() => void) | undefined
}

function formatVerticalLabel(vertical: string | null): string {
  if (vertical === null || vertical === '') return ''
  return vertical
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function ManageModulesSection({
  tenantId,
  vertical,
  defaultModules,
  compatibleExtras,
  enabledExtras,
  onRefresh,
}: ManageModulesSectionProps) {
  const { t } = useTranslation('settings')
  const { t: tAdmin } = useTranslation('admin')
  const updateExtras = useUpdateTenantExtras()
  const [pendingToggle, setPendingToggle] = useState<{ module: string; enable: boolean } | null>(null)

  if (compatibleExtras.length === 0 && defaultModules.length === 0) {
    return null
  }

  const handleConfirmToggle = () => {
    if (!pendingToggle) return
    const newExtras = pendingToggle.enable
      ? [...enabledExtras, pendingToggle.module]
      : enabledExtras.filter((e) => e !== pendingToggle.module)

    updateExtras.mutate(
      { tenantId, enabledExtras: newExtras },
      {
        onSuccess: () => {
          setPendingToggle(null)
          onRefresh?.()
        },
        onSettled: () => {
          setPendingToggle(null)
        },
      }
    )
  }

  return (
    <div>
      <h4 className={`mb-3 font-semibold ${textColors.primary}`}>{t('admin.tenants.manageModules')}</h4>
      {defaultModules.length > 0 && (
        <div
          role="group"
          aria-label={tAdmin('tenantModules.defaultsTitle')}
          className="mb-4"
        >
          <h5 className={`text-sm font-medium ${textColors.secondary}`}>
            {tAdmin('tenantModules.defaultsTitle')}
          </h5>
          <p className={`mb-2 mt-0.5 text-xs ${textColors.disabled}`}>
            {tAdmin('tenantModules.defaultsHint', {
              vertical: formatVerticalLabel(vertical),
            })}
          </p>
          <div className="flex flex-wrap gap-2">
            {defaultModules.map((module) => (
              <span
                key={module}
                className={`${tokens.badge.base} ${tokens.badge.gray}`}
              >
                {module}
              </span>
            ))}
          </div>
        </div>
      )}
      <div className="space-y-2">
        {compatibleExtras.map((extra) => {
          const isEnabled = enabledExtras.includes(extra)
          return (
            <div
              key={extra}
              className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3"
            >
              <span className="text-sm font-medium text-gray-700">{extra}</span>
              <button
                type="button"
                disabled={updateExtras.isPending}
                onClick={() => setPendingToggle({ module: extra, enable: !isEnabled })}
                className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors disabled:opacity-50 ${
                  isEnabled ? 'bg-blue-600' : 'bg-gray-200'
                }`}
                aria-pressed={isEnabled}
                aria-label={`${isEnabled ? 'Disable' : 'Enable'} ${extra}`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transform transition-transform ${
                    isEnabled ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>
          )
        })}
      </div>
      <ConfirmDialog
        isOpen={pendingToggle !== null}
        onClose={() => setPendingToggle(null)}
        onConfirm={handleConfirmToggle}
        title={pendingToggle?.enable ? t('admin.tenants.enableModule') : t('admin.tenants.disableModule')}
        message={
          pendingToggle?.enable
            ? t('admin.tenants.enableModuleConfirm', { module: pendingToggle.module })
            : t('admin.tenants.disableModuleConfirm', { module: pendingToggle?.module ?? '' })
        }
        variant={pendingToggle?.enable ? 'info' : 'warning'}
        isLoading={updateExtras.isPending}
        confirmText={pendingToggle?.enable ? t('common:actions.enable') : t('common:actions.disable')}
      />
    </div>
  )
}

export function TenantDetailModal({
  tenantId,
  onClose,
  onRefresh,
}: TenantDetailModalProps) {
  const { data, isLoading } = useTenant(tenantId ?? '')
  const isOpen = tenantId !== null

  const enabledExtras = data?.tenant?.enabled_extras ?? []
  const compatibleExtras = data?.compatible_extras ?? []
  const defaultModules = data?.default_modules ?? []

  return (
    <Transition appear show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black bg-opacity-25" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-3xl transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                <div className="flex items-center justify-between">
                  <Dialog.Title
                    as="h3"
                    className="text-lg font-semibold leading-6 text-gray-900"
                  >
                    {data?.tenant?.name ?? 'Tenant Details'}
                  </Dialog.Title>
                  <button
                    type="button"
                    className="rounded-md p-1 hover:bg-gray-100"
                    onClick={onClose}
                  >
                    <X className="h-5 w-5 text-gray-500" />
                  </button>
                </div>

                <div className="mt-4">
                  {isLoading ? (
                    <div className="flex h-64 items-center justify-center">
                      <div className="text-gray-500">Loading...</div>
                    </div>
                  ) : data?.plan_summary ? (
                    <div className="space-y-6">
                      <PlanUsageSection planSummary={data.plan_summary} />
                      {tenantId !== null && (
                        <ManageModulesSection
                          tenantId={tenantId}
                          vertical={data?.tenant?.vertical ?? null}
                          defaultModules={defaultModules}
                          compatibleExtras={compatibleExtras}
                          enabledExtras={enabledExtras}
                          onRefresh={onRefresh}
                        />
                      )}
                    </div>
                  ) : (
                    <div className="flex h-64 items-center justify-center">
                      <div className="text-gray-500">No data available</div>
                    </div>
                  )}
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  )
}
