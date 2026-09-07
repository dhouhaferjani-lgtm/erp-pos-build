import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, Trash2 } from 'lucide-react'
import { useCreateCounting } from '../api/queries'
import { isBlockSalesEnforced } from '../blockSales'
import type {
  CountingScopeType,
  CountingExecutionMode,
  CreateCountingFormData,
} from '../types'
import { cn } from '@/lib/utils'
import { UserSelector } from '@/features/users/components/UserSelector'
import { LineItemEntryBar, ProductCell, type ProductLineProduct } from '@/components/molecules/line-items'
import { LocationSelectorMulti } from '@/features/locations/components/LocationSelectorMulti'
import { CategorySelector } from '@/features/categories/components/CategorySelector'
import { useUsers } from '@/features/users/hooks/useUsers'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { listLocationNodes } from '@/features/placement/api'
import { NodePicker } from '@/features/placement/components/NodePicker'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Button } from '@/components/atoms/Button'

const STEPS = ['scope', 'selection', 'configuration', 'assignment', 'review'] as const
type Step = (typeof STEPS)[number]

const DEFAULT_AMBIGUITY_WINDOW_MINUTES = 15

const SCOPE_TYPES: CountingScopeType[] = [
  'full_inventory',
  'zone',
  'category',
  'location',
  'product',
  'product_location',
]

