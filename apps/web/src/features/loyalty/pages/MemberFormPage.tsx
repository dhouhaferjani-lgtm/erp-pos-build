import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft } from 'lucide-react'
import { Button, Input, FormField } from '@/components/atoms'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
import { useAfterSaveNavigation } from '@/hooks/useAfterSaveNavigation'
import { useUnsavedChangesGuard, confirmDiscard } from '@/hooks/useUnsavedChangesGuard'

import { useMember, useCreateMember, useUpdateMember } from '../hooks/useMembers'
import type { CreateMemberData } from '../types/loyalty'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

const memberSchema = z.object({
  phone: z.string().min(1),
  email: z.string().email().optional().or(z.literal('')).nullable(),
  first_name: z.string().optional().nullable(),
  last_name: z.string().optional().nullable(),
  date_of_birth: z.string().optional().nullable(),
})

type MemberFormValues = z.infer<typeof memberSchema>

export function MemberFormPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = !!id

  const { data: existingMember, isLoading: isLoadingMember } = useMember(id ?? '')
  const createMutation = useCreateMember()
  const updateMutation = useUpdateMember()

  const form = useForm<MemberFormValues>({
    resolver: zodResolver(memberSchema) as Resolver<MemberFormValues>,
    defaultValues: {
      phone: '',
      email: null,
      first_name: null,
      last_name: null,
      date_of_birth: null,
    },
  })

  const nav = useAfterSaveNavigation({
    recordPath: (rid) => `/pos/loyalty/members/${rid}`,
    listPath: '/pos/loyalty/members',
  })

  const closeIntentRef = useRef(false)

  useUnsavedChangesGuard({ isDirty: form.formState.isDirty })

  const cancel = () => {
    if (!form.formState.isDirty || confirmDiscard(t('common:confirmation.unsavedChangesBody'))) {
      navigate('/pos/loyalty/members')
    }
  }

  useEffect(() => {
    if (existingMember) {
      form.reset({
        phone: existingMember.phone,
        email: existingMember.email,
        first_name: existingMember.first_name,
        last_name: existingMember.last_name,
        date_of_birth: existingMember.date_of_birth?.slice(0, 10) ?? null,
      })
    }
  }, [existingMember, form])

  const onSubmit = (values: MemberFormValues) => {
    // Snapshot + reset the close intent up front so a failed submit
    // (validation abort or mutation error) can never leave it stuck true.
    const shouldClose = closeIntentRef.current
    closeIntentRef.current = false

    const payload: CreateMemberData = {
      phone: values.phone,
      email: values.email || null,
      first_name: values.first_name || null,
      last_name: values.last_name || null,
      date_of_birth: values.date_of_birth || null,
    }

    if (isEditing && id) {
      updateMutation.mutate(
        { id, data: payload },
        {
          onSuccess: () => {
            if (shouldClose) nav.goToList()
            else nav.goToRecord(id)
          },
        },
      )
    } else {
      createMutation.mutate(payload, {
        onSuccess: (created) => {
          if (shouldClose) nav.goToList()
          else nav.goToRecord(created.id)
        },
      })
    }
  }

  if (isEditing && isLoadingMember) {
    return <div className={`text-center py-12 ${colorTokens.text.subtle}`}>{t('common:loading')}</div>
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <div className="max-w-2xl space-y-6">
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={cancel}
          className={`p-2 rounded-lg ${colorTokens.intent.neutral.bgHoverSoft}`}
        >
          <ArrowLeft className="w-5 h-5" />
        </button>
        <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
          {isEditing ? t('loyalty:members.edit') : t('loyalty:members.create')}
        </PageHeaderTitle>
      </div>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-8 pb-24">
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('loyalty:sections.basicInfo')}</h2>

          <FormField
            label={t('loyalty:fields.phone')}
            error={form.formState.errors.phone?.message}
          >
            <Input {...form.register('phone')} type="tel" />
          </FormField>

          <FormField
            label={t('loyalty:fields.email')}
            error={form.formState.errors.email?.message}
          >
            <Input {...form.register('email')} type="email" />
          </FormField>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('loyalty:fields.firstName')}>
              <Input {...form.register('first_name')} />
            </FormField>
            <FormField label={t('loyalty:fields.lastName')}>
              <Input {...form.register('last_name')} />
            </FormField>
          </div>

          <FormField label={t('loyalty:fields.dateOfBirth')}>
            <Input {...form.register('date_of_birth')} type="date" />
          </FormField>
        </section>

        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={cancel}
          >
            {t('common:cancel')}
          </Button>
          <SaveSplitButton
            onPrimarySave={() => {}}
            onSaveAndClose={() => {
              closeIntentRef.current = true
              void form.handleSubmit(onSubmit, () => { closeIntentRef.current = false })()
            }}
            isPending={isSaving}
          />
        </StickyFormFooter>
      </form>
    </div>
  )
}
