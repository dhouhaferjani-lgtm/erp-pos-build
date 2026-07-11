import { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/atoms/Button/Button';
import { Input } from '@/components/atoms/Input/Input';
import { Select } from '@/components/atoms/Select/Select';
import { Spinner } from '@/components/atoms/Spinner/Spinner';
import { tenantScopedKey } from '@/lib/tenantScopedKey';
import { useAuthStore } from '@/stores/authStore';
import { useCompanyStore } from '@/stores/companyStore';
import { TranslationEditor, type Translation } from '../components';
import {
  fetchHealthClaim,
  createHealthClaim,
  updateHealthClaim,
  type CreateHealthClaimInput,
} from '../api/healthClaimApi';
import { parapharmacyListInvalidationPredicate } from './tenantScope';
import { toast } from 'sonner';
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function HealthClaimFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null);
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null);
  const isEdit = !!id && id !== 'new';
  const { handleSubmit: handleFormSubmit } = useForm();

  const [claimType, setClaimType] = useState<
    'function' | 'reduction_of_disease_risk' | 'development_and_health'
  >('function');
  const [slug, setSlug] = useState('');
  const [regulatoryStatus, setRegulatoryStatus] = useState<
    'approved' | 'pending' | 'rejected'
  >('approved');
  const [efsaReference, setEfsaReference] = useState('');
  const [fdaReference, setFdaReference] = useState('');
  const [countryRestrictions, setCountryRestrictions] = useState('');
  const [requiresDisclaimer, setRequiresDisclaimer] = useState(false);
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', claim: '', disclaimer_text: '' },
  ]);

  const { data: healthClaim, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'health-claims', id]),
    queryFn: () => fetchHealthClaim(id!),
    enabled: isEdit && !!tenantId && !!companyId,
  });

  useEffect(() => {
    if (healthClaim) {
      setClaimType(healthClaim.claim_type);
      setSlug(healthClaim.slug);
      setRegulatoryStatus(healthClaim.regulatory_status);
      setEfsaReference(healthClaim.efsa_reference || '');
      setFdaReference(healthClaim.fda_reference || '');
      setCountryRestrictions(
        healthClaim.country_restrictions?.join(', ') || ''
      );
      setRequiresDisclaimer(healthClaim.requires_disclaimer);

      setTranslations([
        {
          locale: 'en',
          name: '',
          claim: healthClaim.claim,
          disclaimer_text: healthClaim.disclaimer_text || '',
        },
      ]);
    }
  }, [healthClaim]);

  const createMutation = useMutation({
    mutationFn: createHealthClaim,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'health-claims'),
      });
      toast.success(t('parapharmacy:healthClaimCreated'));
      navigate('/parapharmacy/health-claims');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createHealthClaimError');
      toast.error(message);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateHealthClaimInput) => updateHealthClaim(id!, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'health-claims'),
      });
      toast.success(t('parapharmacy:healthClaimUpdated'));
      navigate('/parapharmacy/health-claims');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateHealthClaimError');
      toast.error(message);
    },
  });

  const submitHealthClaim = () => {
    const data: CreateHealthClaimInput = {
      claim_type: claimType,
      slug,
      regulatory_status: regulatoryStatus,
      efsa_reference: efsaReference || null,
      fda_reference: fdaReference || null,
      country_restrictions: countryRestrictions
        ? countryRestrictions.split(',').map((c) => c.trim())
        : null,
      requires_disclaimer: requiresDisclaimer,
      translations: translations.map((t) => ({
        id: t.id ?? '',
        locale: t.locale,
        claim: t['claim'] as string,
        disclaimer_text: (t['disclaimer_text'] as string) || null,
      })),
    };

    if (isEdit) {
      updateMutation.mutate(data);
    } else {
      createMutation.mutate(data);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex items-center gap-4">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/parapharmacy/health-claims')}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          {t('common:back')}
        </Button>
        <div>
          <PageHeaderTitle className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editHealthClaim')
              : t('parapharmacy:addHealthClaim')}
          </PageHeaderTitle>
        </div>
      </div>

      <form onSubmit={(event) => { void handleFormSubmit(submitHealthClaim)(event) }} className="space-y-6">
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white shadow-sm`}>
          <div className={`border-b ${colorTokens.border.subtle} px-6 py-4`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('parapharmacy:basicInformation')}
            </h2>
          </div>
          <div className="px-6 py-4 space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="claim_type"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:claimTypeLabel')} <span className={`${colorTokens.intent.danger.text}`}>*</span>
                </label>
                <Select
                  id="claim_type"
                  value={claimType}
                  onChange={(e) =>
                    { setClaimType(
                      e.target.value as
                        | 'function'
                        | 'reduction_of_disease_risk'
                        | 'development_and_health'
                    ); }
                  }
                  required
                >
                  <option value="function">
                    {t('parapharmacy:claimType.function')}
                  </option>
                  <option value="reduction_of_disease_risk">
                    {t('parapharmacy:claimType.reduction_of_disease_risk')}
                  </option>
                  <option value="development_and_health">
                    {t('parapharmacy:claimType.development_and_health')}
                  </option>
                </Select>
              </div>

              <div>
                <label
                  htmlFor="slug"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:slug')} <span className={`${colorTokens.intent.danger.text}`}>*</span>
                </label>
                <Input
                  id="slug"
                  value={slug}
                  onChange={(e) => { setSlug(e.target.value); }}
                  required
                  placeholder="supports-immune-function"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="regulatory_status"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:regulatoryStatusLabel')}{' '}
                  <span className={`${colorTokens.intent.danger.text}`}>*</span>
                </label>
                <Select
                  id="regulatory_status"
                  value={regulatoryStatus}
                  onChange={(e) =>
                    { setRegulatoryStatus(e.target.value as 'approved' | 'pending' | 'rejected'); }
                  }
                  required
                >
                  <option value="approved">
                    {t('parapharmacy:regulatoryStatus.approved')}
                  </option>
                  <option value="pending">
                    {t('parapharmacy:regulatoryStatus.pending')}
                  </option>
                  <option value="rejected">
                    {t('parapharmacy:regulatoryStatus.rejected')}
                  </option>
                </Select>
              </div>

              <div>
                <label
                  htmlFor="efsa_reference"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:efsaReference')}
                </label>
                <Input
                  id="efsa_reference"
                  value={efsaReference}
                  onChange={(e) => { setEfsaReference(e.target.value); }}
                  placeholder={t('parapharmacy:efsaReferencePlaceholder')}
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="fda_reference"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:fdaReference')}
                </label>
                <Input
                  id="fda_reference"
                  value={fdaReference}
                  onChange={(e) => { setFdaReference(e.target.value); }}
                  placeholder={t('parapharmacy:fdaReferencePlaceholder')}
                />
              </div>

              <div>
                <label
                  htmlFor="country_restrictions"
                  className={`block text-sm font-medium ${colorTokens.text.secondary} mb-1`}
                >
                  {t('parapharmacy:countryRestrictions')}
                </label>
                <Input
                  id="country_restrictions"
                  value={countryRestrictions}
                  onChange={(e) => { setCountryRestrictions(e.target.value); }}
                  placeholder={t('parapharmacy:countryRestrictionsPlaceholder')}
                />
                <p className={`text-xs ${colorTokens.text.subtle} mt-1`}>
                  {t('parapharmacy:countryRestrictionsHelp')}
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="requires_disclaimer"
                checked={requiresDisclaimer}
                onChange={(e) => { setRequiresDisclaimer(e.target.checked); }}
                className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.variants.focusRingBlue500}`}
              />
              <label htmlFor="requires_disclaimer" className={`text-sm ${colorTokens.text.secondary}`}>
                {t('parapharmacy:requiresDisclaimer')}
              </label>
            </div>
          </div>
        </div>

        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white shadow-sm`}>
          <div className={`border-b ${colorTokens.border.subtle} px-6 py-4`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('parapharmacy:translations')}
            </h2>
          </div>
          <div className="px-6 py-4">
            <TranslationEditor
              translations={translations}
              onChange={setTranslations}
              fields={[
                {
                  name: 'claim',
                  label: t('parapharmacy:claim'),
                  required: true,
                  multiline: true,
                },
                {
                  name: 'disclaimer_text',
                  label: t('parapharmacy:disclaimerText'),
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
            onClick={() => navigate('/parapharmacy/health-claims')}
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
  );
}
