import { useTranslation } from 'react-i18next'
import { useFieldArray, type Control, type UseFormRegister, type FieldErrors } from 'react-hook-form'
import { Plus, X } from 'lucide-react'

 
interface ParapharmacyMetadataFieldsProps {
  control: Control<any>
  register: UseFormRegister<any>
  errors: FieldErrors<any>
}

export function ParapharmacyMetadataFields({
  control,
  register,
  errors,
}: ParapharmacyMetadataFieldsProps) {
  const { t } = useTranslation(['products'])

  const { fields: ingredientFields, append: appendIngredient, remove: removeIngredient } = useFieldArray({
    control: control as Control<Record<string, unknown>>,
    name: 'parapharmacy_metadata.active_ingredients' as never,
  })

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h2 className="mb-4 text-lg font-semibold text-gray-900">
        {t('products:parapharmacy.title')}
      </h2>

      <div className="space-y-6">
        {/* Category - Required */}
        <div>
          <label htmlFor="parapharmacy_category" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.category')} *
          </label>
          <select
            id="parapharmacy_category"
            {...register('parapharmacy_metadata.category', { required: t('validation:required') || 'Required' })}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">{t('common:actions.select')}</option>
            <option value="supplement">{t('products:parapharmacy.categories.supplement')}</option>
            <option value="cosmetic">{t('products:parapharmacy.categories.cosmetic')}</option>
            <option value="medical_device">{t('products:parapharmacy.categories.medical_device')}</option>
            <option value="herbal">{t('products:parapharmacy.categories.herbal')}</option>
            <option value="baby_care">{t('products:parapharmacy.categories.baby_care')}</option>
            <option value="sports_nutrition">{t('products:parapharmacy.categories.sports_nutrition')}</option>
            <option value="other">{t('products:parapharmacy.categories.other')}</option>
          </select>
          {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
          {(errors as any)?.parapharmacy_metadata?.category && (
            <p className="mt-1 text-sm text-red-600">
              {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
              {(errors as any).parapharmacy_metadata.category.message}
            </p>
          )}
        </div>

        {/* Dosage Form */}
        <div>
          <label htmlFor="dosage_form" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.dosageForm')}
          </label>
          <select
            id="dosage_form"
            {...register('parapharmacy_metadata.dosage_form')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">{t('common:actions.select')}</option>
            <option value="capsule">{t('products:parapharmacy.dosageForms.capsule')}</option>
            <option value="tablet">{t('products:parapharmacy.dosageForms.tablet')}</option>
            <option value="softgel">{t('products:parapharmacy.dosageForms.softgel')}</option>
            <option value="liquid">{t('products:parapharmacy.dosageForms.liquid')}</option>
            <option value="powder">{t('products:parapharmacy.dosageForms.powder')}</option>
            <option value="cream">{t('products:parapharmacy.dosageForms.cream')}</option>
            <option value="gel">{t('products:parapharmacy.dosageForms.gel')}</option>
            <option value="lotion">{t('products:parapharmacy.dosageForms.lotion')}</option>
            <option value="spray">{t('products:parapharmacy.dosageForms.spray')}</option>
            <option value="patch">{t('products:parapharmacy.dosageForms.patch')}</option>
            <option value="other">{t('products:parapharmacy.dosageForms.other')}</option>
          </select>
        </div>

        {/* Active Ingredients */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('products:parapharmacy.activeIngredients')}
          </label>
          <div className="space-y-2">
            {ingredientFields.map((field, index) => (
              <div key={field.id} className="flex gap-2">
                <input
                  type="text"
                  {...register(`parapharmacy_metadata.active_ingredients.${index}.name`)}
                  placeholder={t('products:parapharmacy.ingredientName')}
                  className="flex-1 rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                <input
                  type="text"
                  {...register(`parapharmacy_metadata.active_ingredients.${index}.concentration`)}
                  placeholder={t('products:parapharmacy.concentration')}
                  className="w-40 rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                <button
                  type="button"
                  onClick={() => { removeIngredient(index) }}
                  className="inline-flex items-center rounded-lg border border-gray-300 bg-white p-2 text-gray-400 hover:bg-gray-50 hover:text-gray-600"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
          <button
            type="button"
            onClick={() => { appendIngredient({ name: '', concentration: '' }) }}
            className="mt-2 inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800"
          >
            <Plus className="h-4 w-4" />
            {t('products:parapharmacy.addIngredient')}
          </button>
        </div>

        {/* Usage Instructions */}
        <div>
          <label htmlFor="usage_instructions" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.usageInstructions')}
          </label>
          <textarea
            id="usage_instructions"
            rows={3}
            {...register('parapharmacy_metadata.usage_instructions')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Warnings */}
        <div>
          <label htmlFor="warnings" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.warnings')}
          </label>
          <textarea
            id="warnings"
            rows={3}
            {...register('parapharmacy_metadata.warnings')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Contraindications */}
        <div>
          <label htmlFor="contraindications" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.contraindications')}
          </label>
          <textarea
            id="contraindications"
            rows={3}
            {...register('parapharmacy_metadata.contraindications')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Age Information */}
        <div className="grid gap-6 sm:grid-cols-2">
          <div>
            <label htmlFor="minimum_age" className="block text-sm font-medium text-gray-700">
              {t('products:parapharmacy.minimumAge')}
            </label>
            <input
              type="number"
              id="minimum_age"
              min="0"
              step="1"
              {...register('parapharmacy_metadata.minimum_age', { valueAsNumber: true })}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label htmlFor="age_restriction" className="block text-sm font-medium text-gray-700">
              {t('products:parapharmacy.ageRestriction')}
            </label>
            <select
              id="age_restriction"
              {...register('parapharmacy_metadata.age_restriction')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="">{t('common:actions.select')}</option>
              <option value="adult_only">{t('products:parapharmacy.ageRestrictions.adult_only')}</option>
              <option value="children_only">{t('products:parapharmacy.ageRestrictions.children_only')}</option>
              <option value="all_ages">{t('products:parapharmacy.ageRestrictions.all_ages')}</option>
            </select>
          </div>
        </div>

        {/* Requires Consultation */}
        <div className="flex items-center gap-2">
          <input
            type="checkbox"
            id="requires_consultation"
            {...register('parapharmacy_metadata.requires_consultation')}
            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <label htmlFor="requires_consultation" className="text-sm font-medium text-gray-700">
            {t('products:parapharmacy.requiresConsultation')}
          </label>
        </div>

        {/* Regulatory Code */}
        <div>
          <label htmlFor="regulatory_code" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.regulatoryCode')}
          </label>
          <input
            type="text"
            id="regulatory_code"
            {...register('parapharmacy_metadata.regulatory_code')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>

        {/* Storage Requirements */}
        <div>
          <label htmlFor="storage_requirements" className="block text-sm font-medium text-gray-700">
            {t('products:parapharmacy.storageRequirements')}
          </label>
          <textarea
            id="storage_requirements"
            rows={2}
            {...register('parapharmacy_metadata.storage_requirements')}
            className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </div>
      </div>
    </div>
  )
}
