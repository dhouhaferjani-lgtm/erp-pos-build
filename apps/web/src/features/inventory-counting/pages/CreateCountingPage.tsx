import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowRight, Check } from 'lucide-react'
import { useCreateCounting } from '../api/queries'
import type {
  CountingScopeType,
  CountingExecutionMode,
  CreateCountingFormData,
} from '../types'
import { cn } from '@/lib/utils'
import { UserSelector } from '@/features/users/components/UserSelector'
import { ProductSelector } from '@/features/products/components/ProductSelector'
import { LocationSelectorMulti } from '@/features/locations/components/LocationSelectorMulti'

const STEPS = ['scope', 'selection', 'configuration', 'assignment', 'review'] as const
type Step = (typeof STEPS)[number]

const SCOPE_TYPES: CountingScopeType[] = [
  'full_inventory',
  'warehouse',
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
  })

  const stepIndex = STEPS.indexOf(currentStep)

  // Determine if selection step should be shown based on scope type
  const needsSelectionStep = () => {
    const scopeType = formData.scope_type
    return scopeType === 'product' ||
           scopeType === 'product_location' ||
           scopeType === 'location' ||
           scopeType === 'category'
  }

  const canProceed = () => {
    switch (currentStep) {
      case 'scope':
        return !!formData.scope_type
      case 'selection':
        // Validate selection based on scope type
        if (formData.scope_type === 'product' || formData.scope_type === 'product_location') {
          return (formData.scope_filters?.product_ids?.length ?? 0) > 0
        }
        if (formData.scope_type === 'location') {
          return (formData.scope_filters?.location_ids?.length ?? 0) > 0
        }
        // Category selection not implemented yet, allow proceeding
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

  const handleSubmit = () => {
    if (!formData.count_1_user_id) return

    createCounting.mutate(formData as CreateCountingFormData, {
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
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
        >
          <ArrowLeft className="w-4 h-4 me-1" />
          {t('back')}
        </Link>
        <h1 className="text-2xl font-bold">{t('counting.create.title')}</h1>
        <p className="text-gray-500">{t('counting.create.description')}</p>
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
                    isActive && 'bg-blue-600 text-white',
                    isCompleted && 'bg-green-600 text-white',
                    !isActive && !isCompleted && 'bg-gray-200 text-gray-600'
                  )}
                >
                  {isCompleted ? <Check className="w-4 h-4" /> : index + 1}
                </div>
                <span
                  className={cn(
                    'ms-2 text-sm font-medium',
                    isActive && 'text-blue-600',
                    isCompleted && 'text-green-600',
                    !isActive && !isCompleted && 'text-gray-500'
                  )}
                >
                  {t(`counting.create.steps.${step}`)}
                </span>
                {index < STEPS.length - 1 && (
                  <div
                    className={cn(
                      'flex-1 h-0.5 mx-4',
                      isCompleted ? 'bg-green-600' : 'bg-gray-200'
                    )}
                  />
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Step Content */}
      <div className="bg-white rounded-lg border p-6 mb-6">
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
          className="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <ArrowLeft className="w-4 h-4 me-2" />
          {t('previous')}
        </button>

        {currentStep === 'review' ? (
          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canProceed() || createCounting.isPending}
            className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
            className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
      <p className="text-gray-500 mb-6">{t('counting.create.scopeDescription')}</p>

      <div className="grid grid-cols-2 gap-4">
        {SCOPE_TYPES.map((type) => (
          <button
            key={type}
            type="button"
            onClick={() => { onChange(type); }}
            className={cn(
              'p-4 rounded-lg border-2 text-start transition-colors',
              scopeType === type
                ? 'border-blue-600 bg-blue-50'
                : 'border-gray-200 hover:border-gray-300'
            )}
          >
            <div className="font-medium">{t(`counting.scopeTypes.${type}`)}</div>
            <div className="text-sm text-gray-500">
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

  const handleProductsChange = (productIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        product_ids: productIds,
      },
    })
  }

  const handleLocationsChange = (locationIds: string[]) => {
    onChange({
      scope_filters: {
        ...data.scope_filters,
        location_ids: locationIds,
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
      default:
        return ''
    }
  }

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">{getTitle()}</h2>
      <p className="text-gray-500 mb-6">{getDescription()}</p>

      {/* Product Selection */}
      {(scopeType === 'product' || scopeType === 'product_location') && (
        <ProductSelector
          value={data.scope_filters?.product_ids ?? []}
          onChange={handleProductsChange}
          helperText={t('counting.create.productSelectionHelper')}
        />
      )}

      {/* Location Selection */}
      {scopeType === 'location' && (
        <LocationSelectorMulti
          value={data.scope_filters?.location_ids ?? []}
          onChange={handleLocationsChange}
          helperText={t('counting.create.locationSelectionHelper')}
        />
      )}

      {/* Category Selection - Placeholder for future implementation */}
      {scopeType === 'category' && (
        <div className="border border-yellow-200 bg-yellow-50 rounded-lg p-6 text-center">
          <div className="text-yellow-800 font-medium mb-2">
            {t('counting.create.categorySelectionComingSoon')}
          </div>
          <div className="text-sm text-yellow-700">
            {t('counting.create.categorySelectionComingSoonHint')}
          </div>
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
      <p className="text-gray-500 mb-6">
        {t('counting.create.configDescription')}
      </p>

      <div className="space-y-6">
        {/* Execution Mode */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
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
          <label className="block text-sm font-medium text-gray-700 mb-2">
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
          <p className="text-sm text-gray-500 ms-6">
            {t('counting.create.allowUnexpectedItemsHelp')}
          </p>
        </div>

        {/* Instructions */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('counting.create.instructions')}
          </label>
          <textarea
            value={data.instructions || ''}
            onChange={(e) => { onChange({ instructions: e.target.value }); }}
            rows={4}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
      <p className="text-gray-500 mb-6">
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
            <label className="block text-sm font-medium text-gray-700 mb-2">
              {t('counting.create.scheduledStart')}
            </label>
            <input
              type="datetime-local"
              value={data.scheduled_start || ''}
              onChange={(e) => { onChange({ scheduled_start: e.target.value }); }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              {t('counting.create.scheduledEnd')}
            </label>
            <input
              type="datetime-local"
              value={data.scheduled_end || ''}
              onChange={(e) => { onChange({ scheduled_end: e.target.value }); }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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

  return (
    <div>
      <h2 className="text-lg font-semibold mb-4">
        {t('counting.create.reviewTitle')}
      </h2>
      <p className="text-gray-500 mb-6">
        {t('counting.create.reviewDescription')}
      </p>

      <div className="space-y-4">
        <div className="bg-gray-50 rounded-lg p-4">
          <h3 className="font-medium mb-3">{t('counting.create.steps.scope')}</h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className="text-gray-500">{t('counting.create.scopeType')}</dt>
            <dd>{t(`counting.scopeTypes.${data.scope_type ?? 'full_inventory'}`)}</dd>
          </dl>
        </div>

        <div className="bg-gray-50 rounded-lg p-4">
          <h3 className="font-medium mb-3">
            {t('counting.create.steps.configuration')}
          </h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className="text-gray-500">{t('counting.create.executionMode')}</dt>
            <dd>{t(`counting.executionModes.${data.execution_mode ?? 'parallel'}`)}</dd>
            <dt className="text-gray-500">{t('counting.create.requiresCount2')}</dt>
            <dd>{data.requires_count_2 ? t('yes') : t('no')}</dd>
            <dt className="text-gray-500">{t('counting.create.requiresCount3')}</dt>
            <dd>{data.requires_count_3 ? t('yes') : t('no')}</dd>
            <dt className="text-gray-500">{t('counting.create.allowUnexpectedItems')}</dt>
            <dd>
              {data.allow_unexpected_items ? t('yes') : t('no')}
            </dd>
          </dl>
        </div>

        <div className="bg-gray-50 rounded-lg p-4">
          <h3 className="font-medium mb-3">
            {t('counting.create.steps.assignment')}
          </h3>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <dt className="text-gray-500">{t('counting.create.counter1')}</dt>
            <dd>{data.count_1_user_id || '-'}</dd>
            {data.requires_count_2 && (
              <>
                <dt className="text-gray-500">{t('counting.create.counter2')}</dt>
                <dd>{data.count_2_user_id || '-'}</dd>
              </>
            )}
            {data.requires_count_3 && (
              <>
                <dt className="text-gray-500">{t('counting.create.counter3')}</dt>
                <dd>{data.count_3_user_id || '-'}</dd>
              </>
            )}
          </dl>
        </div>
      </div>
    </div>
  )
}