export function CreateCountingPage() {
  const { t } = useTranslation('inventory')
  const navigate = useNavigate()
  const createCounting = useCreateCounting()

  const [currentStep, setCurrentStep] = useState<Step>('scope')
  const [formData, setFormData] = useState<Partial<CreateCountingFormData>>({
    scope_type: 'full_inventory',
    scope_filters: {},
    execution_mode: 'parallel',
    requires_count_2: true,
    requires_count_3: false,
    allow_unexpected_items: true,
    block_sales: false,
    ambiguity_window_minutes: DEFAULT_AMBIGUITY_WINDOW_MINUTES,
  })

  const stepIndex = STEPS.indexOf(currentStep)

  // Determine if selection step should be shown based on scope type
  const needsSelectionStep = () => {
    const scopeType = formData.scope_type
    return scopeType === 'product' ||
           scopeType === 'product_location' ||
           scopeType === 'location' ||
           scopeType === 'category' ||
           scopeType === 'zone'
  }

  const canProceed = () => {
    switch (currentStep) {
      case 'scope':
        return !!formData.scope_type
      case 'selection':
        // Validate selection based on scope type
        if (formData.scope_type === 'product') {
          return (formData.scope_filters?.product_ids?.length ?? 0) > 0
        }
        if (formData.scope_type === 'product_location') {
          // product_location additionally requires the single location the
          // products are counted at — the backend 422s without location_id.
          return (
            (formData.scope_filters?.product_ids?.length ?? 0) > 0 &&
            !!formData.scope_filters?.location_id
          )
        }
        if (formData.scope_type === 'location') {
          return (formData.scope_filters?.location_ids?.length ?? 0) > 0
        }
        if (formData.scope_type === 'category') {
          return (formData.scope_filters?.category_ids?.length ?? 0) > 0
        }
        if (formData.scope_type === 'zone') {
          return (
            !!formData.scope_filters?.location_id &&
            (formData.scope_filters.zone_ids?.length ?? 0) > 0
          )
        }
        return true
      case 'configuration':
        return true
      case 'assignment':
        return !!formData.count_1_user_id
      case 'review':
        return true
      default:
        return false
    }
  }

  const nextStep = () => {
    const currentIndex = stepIndex
    let nextIndex = currentIndex + 1

    // Skip selection step if not needed
    if (currentStep === 'scope' && !needsSelectionStep()) {
      nextIndex = STEPS.indexOf('configuration')
    }

    if (nextIndex < STEPS.length) {
      setCurrentStep(STEPS[nextIndex])
    }
  }

  const prevStep = () => {
    const currentIndex = stepIndex
    let prevIndex = currentIndex - 1

    // Skip selection step if not needed when going back
    if (currentStep === 'configuration' && !needsSelectionStep()) {
      prevIndex = STEPS.indexOf('scope')
    }

    if (prevIndex >= 0) {
      setCurrentStep(STEPS[prevIndex])
    }
  }

  const createCountingSession = () => {
    if (!formData.count_1_user_id) return

    // Sales blocking is only ENFORCED for location / full_inventory /
    // product_location (CountingBlockService::activeBlockFor); the backend
    // rejects block_sales:true for zone, product and category with a 422.
    // Coerced at submit time too, defense-in-depth alongside the disabled
    // toggle (gate r1 FE IMPORTANT-1).
    const payload: CreateCountingFormData = {
      ...(formData as CreateCountingFormData),
      block_sales: isBlockSalesEnforced(formData.scope_type) && !!formData.block_sales,
    }

    createCounting.mutate(payload, {
      onSuccess: (counting) => {
        void navigate(`/inventory/counting/${String(counting.id)}`)
      },
    })
  }

  return (
    <div className="max-w-3xl mx-auto">
      {/* Header */}
      <div className="mb-8">
        <Link
          to="/inventory/counting"
          className={`inline-flex items-center text-sm ${colorTokens.text.subtle} ${colorTokens.intent.neutral.textHoverStrong} mb-4`}
        >
          <ArrowLeft className="w-4 h-4 me-1" />
          {t('back')}
        </Link>
        <PageHeaderTitle className="text-2xl font-bold">{t('counting.create.title')}</PageHeaderTitle>
        <p className={colorTokens.text.subtle}>{t('counting.create.description')}</p>
      </div>

      {/* Progress Steps */}
      <div className="mb-8">
        <div className="flex items-center justify-between">
          {STEPS.map((step, index) => {
            const isActive = index === stepIndex
            const isCompleted = index < stepIndex
            return (
              <div key={step} className="flex items-center flex-1">
                <div
                  className={cn(
                    'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium',
                    isActive && `${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse}`,
                    isCompleted && `${colorTokens.intent.success.bgStrong} ${colorTokens.text.inverse}`,
                    !isActive && !isCompleted && `${colorTokens.surface.subdued} ${colorTokens.text.muted}`
                  )}
                >
                  {isCompleted ? <Check className="w-4 h-4" /> : index + 1}
                </div>
                <span
                  className={cn(
                    'ms-2 text-sm font-medium',
                    isActive && colorTokens.intent.primary.text,
                    isCompleted && colorTokens.intent.success.text,
                    !isActive && !isCompleted && colorTokens.text.subtle
                  )}
                >
                  {t(`counting.create.steps.${step}`)}
                </span>
                {index < STEPS.length - 1 && (
                  <div
                    className={cn(
                      'flex-1 h-0.5 mx-4',
                      isCompleted ? colorTokens.intent.success.bgStrong : colorTokens.surface.subdued
                    )}
                  />
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Step Content */}
      <div className={`${colorTokens.surface.base} rounded-lg border p-6 mb-6`}>
        {currentStep === 'scope' && (
          <ScopeStep
            scopeType={formData.scope_type || 'full_inventory'}
            onChange={(scope_type) => { setFormData({ ...formData, scope_type }); }}
          />
        )}

        {currentStep === 'selection' && needsSelectionStep() && (
          <ProductSelectionStep
            scopeType={formData.scope_type || 'full_inventory'}
            data={formData}
            onChange={(updates) => { setFormData({ ...formData, ...updates }); }}
          />
        )}

        {currentStep === 'configuration' && (
          <ConfigurationStep
            data={formData}
            onChange={(updates) => { setFormData({ ...formData, ...updates }); }}
          />
        )}

        {currentStep === 'assignment' && (
          <AssignmentStep
            data={formData}
            onChange={(updates) => { setFormData({ ...formData, ...updates }); }}
          />
        )}

        {currentStep === 'review' && <ReviewStep data={formData} />}
      </div>

      {/* Navigation */}
      <div className="flex justify-between">
        <button
          type="button"
          onClick={prevStep}
          disabled={stepIndex === 0}
          className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-md ${colorTokens.intent.neutral.bgHover} disabled:opacity-50 disabled:cursor-not-allowed`}
        >
          <ArrowLeft className="w-4 h-4 me-2" />
          {t('previous')}
        </button>

        {currentStep === 'review' ? (
          <button
            type="button"
            onClick={createCountingSession}
            disabled={!canProceed() || createCounting.isPending}
            className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
          >
            {createCounting.isPending
              ? t('creating')
              : t('counting.create.submit')}
          </button>
        ) : (
          <button
            type="button"
            onClick={nextStep}
            disabled={!canProceed()}
            className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
          >
            {t('next')}
            <ArrowRight className="w-4 h-4 ms-2" />
          </button>
        )}
      </div>
    </div>
  )
}

// Step Components
interface ScopeStepProps {
  scopeType: CountingScopeType
  onChange: (scopeType: CountingScopeType) => void
}

function ScopeStep({ scopeType, onChange }: ScopeStepProps) {
  const { t } = useTranslation('inventory')

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">
        {t('counting.create.scopeTitle')}
      </h2>
      <p className={`${colorTokens.text.subtle} mb-6`}>{t('counting.create.scopeDescription')}</p>

      <div className="grid grid-cols-2 gap-4">
        {SCOPE_TYPES.map((type) => (
          <button
            key={type}
            type="button"
            onClick={() => { onChange(type); }}
            className={cn(
              'p-4 rounded-lg border-2 text-start transition-colors',
              scopeType === type
                ? `${colorTokens.intent.primary.borderStrong} ${colorTokens.intent.primary.bgSubtle}`
                : `${colorTokens.border.subtle} ${colorTokens.border.hover}`
            )}
          >
            <div className="font-medium">{t(`counting.scopeTypes.${type}`)}</div>
            <div className={`text-sm ${colorTokens.text.subtle}`}>
              {t(`counting.scopeDescriptions.${type}`)}
            </div>
          </button>
        ))}
      </div>
    </div>
  )
}

interface ProductSelectionStepProps {
  scopeType: CountingScopeType
  data: Partial<CreateCountingFormData>
  onChange: (updates: Partial<CreateCountingFormData>) => void
}

function ProductSelectionStep({ scopeType, data, onChange }: ProductSelectionStepProps) {
  const { t } = useTranslation(['inventory', 'products', 'locations'])
  const [selectedProducts, setSelectedProducts] = useState<ProductLineProduct[]>([])

  const productIds = data.scope_filters?.product_ids ?? []

  const handleProductsChange = (productIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        product_ids: productIds,
      },
    })
  }

  const handleAddProduct = (product: ProductLineProduct) => {
    if (productIds.includes(product.id)) return
    setSelectedProducts((current) => [...current, product])
    handleProductsChange([...productIds, product.id])
  }

  const handleRemoveProduct = (productId: string) => {
    setSelectedProducts((current) => current.filter((product) => product.id !== productId))
    handleProductsChange(productIds.filter((id) => id !== productId))
  }

  const handleLocationsChange = (locationIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        location_ids: locationIds,
      },
    })
  }

  // product_location scope pins the products to a single location. Mirror the
  // zone scope's single-location pattern (LocationSelectorMulti maxSelection=1):
  // the payload carries scope_filters.location_id — clear it on deselection so a
  // stale id never leaks into the create request the backend 422s without it.
  const handleProductLocationChange = (locationIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        location_id: locationIds[0],
      },
    })
  }

  // Determine title and description based on scope type
  const getTitle = () => {
    switch (scopeType) {
      case 'product':
        return t('products:selectProducts')
      case 'product_location':
        return t('products:selectProducts')
      case 'location':
        return t('locations:selectLocations')
      case 'category':
        return t('counting.create.selectionTitleCategory')
      case 'zone':
        return t('counting.create.selectionTitleZone')
      default:
        return t('counting.create.selection')
    }
  }

  const getDescription = () => {
    switch (scopeType) {
      case 'product':
        return t('counting.create.selectionDescriptionProduct')
      case 'product_location':
        return t('counting.create.selectionDescriptionProductLocation')
      case 'location':
        return t('counting.create.selectionDescriptionLocation')
      case 'category':
        return t('counting.create.selectionDescriptionCategory')
      case 'zone':
        return t('counting.create.selectionDescriptionZone')
      default:
        return ''
    }
  }

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">{getTitle()}</h2>
      <p className={`${colorTokens.text.subtle} mb-6`}>{getDescription()}</p>

      {/* Product Selection */}
      {(scopeType === 'product' || scopeType === 'product_location') && (
        <div className="space-y-4">
          <LineItemEntryBar
            onAddProduct={handleAddProduct}
            onNotFound={() => undefined}
          />
          <p className={`text-sm ${textColors.tertiary}`}>{t('counting.create.productSelectionHelper')}</p>

          {productIds.length > 0 && (
            <div className={`rounded-md border ${borderColors.light} ${colors.neutral[50]} p-3`}>
              <div className={`mb-2 text-sm font-medium ${textColors.secondary}`}>
                {t('products:selectedProducts', { count: productIds.length })}
              </div>
              <div className="space-y-2">
                {selectedProducts.map((product) => (
                  <div
                    key={product.id}
                    className={`flex items-center gap-3 rounded-md border ${borderColors.light} ${colors.white} p-2`}
                  >
                    <div className="min-w-0 flex-1">
                      <ProductCell product={product} size="sm" />
                    </div>
                    <Button
                      type="button"
                      onClick={() => { handleRemoveProduct(product.id); }}
                      variant="ghost"
                      size="sm"
                      className={textColors.error}
                      aria-label={t('common:remove')}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Product + Location Selection: a single location the products are counted at */}
      {scopeType === 'product_location' && (
        <div className="mt-6">
          <LocationSelectorMulti
            value={data.scope_filters?.location_id ? [data.scope_filters.location_id] : []}
            onChange={handleProductLocationChange}
            maxSelection={1}
            label={t('counting.create.productLocationLabel')}
            helperText={t('counting.create.productLocationHelper')}
          />
        </div>
      )}

      {/* Location Selection */}
      {scopeType === 'location' && (
        <LocationSelectorMulti
          value={data.scope_filters?.location_ids ?? []}
          onChange={handleLocationsChange}
          helperText={t('counting.create.locationSelectionHelper')}
        />
      )}

      {/* Category Selection */}
      {scopeType === 'category' && (
        <CategorySelector
          value={(data.scope_filters?.category_ids ?? []).map(Number)}
          onChange={(categoryIds) => {
            onChange({
              scope_filters: {
                ...data.scope_filters,
                category_ids: categoryIds.map(String),
              },
            })
          }}
          label={t('counting.create.selectionTitleCategory')}
          helperText={t('counting.create.selectionDescriptionCategory')}
        />
      )}

      {/* Node / Zone Selection: single location, then hierarchy nodes of that location */}
      {scopeType === 'zone' && <NodeScopeSelection data={data} onChange={onChange} />}
    </div>
  )
}

interface NodeScopeSelectionProps {
  data: Partial<CreateCountingFormData>
  onChange: (updates: Partial<CreateCountingFormData>) => void
}

function NodeScopeSelection({ data, onChange }: NodeScopeSelectionProps) {
  const { t } = useTranslation(['inventory', 'locations'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const locationId = data.scope_filters?.location_id ?? ''
  const nodeIds = data.scope_filters?.zone_ids ?? []

  const { data: nodes, isLoading } = useQuery({
    queryKey: tenantScopedKey(['placement', 'nodes', locationId]),
    queryFn: () => listLocationNodes(locationId),
    enabled: locationId !== '' && tenantId !== null && companyId !== null,
  })

  const handleLocationChange = (locationIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        location_id: locationIds[0],
        // Zones belong to a single location — reset the selection whenever
        // the location changes so stale zone ids never leak across locations.
        zone_ids: [],
      },
    })
  }

  const handleToggleNode = (nodeId: string) => {
    const next = nodeIds.includes(nodeId)
      ? nodeIds.filter((id) => id !== nodeId)
      : [...nodeIds, nodeId]
    onChange({
      scope_filters: {
        ...data.scope_filters,
        zone_ids: next,
      },
    })
  }

  const handleNodeSelection = (selectedIds: ReadonlySet<string>) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        location_id: locationId,
        zone_ids: [...selectedIds],
      },
    })
  }

  return (
    <div className="space-y-6">
      <LocationSelectorMulti
        value={locationId ? [locationId] : []}
        onChange={handleLocationChange}
        maxSelection={1}
        label={t('counting.create.zoneLocationLabel')}
        helperText={t('counting.create.zoneLocationHelper')}
      />

      {locationId !== '' && (
        <div>
          <p className={cn('mb-2 text-sm font-medium', textColors.secondary)}>
            {t('counting.create.zoneSelectLabel')}
          </p>

          {isLoading ? (
            <p className={cn('text-sm', textColors.tertiary)}>{t('counting.create.zoneLoadingZones')}</p>
          ) : (nodes ?? []).length === 0 ? (
            <p className={cn('text-sm', textColors.tertiary)}>{t('counting.create.noZonesForLocation')}</p>
          ) : (
            <div className={cn('rounded-lg border', borderColors.default)}>
              <NodePicker
                nodes={nodes ?? []}
                selectedId={nodeIds.length === 1 ? nodeIds[0] : null}
                onSelect={(node) => { handleToggleNode(node.id) }}
                selectedIds={new Set(nodeIds)}
                onSelectionChange={handleNodeSelection}
                label={t('counting.create.zoneSelectLabel')}
              />
            </div>
          )}

          <p className={cn('mt-2 text-sm', textColors.tertiary)}>
            {t('counting.create.zoneSelectionHelper')}
          </p>
        </div>
      )}
    </div>
  )
}

interface ConfigurationStepProps {
  data: Partial<CreateCountingFormData>
  onChange: (updates: Partial<CreateCountingFormData>) => void
}

function ConfigurationStep({ data, onChange }: ConfigurationStepProps) {
  const { t } = useTranslation('inventory')

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">
        {t('counting.create.configTitle')}
      </h2>
      <p className={`${colorTokens.text.subtle} mb-6`}>
        {t('counting.create.configDescription')}
      </p>

      <div className="space-y-6">
        {/* Execution Mode */}
        <div>
          <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            {t('counting.create.executionMode')}
          </label>
          <div className="flex gap-4">
            <label className="flex items-center">
              <input
                type="radio"
                name="execution_mode"
                value="parallel"
                checked={data.execution_mode === 'parallel'}
                onChange={(e) =>
                  { onChange({
                    execution_mode: e.target.value as CountingExecutionMode,
                  }); }
                }
                className="me-2"
              />
              <span>{t('counting.executionModes.parallel')}</span>
            </label>
            <label className="flex items-center">
              <input
                type="radio"
                name="execution_mode"
                value="sequential"
                checked={data.execution_mode === 'sequential'}
                onChange={(e) =>
                  { onChange({
                    execution_mode: e.target.value as CountingExecutionMode,
                  }); }
                }
                className="me-2"
              />
              <span>{t('counting.executionModes.sequential')}</span>
            </label>
          </div>
        </div>

        {/* Count Requirements */}
        <div>
          <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            {t('counting.create.countRequirements')}
          </label>
          <div className="space-y-2">
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={data.requires_count_2}
                onChange={(e) =>
                  { onChange({ requires_count_2: e.target.checked }); }
                }
                className="me-2 rounded"
              />
              <span>{t('counting.create.requiresCount2')}</span>
            </label>
            <label className="flex items-center">
              <input
                type="checkbox"
                checked={data.requires_count_3}
                onChange={(e) =>
                  { onChange({ requires_count_3: e.target.checked }); }
                }
                className="me-2 rounded"
              />
              <span>{t('counting.create.requiresCount3')}</span>
            </label>
          </div>
        </div>

        {/* Unexpected Items */}
        <div>
          <label className="flex items-center">
            <input
              type="checkbox"
              checked={data.allow_unexpected_items}
              onChange={(e) =>
                { onChange({ allow_unexpected_items: e.target.checked }); }
              }
              className="me-2 rounded"
            />
            <span>{t('counting.create.allowUnexpectedItems')}</span>
          </label>
          <p className={`text-sm ${colorTokens.text.subtle} ms-6`}>
            {t('counting.create.allowUnexpectedItemsHelp')}
          </p>
        </div>

        {/* Block Sales During Count */}
        <div>
          <label className="flex items-center">
            <input
              type="checkbox"
              checked={isBlockSalesEnforced(data.scope_type) && !!data.block_sales}
              disabled={!isBlockSalesEnforced(data.scope_type)}
              onChange={(e) => { onChange({ block_sales: e.target.checked }); }}
              className="me-2 rounded disabled:cursor-not-allowed disabled:opacity-50"
            />
            <span>{t('counting.create.blockSales')}</span>
          </label>
          <p className={cn('text-sm ms-6', textColors.tertiary)}>
            {/* Gate r1 FE IMPORTANT-1: product and category join zone as scopes
                the block engine never enforces. Zone keeps its own hint (it
                explains the timestamp-replay reconciliation specific to node
                counts); the others get the generic scope hint. */}
            {data.scope_type === 'zone'
              ? t('counting.create.blockSalesZoneDisabledHint')
              : isBlockSalesEnforced(data.scope_type)
                ? t('counting.create.blockSalesHelp')
                : t('counting.create.blockSalesScopeDisabledHint')}
          </p>
        </div>

        {/* Ambiguity Window */}
        <div>
          <label
            htmlFor="ambiguity-window-minutes"
            className={cn('mb-2 block text-sm font-medium', textColors.secondary)}
          >
            {t('counting.create.ambiguityWindowMinutes')}
          </label>
          <input
            id="ambiguity-window-minutes"
            type="number"
            min={0}
            step={1}
            value={data.ambiguity_window_minutes ?? DEFAULT_AMBIGUITY_WINDOW_MINUTES}
            onChange={(e) => {
              const parsed = Number.parseInt(e.target.value, 10)
              onChange({ ambiguity_window_minutes: Number.isNaN(parsed) ? 0 : parsed })
            }}
            className={cn(
              `w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing}`,
              borderColors.default,
              colorTokens.focus.primaryBorder,
            )}
          />
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>
            {t('counting.create.ambiguityWindowMinutesHelp')}
          </p>
        </div>

        {/* Instructions */}
        <div>
          <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            {t('counting.create.instructions')}
          </label>
          <textarea
            value={data.instructions || ''}
            onChange={(e) => { onChange({ instructions: e.target.value }); }}
            rows={4}
            className={`w-full px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
            placeholder={t('counting.create.instructionsPlaceholder')}
          />
        </div>
      </div>
    </div>
  )
}

interface AssignmentStepProps {
  data: Partial<CreateCountingFormData>
  onChange: (updates: Partial<CreateCountingFormData>) => void
}

function AssignmentStep({ data, onChange }: AssignmentStepProps) {
  const { t } = useTranslation('inventory')

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">
        {t('counting.create.assignmentTitle')}
      </h2>
      <p className={`${colorTokens.text.subtle} mb-6`}>
        {t('counting.create.assignmentDescription')}
      </p>

      <div className="space-y-6">
        {/* Counter 1 */}
        <UserSelector
          value={data.count_1_user_id ?? null}
          onChange={(userId) => {
            if (userId) {
              onChange({ count_1_user_id: userId })
            }
          }}
          label={t('counting.create.counter1')}
          required
          helperText={t('counting.create.counter1Help')}
          excludeUserIds={[data.count_2_user_id, data.count_3_user_id].filter(
            (id): id is string => Boolean(id)
          )}
        />

        {/* Counter 2 */}
        {data.requires_count_2 && (
          <UserSelector
            value={data.count_2_user_id ?? null}
            onChange={(userId) => {
              if (userId) {
                onChange({ count_2_user_id: userId })
              }
            }}
            label={t('counting.create.counter2')}
            required={data.requires_count_2}
            excludeUserIds={[data.count_1_user_id, data.count_3_user_id].filter(
              (id): id is string => Boolean(id)
            )}
          />
        )}

        {/* Counter 3 */}
        {data.requires_count_3 && (
          <UserSelector
            value={data.count_3_user_id ?? null}
            onChange={(userId) => {
              if (userId) {
                onChange({ count_3_user_id: userId })
              }
            }}
            label={t('counting.create.counter3')}
            required={data.requires_count_3}
            excludeUserIds={[data.count_1_user_id, data.count_2_user_id].filter(
              (id): id is string => Boolean(id)
            )}
          />
        )}

        {/* Schedule */}
        <div className="grid grid-cols-2 gap-4 pt-4 border-t">
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('counting.create.scheduledStart')}
            </label>
            <input
              type="datetime-local"
              value={data.scheduled_start || ''}
              onChange={(e) => { onChange({ scheduled_start: e.target.value }); }}
              className={`w-full px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
            />
          </div>
          <div>
            <label className={`block text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('counting.create.scheduledEnd')}
            </label>
            <input
              type="datetime-local"
              value={data.scheduled_end || ''}
              onChange={(e) => { onChange({ scheduled_end: e.target.value }); }}
              className={`w-full px-3 py-2 border ${colorTokens.border.default} rounded-md focus:outline-none focus:ring-2 ${colorTokens.focus.primaryRing} ${colorTokens.focus.primaryBorder}`}
            />
          </div>
        </div>
      </div>
    </div>
  )
}

interface ReviewStepProps {
  data: Partial<CreateCountingFormData>
}

function ReviewStep({ data }: ReviewStepProps) {
  const { t } = useTranslation('inventory')
  const { data: usersData } = useUsers()

  const getUserName = (userId: string | undefined): string => {
    if (!userId) return '-'
    const user = usersData?.data?.find((u) => u.id === userId)
    return user?.name ?? userId.slice(0, 8) + '...'
  }

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">
        {t('counting.create.reviewTitle')}
      </h2>
      <p className={`${colorTokens.text.subtle} mb-6`}>
        {t('counting.create.reviewDescription')}
      </p>

      <div className="space-y-4">
        <div className={`${colorTokens.surface.page} rounded-lg p-4`}>
          <h3 className="font-medium mb-3">{t('counting.create.steps.scope')}</h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className={colorTokens.text.subtle}>{t('counting.create.scopeType')}</dt>
            <dd>{t(`counting.scopeTypes.${data.scope_type ?? 'full_inventory'}`)}</dd>
          </dl>
        </div>

        <div className={`${colorTokens.surface.page} rounded-lg p-4`}>
          <h3 className="font-medium mb-3">
            {t('counting.create.steps.configuration')}
          </h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className={colorTokens.text.subtle}>{t('counting.create.executionMode')}</dt>
            <dd>{t(`counting.executionModes.${data.execution_mode ?? 'parallel'}`)}</dd>
            <dt className={colorTokens.text.subtle}>{t('counting.create.requiresCount2')}</dt>
            <dd>{data.requires_count_2 ? t('yes') : t('no')}</dd>
            <dt className={colorTokens.text.subtle}>{t('counting.create.requiresCount3')}</dt>
            <dd>{data.requires_count_3 ? t('yes') : t('no')}</dd>
            <dt className={colorTokens.text.subtle}>{t('counting.create.allowUnexpectedItems')}</dt>
            <dd>
              {data.allow_unexpected_items ? t('yes') : t('no')}
            </dd>
            {/* N-1/A-8: the two fields that decide how the shop keeps trading
                during the count were summarised nowhere. zone / product /
                category all force block_sales to false — the block engine never
                enforces them and the backend 422s otherwise (see
                isBlockSalesEnforced) — so the review reports the value that will
                actually be SUBMITTED, not the raw toggle state. */}
            <dt className={colorTokens.text.subtle}>{t('counting.create.blockSales')}</dt>
            <dd data-testid="review-block-sales">
              {isBlockSalesEnforced(data.scope_type) && data.block_sales ? t('yes') : t('no')}
            </dd>
            <dt className={colorTokens.text.subtle}>
              {t('counting.create.ambiguityWindowMinutes')}
            </dt>
            <dd data-testid="review-ambiguity-window">
              {data.ambiguity_window_minutes ?? DEFAULT_AMBIGUITY_WINDOW_MINUTES}
            </dd>
          </dl>
        </div>

        <div className={`${colorTokens.surface.page} rounded-lg p-4`}>
          <h3 className="font-medium mb-3">
            {t('counting.create.steps.assignment')}
          </h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className={colorTokens.text.subtle}>{t('counting.create.counter1')}</dt>
            <dd>{getUserName(data.count_1_user_id)}</dd>
            {data.requires_count_2 && (
              <>
                <dt className={colorTokens.text.subtle}>{t('counting.create.counter2')}</dt>
                <dd>{getUserName(data.count_2_user_id)}</dd>
              </>
            )}
            {data.requires_count_3 && (
              <>
                <dt className={colorTokens.text.subtle}>{t('counting.create.counter3')}</dt>
                <dd>{getUserName(data.count_3_user_id)}</dd>
              </>
            )}
          </dl>
        </div>
      </div>
    </div>
  )
}
