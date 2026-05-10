import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/components/atoms/Button/Button'
import { Input } from '@/components/atoms/Input/Input'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Select } from '@/components/atoms/Select/Select'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { TranslationEditor, type Translation } from '../components'
import {
  fetchIngredient,
  createIngredient,
  updateIngredient,
  type CreateIngredientInput,
} from '../api/ingredientApi'
import { parapharmacyListInvalidationPredicate } from './tenantScope'
import { toast } from 'sonner'

export function IngredientFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const isEdit = !!id && id !== 'new'

  const [slug, setSlug] = useState('')
  const [casNumber, setCasNumber] = useState('')
  const [isAllergen, setIsAllergen] = useState(false)
  const [allergenCode, setAllergenCode] = useState('')
  const [regulatoryStatus, setRegulatoryStatus] = useState<
    'approved' | 'restricted' | 'banned'
  >('approved')
  const [notes, setNotes] = useState('')
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', description: '' },
  ])

  const { data: ingredient, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'ingredients', id]),
    queryFn: () => fetchIngredient(id!),
    enabled: isEdit && !!tenantId && !!companyId,
  })

  useEffect(() => {
    if (ingredient) {
      setSlug(ingredient.slug)
      setCasNumber(ingredient.cas_number || '')
      setIsAllergen(ingredient.is_allergen)
      setAllergenCode(ingredient.allergen_code || '')
      setRegulatoryStatus(
        (ingredient.regulatory_status) ||
          'approved'
      )
      setNotes(ingredient.notes || '')
      setTranslations([
        {
          locale: 'en',
          name: ingredient.name,
          description: ingredient.description || '',
        },
      ])
    }
  }, [ingredient])

  const createMutation = useMutation({
    mutationFn: createIngredient,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'ingredients'),
      })
      toast.success(t('parapharmacy:ingredientCreated'))
      navigate('/parapharmacy/ingredients')
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createIngredientError')
      toast.error(message)
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: CreateIngredientInput) => updateIngredient(id!, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'ingredients'),
      })
      toast.success(t('parapharmacy:ingredientUpdated'))
      navigate('/parapharmacy/ingredients')
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateIngredientError')
      toast.error(message)
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const data: CreateIngredientInput = {
      slug,
      cas_number: casNumber || null,
      is_allergen: isAllergen,
      allergen_code: allergenCode || null,
      regulatory_status: regulatoryStatus,
      notes: notes || null,
      translations: translations.map((t) => ({
        id: t.id ?? '',
        locale: t.locale,
        name: t.name,
        description: t.description || null,
      })),
    }

    if (isEdit) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex items-center gap-4">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/parapharmacy/ingredients')}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          {t('common:back')}
        </Button>
        <div>
          <h1 className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editIngredient')
              : t('parapharmacy:addIngredient')}
          </h1>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">
              {t('parapharmacy:basicInformation')}
            </h2>
          </div>
          <div className="px-6 py-4 space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="slug"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:slug')} <span className="text-red-600">*</span>
                </label>
                <Input
                  id="slug"
                  value={slug}
                  onChange={(e) => { setSlug(e.target.value); }}
                  required
                  placeholder="vitamin-c-ascorbic-acid"
                />
                <p className="text-xs text-gray-500 mt-1">
                  {t('parapharmacy:slugHelp')}
                </p>
              </div>

              <div>
                <label
                  htmlFor="cas_number"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:casNumber')}
                </label>
                <Input
                  id="cas_number"
                  value={casNumber}
                  onChange={(e) => { setCasNumber(e.target.value); }}
                  placeholder="50-81-7"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="regulatory_status"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:regulatoryStatus')}{' '}
                  <span className="text-red-600">*</span>
                </label>
                <Select
                  id="regulatory_status"
                  value={regulatoryStatus}
                  onChange={(e) =>
                    { setRegulatoryStatus(
                      e.target.value as 'approved' | 'restricted' | 'banned'
                    ); }
                  }
                  required
                >
                  <option value="approved">
                    {t('parapharmacy:regulatoryStatus.approved')}
                  </option>
                  <option value="restricted">
                    {t('parapharmacy:regulatoryStatus.restricted')}
                  </option>
                  <option value="banned">
                    {t('parapharmacy:regulatoryStatus.banned')}
                  </option>
                </Select>
              </div>

              <div>
                <label
                  htmlFor="allergen_code"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:allergenCode')}
                </label>
                <Input
                  id="allergen_code"
                  value={allergenCode}
                  onChange={(e) => { setAllergenCode(e.target.value); }}
                  placeholder="EU14"
                  disabled={!isAllergen}
                />
              </div>
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_allergen"
                checked={isAllergen}
                onChange={(e) => { setIsAllergen(e.target.checked); }}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="is_allergen" className="text-sm text-gray-700">
                {t('parapharmacy:isAllergen')}
              </label>
            </div>

            <div>
              <label
                htmlFor="notes"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                {t('parapharmacy:notes')}
              </label>
              <Textarea
                id="notes"
                value={notes}
                onChange={(e) => { setNotes(e.target.value); }}
                rows={3}
                placeholder={t('parapharmacy:notesPlaceholder')}
              />
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">
              {t('parapharmacy:translations')}
            </h2>
          </div>
          <div className="px-6 py-4">
            <TranslationEditor
              translations={translations}
              onChange={setTranslations}
              fields={[
                {
                  name: 'name',
                  label: t('parapharmacy:name'),
                  required: true,
                },
                {
                  name: 'description',
                  label: t('parapharmacy:description'),
                  required: false,
                  multiline: true,
                },
              ]}
            />
          </div>
        </div>

        <div className="flex justify-end gap-4">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/parapharmacy/ingredients')}
          >
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            disabled={createMutation.isPending || updateMutation.isPending}
          >
            {createMutation.isPending || updateMutation.isPending
              ? t('common:saving')
              : t('common:save')}
          </Button>
        </div>
      </form>
    </div>
  )
}
