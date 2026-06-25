import { useTranslation } from 'react-i18next'
import { useFieldArray, type Control, type UseFormRegister, type FieldErrors } from 'react-hook-form'
import { Plus, X } from 'lucide-react'
import { Input, Select, Textarea } from '../../../components/atoms'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'


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
    <div className={tokens.card.base}>
      <h2 className={`mb-4 ${tokens.heading.section}`}>
        {t('products:parapharmacy.title')}
      </h2>

      {/* 3-column responsive grid */}
      <div className="grid grid-cols-1 gap-x-[18px] gap-y-4 sm:grid-cols-2 lg:grid-cols-3">

        {/* Row 1 col 1: Category - Required */}
        <div>
          <label htmlFor="parapharmacy_category" className={tokens.label.base}>
            {t('products:parapharmacy.category')} *
          </label>
          <Select
            id="parapharmacy_category"
            {...register('parapharmacy_metadata.category', { required: t('validation:required') || 'Required' })}
          >
            <option value="">{t('common:actions.select')}</option>
            <option value="supplement">{t('products:parapharmacy.categories.supplement')}</option>
            <option value="cosmetic">{t('products:parapharmacy.categories.cosmetic')}</option>
            <option value="medical_device">{t('products:parapharmacy.categories.medical_device')}</option>
            <option value="herbal">{t('products:parapharmacy.categories.herbal')}</option>
            <option value="baby_care">{t('products:parapharmacy.categories.baby_care')}</option>
            <option value="sports_nutrition">{t('products:parapharmacy.categories.sports_nutrition')}</option>
            <option value="other">{t('products:parapharmacy.categories.other')}</option>
          </Select>
          {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
          {(errors as any)?.parapharmacy_metadata?.category && (
            <p className={`mt-1 text-sm ${textColors.error}`}>
              {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
              {(errors as any).parapharmacy_metadata.category.message}
            </p>
          )}
        </div>

        {/* Row 1 col 2: Dosage Form */}
        <div>
          <label htmlFor="dosage_form" className={tokens.label.base}>
            {t('products:parapharmacy.dosageForm')}
          </label>
          <Select
            id="dosage_form"
            {...register('parapharmacy_metadata.dosage_form')}
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
          </Select>
        </div>

        {/* Row 1 col 3: Age Restriction */}
        <div>
          <label htmlFor="age_restriction" className={tokens.label.base}>
            {t('products:parapharmacy.ageRestriction')}
          </label>
          <Select
            id="age_restriction"
            {...register('parapharmacy_metadata.age_restriction')}
          >
            <option value="">{t('common:actions.select')}</option>
            <option value="adult_only">{t('products:parapharmacy.ageRestrictions.adult_only')}</option>
            <option value="children_only">{t('products:parapharmacy.ageRestrictions.children_only')}</option>
            <option value="all_ages">{t('products:parapharmacy.ageRestrictions.all_ages')}</option>
          </Select>
        </div>

        {/* Row 2: Active Ingredients — full width */}
        <div className="col-span-1 sm:col-span-2 lg:col-span-3">
          <label className={`${tokens.label.base} mb-2`}>
            {t('products:parapharmacy.activeIngredients')}
          </label>
          <div className="space-y-2">
            {ingredientFields.map((field, index) => (
              <div key={field.id} className="flex gap-2">
                <Input
                  type="text"
                  {...register(`parapharmacy_metadata.active_ingredients.${index}.name`)}
                  placeholder={t('products:parapharmacy.ingredientName')}
                  className="flex-1 w-auto"
                />
                <Input
                  type="text"
                  {...register(`parapharmacy_metadata.active_ingredients.${index}.concentration`)}
                  placeholder={t('products:parapharmacy.concentration')}
                  className="w-40"
                />
                <button
                  type="button"
                  onClick={() => { removeIngredient(index) }}
                  className={`inline-flex items-center rounded-[var(--radius-button)] border ${borderColors.default} ${colors.white} p-2 ${textColors.disabled} ${colors.hover.gray50} ${textColors.hoverSecondary}`}
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
          <button
            type="button"
            onClick={() => { appendIngredient({ name: '', concentration: '' }) }}
            className={`mt-2 inline-flex items-center gap-1 text-sm ${textColors.brand} ${textColors.hoverBrand}`}
          >
            <Plus className="h-4 w-4" />
            {t('products:parapharmacy.addIngredient')}
          </button>
        </div>

        {/* Row 3: Usage Instructions — full width */}
        <div className="col-span-1 sm:col-span-2 lg:col-span-3">
          <label htmlFor="usage_instructions" className={tokens.label.base}>
            {t('products:parapharmacy.usageInstructions')}
          </label>
          <Textarea
            id="usage_instructions"
            rows={3}
            {...register('parapharmacy_metadata.usage_instructions')}
          />
        </div>

        {/* Row 4 cols 1-2: Warnings */}
        <div className="col-span-1 sm:col-span-2">
          <label htmlFor="warnings" className={tokens.label.base}>
            {t('products:parapharmacy.warnings')}
          </label>
          <Textarea
            id="warnings"
            rows={3}
            {...register('parapharmacy_metadata.warnings')}
          />
        </div>

        {/* Row 4 col 3: Minimum Age + Requires Consultation */}
        <div className="col-span-1 flex flex-col gap-4">
          <div>
            <label htmlFor="minimum_age" className={tokens.label.base}>
              {t('products:parapharmacy.minimumAge')}
            </label>
            <Input
              type="number"
              id="minimum_age"
              min="0"
              step="1"
              {...register('parapharmacy_metadata.minimum_age', { valueAsNumber: true })}
            />
          </div>

          {/* Requires Consultation — paired with min_age in col 3 */}
          <div className="flex items-center gap-2 mt-auto">
            <input
              type="checkbox"
              id="requires_consultation"
              {...register('parapharmacy_metadata.requires_consultation')}
              className={tokens.checkbox.base}
            />
            <label htmlFor="requires_consultation" className={tokens.label.base}>
              {t('products:parapharmacy.requiresConsultation')}
            </label>
          </div>
        </div>

        {/* Row 5 col 1: Regulatory Code */}
        <div>
          <label htmlFor="regulatory_code" className={tokens.label.base}>
            {t('products:parapharmacy.regulatoryCode')}
          </label>
          <Input
            type="text"
            id="regulatory_code"
            {...register('parapharmacy_metadata.regulatory_code')}
          />
        </div>

        {/* Row 5 cols 2-3: Storage Requirements */}
        <div className="col-span-1 sm:col-span-2 lg:col-span-2">
          <label htmlFor="storage_requirements" className={tokens.label.base}>
            {t('products:parapharmacy.storageRequirements')}
          </label>
          <Textarea
            id="storage_requirements"
            rows={2}
            {...register('parapharmacy_metadata.storage_requirements')}
          />
        </div>

        {/* Contraindications — full width (kept from original, not in mock but field must be preserved) */}
        <div className="col-span-1 sm:col-span-2 lg:col-span-3">
          <label htmlFor="contraindications" className={tokens.label.base}>
            {t('products:parapharmacy.contraindications')}
          </label>
          <Textarea
            id="contraindications"
            rows={3}
            {...register('parapharmacy_metadata.contraindications')}
          />
        </div>

      </div>
    </div>
  )
}
