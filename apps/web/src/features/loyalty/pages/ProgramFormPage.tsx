import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft } from 'lucide-react'
import { Button, Input, FormField, Select } from '@/components/atoms'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'

import { useProgram, useCreateProgram, useUpdateProgram } from '../hooks/usePrograms'
import type { CreateProgramData, ProgramType } from '../types/loyalty'

const programSchema = z.object({
  name: z.string().min(1),
  program_type: z.enum(['points', 'stamps', 'visits', 'cashback', 'hybrid']),
  currency: z.string().optional().nullable(),
  start_date: z.string().optional().nullable(),
  end_date: z.string().optional().nullable(),
  terms_and_conditions: z.string().optional().nullable(),
})

type ProgramFormValues = z.infer<typeof programSchema>

export function ProgramFormPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = !!id

  const { data: existingProgram, isLoading: isLoadingProgram } = useProgram(id ?? '')
  const createMutation = useCreateProgram()
  const updateMutation = useUpdateProgram()

  const form = useForm<ProgramFormValues>({
    resolver: zodResolver(programSchema) as Resolver<ProgramFormValues>,
    defaultValues: {
      name: '',
      program_type: 'points',
      currency: null,
      start_date: null,
      end_date: null,
      terms_and_conditions: null,
    },
  })

  useEffect(() => {
    if (existingProgram) {
      form.reset({
        name: existingProgram.name,
        program_type: existingProgram.program_type,
        currency: existingProgram.currency,
        start_date: existingProgram.start_date?.slice(0, 10) ?? null,
        end_date: existingProgram.end_date?.slice(0, 10) ?? null,
        terms_and_conditions: existingProgram.terms_and_conditions,
      })
    }
  }, [existingProgram, form])

  const onSubmit = (values: ProgramFormValues) => {
    const payload: CreateProgramData = {
      name: values.name,
      program_type: values.program_type as ProgramType,
      currency: values.currency || null,
      start_date: values.start_date || null,
      end_date: values.end_date || null,
      terms_and_conditions: values.terms_and_conditions || null,
    }

    if (isEditing && id) {
      updateMutation.mutate(
        { id, data: payload },
        { onSuccess: () => navigate(`/pos/loyalty/programs/${id}`) },
      )
    } else {
      createMutation.mutate(payload, {
        onSuccess: () => navigate('/pos/loyalty/programs'),
      })
    }
  }

  if (isEditing && isLoadingProgram) {
    return <div className="text-center py-12 text-gray-500">{t('common:loading')}</div>
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <div className="max-w-2xl space-y-6">
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate('/pos/loyalty/programs')}
          className="p-2 rounded-lg hover:bg-gray-100"
        >
          <ArrowLeft className="w-5 h-5" />
        </button>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? t('loyalty:programs.edit') : t('loyalty:programs.create')}
        </h1>
      </div>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-8 pb-24">
        <section className="space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('loyalty:sections.basicInfo')}
          </h2>

          <FormField
            label={t('loyalty:fields.name')}
            error={form.formState.errors.name?.message}
          >
            <Input {...form.register('name')} />
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('loyalty:fields.programType')}>
              <Select {...form.register('program_type')}>
                {(['points', 'stamps', 'visits', 'cashback', 'hybrid'] as const).map((type) => (
                  <option key={type} value={type}>
                    {t(`loyalty:programTypes.${type}`)}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField label={t('loyalty:fields.currency')}>
              <Input {...form.register('currency')} placeholder="EUR" />
            </FormField>
          </div>
        </section>

        <section className="space-y-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('loyalty:sections.schedule')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('loyalty:fields.startDate')}>
              <Input {...form.register('start_date')} type="date" />
            </FormField>

            <FormField label={t('loyalty:fields.endDate')}>
              <Input {...form.register('end_date')} type="date" />
            </FormField>
          </div>
        </section>

        <section className="space-y-4">
          <FormField label={t('loyalty:fields.termsAndConditions')}>
            <Textarea {...form.register('terms_and_conditions')} rows={4} />
          </FormField>
        </section>

        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/pos/loyalty/programs')}
          >
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isSaving}>
            {isSaving ? t('common:saving') : isEditing ? t('common:save') : t('loyalty:programs.create')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
