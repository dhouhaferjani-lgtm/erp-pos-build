import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Pencil, Trash2, Building2, User, Plus, X } from 'lucide-react'
import { toast } from 'sonner'
import { fetchContact, deleteContact, linkContactToParty, unlinkContactFromParty, contactKeys, contactsInvalidationPredicate } from '../api/contactApi'
import type { LinkPartyData } from '../api/contactApi'
import { PartnerPicker } from '@/components/molecules/pickers/PartnerPicker'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Button } from '@/components/atoms/Button/Button'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Input } from '@/components/atoms/Input/Input'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function ContactDetailPage() {
  const { t } = useTranslation(['crm', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data: contact, isLoading } = useQuery({
    queryKey: tenantScopedKey([...contactKeys.detail(id)]),
    queryFn: () => fetchContact(id),
    enabled: id.length > 0 && !!tenantId && !!companyId,
  })

  const [showLinkForm, setShowLinkForm] = useState(false)
  const [linkPartyId, setLinkPartyId] = useState('')
  const [linkJobTitle, setLinkJobTitle] = useState('')

  const deleteMutation = useMutation({
    mutationFn: () => deleteContact(id),
    onSuccess: async () => {
      toast.success(t('common:messages.deleted'))
      await queryClient.invalidateQueries({
        predicate: contactsInvalidationPredicate(tenantId, companyId),
      })
      void navigate('/crm/contacts')
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const linkMutation = useMutation({
    mutationFn: (data: LinkPartyData) => linkContactToParty(id, data),
    onSuccess: async () => {
      toast.success(t('common:messages.saved'))
      await queryClient.invalidateQueries({
        predicate: contactsInvalidationPredicate(tenantId, companyId),
      })
      setShowLinkForm(false)
      setLinkPartyId('')
      setLinkJobTitle('')
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const unlinkMutation = useMutation({
    mutationFn: (partyId: string) => unlinkContactFromParty(id, partyId),
    onSuccess: async () => {
      toast.success(t('common:messages.saved'))
      await queryClient.invalidateQueries({
        predicate: contactsInvalidationPredicate(tenantId, companyId),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  if (isLoading) {
    return <div className={`flex items-center justify-center py-12 ${colorTokens.text.subtle}`}>{t('common:common.loading')}</div>
  }

  if (!contact) {
    return <div className={`flex items-center justify-center py-12 ${colorTokens.text.subtle}`}>{t('crm:contacts.noContacts')}</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link
          to="/crm/contacts"
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
      </div>

      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className={`flex h-12 w-12 items-center justify-center rounded-full ${colorTokens.intent.primary.bgSoft}`}>
            <User className={`h-6 w-6 ${colorTokens.intent.primary.text}`} />
          </div>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{contact.full_name}</PageHeaderTitle>
            <p className={`text-sm ${colorTokens.text.subtle}`}>{contact.phone ?? contact.email ?? ''}</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Link to={`/crm/contacts/${id}/edit`}>
            <Button variant="secondary">
              <Pencil className="h-4 w-4 me-2" />
              {t('common:actions.edit')}
            </Button>
          </Link>
          <Button
            variant="danger"
            onClick={() => {
              if (window.confirm(t('crm:contacts.deleteConfirm'))) {
                deleteMutation.mutate()
              }
            }}
          >
            <Trash2 className="h-4 w-4 me-2" />
            {t('common:actions.delete')}
          </Button>
        </div>
      </div>

      {/* Personal Information */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>{t('crm:contacts.personalInfo')}</h2>
        <dl className="grid grid-cols-2 gap-4">
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.firstName')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.first_name}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.lastName')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.last_name ?? '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.phone')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.phone ?? '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.mobile')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.mobile ?? '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.email')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.email ?? '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.dateOfBirth')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.date_of_birth ?? '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.gender')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.gender ? t(`crm:contacts.genders.${contact.gender}`) : '\u2014'}</dd>
          </div>
          <div>
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.nationalId')}</dt>
            <dd className={`text-sm ${colorTokens.text.primary}`}>{contact.national_id ?? '\u2014'}</dd>
          </div>
        </dl>
        {contact.notes && (
          <div className="mt-4">
            <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('crm:contacts.notes')}</dt>
            <dd className={`mt-1 text-sm ${colorTokens.text.primary}`}>{contact.notes}</dd>
          </div>
        )}
      </div>

      {/* Company Associations */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('crm:contacts.companyAssociations')}</h2>
          {!showLinkForm && (
            <Button variant="secondary" onClick={() => { setShowLinkForm(true); }}>
              <Plus className="h-4 w-4 me-2" />
              {t('crm:contacts.linkCompany')}
            </Button>
          )}
        </div>

        {showLinkForm && (
          <div className={`mb-4 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4`}>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label className={`mb-1 block text-sm font-medium ${colorTokens.text.secondary}`}>{t('crm:contacts.company')}</label>
                <PartnerPicker
                  value={linkPartyId}
                  onChange={(next) => { setLinkPartyId(next?.id ?? '') }}
                  partnerType="all"
                  label=""
                  placeholder={t('crm:contacts.searchCompany')}
                />
              </div>
              <div>
                <label className={`mb-1 block text-sm font-medium ${colorTokens.text.secondary}`}>{t('crm:contacts.jobTitle')}</label>
                <Input
                  value={linkJobTitle}
                  onChange={(e) => { setLinkJobTitle(e.target.value); }}
                />
              </div>
            </div>
            <div className="mt-4 flex gap-2">
              <Button
                variant="primary"
                disabled={!linkPartyId || linkMutation.isPending}
                onClick={() => {
                  linkMutation.mutate({
                    party_id: linkPartyId,
                    ...(linkJobTitle ? { job_title: linkJobTitle } : {}),
                  })
                }}
              >
                {t('crm:contacts.linkCompany')}
              </Button>
              <Button variant="secondary" onClick={() => { setShowLinkForm(false); setLinkPartyId(''); setLinkJobTitle('') }}>
                {t('common:actions.cancel')}
              </Button>
            </div>
          </div>
        )}

        {contact.parties && contact.parties.length > 0 ? (
          <div className="space-y-3">
            {contact.parties.map((party) => (
              <div key={party.id} className={`flex items-center justify-between rounded-lg border ${colorTokens.border.hairline} p-3`}>
                <div className="flex items-center gap-3">
                  <Building2 className={`h-5 w-5 ${colorTokens.text.disabled}`} />
                  <div>
                    <p className={`text-sm font-medium ${colorTokens.text.primary}`}>{party.name}</p>
                    {party.job_title && <p className={`text-xs ${colorTokens.text.subtle}`}>{party.job_title}</p>}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {party.is_primary && (
                    <Badge variant="info">
                      {t('crm:contacts.isPrimary')}
                    </Badge>
                  )}
                  <button
                    type="button"
                    className={`rounded p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextRed500}`}
                    title={t('crm:contacts.unlinkCompany')}
                    onClick={() => {
                      if (window.confirm(t('crm:contacts.unlinkConfirm'))) {
                        unlinkMutation.mutate(party.id)
                      }
                    }}
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('crm:contacts.noCompanies')}</p>
        )}
      </div>
    </div>
  )
}
