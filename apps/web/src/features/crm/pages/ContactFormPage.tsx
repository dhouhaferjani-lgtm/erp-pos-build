import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft } from 'lucide-react'
import { toast } from 'sonner'
import { fetchContact, createContact, updateContact, contactKeys, contactsInvalidationPredicate } from '../api/contactApi'
import type { CreateContactData } from '../api/contactApi'
import { PartnerPicker } from '@/components/molecules/pickers/PartnerPicker'
import { Input } from '@/components/atoms/Input/Input'
import { Select } from '@/components/atoms/Select/Select'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Button } from '@/components/atoms/Button/Button'
import { FormField } from '@/components/atoms/FormField/FormField'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const contactSchema = z.object({
  first_name: z.string().min(1, 'crm:contacts.validation.firstNameRequired'),
  last_name: z.string(),
  phone: z.string(),
  email: z.string().refine(
    (val) => val === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val),
    'crm:contacts.validation.invalidEmail'
  ),
  mobile: z.string(),
  date_of_birth: z.string(),
  gender: z.string(),
  national_id: z.string(),
  notes: z.string(),
  party_id: z.string().nullable(),
  job_title: z.string(),
  is_primary: z.boolean(),
})

type ContactFormData = z.infer<typeof contactSchema>

export function ContactFormPage() {
  const { t } = useTranslation(['crm', 'common'])
  const navigate = useNavigate()
  const { id = '' } = useParams<{ id: string }>()
  const isEditing = id.length > 0
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<ContactFormData>({
    resolver: zodResolver(contactSchema),
    defaultValues: {
      first_name: '',
      last_name: '',
      phone: '',
      email: '',
      mobile: '',
      date_of_birth: '',
      gender: '',
      national_id: '',
      notes: '',
      party_id: null,
      job_title: '',
      is_primary: false,
    },
  })

  const { data: existingContact } = useQuery({
    queryKey: tenantScopedKey([...contactKeys.detail(id)]),
    queryFn: () => fetchContact(id),
    enabled: isEditing && !!tenantId && !!companyId,
  })

  useEffect(() => {
    if (existingContact) {
      const primaryParty = existingContact.parties?.find((p) => p.is_primary) ?? existingContact.parties?.[0]
      reset({
        first_name: existingContact.first_name,
        last_name: existingContact.last_name ?? '',
        phone: existingContact.phone ?? '',
        email: existingContact.email ?? '',
        mobile: existingContact.mobile ?? '',
        date_of_birth: existingContact.date_of_birth ?? '',
        gender: existingContact.gender ?? '',
        national_id: existingContact.national_id ?? '',
        notes: existingContact.notes ?? '',
        party_id: primaryParty?.id ?? null,
        job_title: primaryParty?.job_title ?? '',
        is_primary: primaryParty?.is_primary ?? false,
      })
    }
  }, [existingContact, reset])

  const createMutation = useMutation({
    mutationFn: (data: CreateContactData) => createContact(data),
    onSuccess: async (contact) => {
      toast.success(t('common:messages.saved'))
      await queryClient.invalidateQueries({
        predicate: contactsInvalidationPredicate(tenantId, companyId),
      })
      void navigate(`/crm/contacts/${contact.id}`)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: Partial<CreateContactData>) => updateContact(id, data),
    onSuccess: async () => {
      toast.success(t('common:messages.saved'))
      await queryClient.invalidateQueries({
        predicate: contactsInvalidationPredicate(tenantId, companyId),
      })
      void navigate(`/crm/contacts/${id}`)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const isMutating = createMutation.isPending || updateMutation.isPending

  const onSubmit = (data: ContactFormData) => {
    const payload: CreateContactData = { first_name: data.first_name }
    if (data.last_name) payload.last_name = data.last_name
    if (data.phone) payload.phone = data.phone
    if (data.email) payload.email = data.email
    if (data.mobile) payload.mobile = data.mobile
    if (data.date_of_birth) payload.date_of_birth = data.date_of_birth
    if (data.gender) payload.gender = data.gender
    if (data.national_id) payload.national_id = data.national_id
    if (data.notes) payload.notes = data.notes
    if (data.party_id) {
      payload.party_id = data.party_id
      if (data.job_title) payload.job_title = data.job_title
      payload.is_primary = data.is_primary
    }

    if (isEditing) {
      updateMutation.mutate(payload)
    } else {
      createMutation.mutate(payload)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link
          to="/crm/contacts"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? t('crm:contacts.editContact') : t('crm:contacts.newContact')}
        </h1>
      </div>

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="grid gap-6 sm:grid-cols-2">
            <FormField
              label={t('crm:contacts.firstName')}
              htmlFor="first_name"
              required
              error={errors.first_name?.message ? t(errors.first_name.message) : undefined}
            >
              <Input
                id="first_name"
                {...register('first_name')}
                error={!!errors.first_name}
              />
            </FormField>

            <FormField
              label={t('crm:contacts.lastName')}
              htmlFor="last_name"
            >
              <Input
                id="last_name"
                {...register('last_name')}
              />
            </FormField>

            <FormField
              label={t('crm:contacts.phone')}
              htmlFor="phone"
            >
              <Input
                id="phone"
                type="tel"
                {...register('phone')}
              />
            </FormField>

            <FormField
              label={t('crm:contacts.mobile')}
              htmlFor="mobile"
            >
              <Input
                id="mobile"
                type="tel"
                {...register('mobile')}
              />
            </FormField>

            <div className="sm:col-span-2">
              <FormField
                label={t('crm:contacts.email')}
                htmlFor="email"
                error={errors.email?.message ? t(errors.email.message) : undefined}
              >
                <Input
                  id="email"
                  type="email"
                  {...register('email')}
                  error={!!errors.email}
                />
              </FormField>
            </div>

            <FormField
              label={t('crm:contacts.dateOfBirth')}
              htmlFor="date_of_birth"
            >
              <Input
                id="date_of_birth"
                type="date"
                {...register('date_of_birth')}
              />
            </FormField>

            <FormField
              label={t('crm:contacts.gender')}
              htmlFor="gender"
            >
              <Select id="gender" {...register('gender')}>
                <option value="">{t('common:select')}</option>
                <option value="male">{t('crm:contacts.genders.male')}</option>
                <option value="female">{t('crm:contacts.genders.female')}</option>
                <option value="other">{t('crm:contacts.genders.other')}</option>
              </Select>
            </FormField>

            <div className="sm:col-span-2">
              <FormField
                label={t('crm:contacts.nationalId')}
                htmlFor="national_id"
              >
                <Input
                  id="national_id"
                  {...register('national_id')}
                />
              </FormField>
            </div>

            <div className="sm:col-span-2">
              <FormField
                label={t('crm:contacts.notes')}
                htmlFor="notes"
              >
                <Textarea
                  id="notes"
                  rows={3}
                  {...register('notes')}
                />
              </FormField>
            </div>
          </div>
        </div>

        {/* Company Association (optional) */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">
            {t('crm:contacts.companyAssociations')}
          </h2>
          <div className="grid gap-6 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <FormField label={t('crm:contacts.company')} htmlFor="party_id">
                <Controller
                  name="party_id"
                  control={control}
                  render={({ field }) => (
                    <PartnerPicker
                      value={field.value ?? null}
                      onChange={(next) => { field.onChange(next?.id ?? null) }}
                      partnerType="all"
                      label=""
                      placeholder={t('crm:contacts.searchCompany')}
                    />
                  )}
                />
              </FormField>
            </div>
            <FormField label={t('crm:contacts.jobTitle')} htmlFor="job_title">
              <Input
                id="job_title"
                {...register('job_title')}
              />
            </FormField>
            <FormField label={t('crm:contacts.isPrimary')} htmlFor="is_primary">
              <label className="flex items-center gap-2 mt-1">
                <input
                  type="checkbox"
                  id="is_primary"
                  {...register('is_primary')}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <span className="text-sm text-gray-700">{t('crm:contacts.isPrimary')}</span>
              </label>
            </FormField>
          </div>
        </div>

        <div className="flex items-center justify-end gap-4">
          <Link to="/crm/contacts">
            <Button variant="secondary" type="button">
              {t('common:actions.cancel')}
            </Button>
          </Link>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || isMutating}
          >
            {isMutating ? t('common:status.saving') : (isEditing ? t('common:actions.update') : t('common:actions.create'))}
          </Button>
        </div>
      </form>
    </div>
  )
}
