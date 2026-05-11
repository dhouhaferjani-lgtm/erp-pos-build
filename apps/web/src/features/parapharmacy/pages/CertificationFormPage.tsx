import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/atoms/Button/Button';
import { Input } from '@/components/atoms/Input/Input';
import { Spinner } from '@/components/atoms/Spinner/Spinner';
import { tenantScopedKey } from '@/lib/tenantScopedKey';
import { useAuthStore } from '@/stores/authStore';
import { useCompanyStore } from '@/stores/companyStore';
import { TranslationEditor, type Translation } from '../components';
import {
  fetchCertification,
  createCertification,
  updateCertification,
  type CreateCertificationInput,
} from '../api/certificationApi';
import { parapharmacyListInvalidationPredicate } from './tenantScope';
import { toast } from 'sonner';

export function CertificationFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null);
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null);
  const isEdit = !!id && id !== 'new';

  const [type, setType] = useState('');
  const [slug, setSlug] = useState('');
  const [certifyingBody, setCertifyingBody] = useState('');
  const [logoUrl, setLogoUrl] = useState('');
  const [verificationUrl, setVerificationUrl] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [displayOrder, setDisplayOrder] = useState(0);
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', description: '' },
  ]);

  const { data: certification, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'certifications', id]),
    queryFn: () => fetchCertification(id!),
    enabled: isEdit && !!tenantId && !!companyId,
  });

  useEffect(() => {
    if (certification) {
      setType(certification.type);
      setSlug(certification.slug);
      setCertifyingBody(certification.certifying_body || '');
      setLogoUrl(certification.logo_url || '');
      setVerificationUrl(certification.verification_url || '');
      setIsActive(certification.is_active);
      setDisplayOrder(certification.display_order);

      setTranslations([
        {
          locale: 'en',
          name: certification.name,
          description: certification.description || '',
        },
      ]);
    }
  }, [certification]);

  const createMutation = useMutation({
    mutationFn: createCertification,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'certifications'),
      });
      toast.success(t('parapharmacy:certificationCreated'));
      navigate('/parapharmacy/certifications');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createCertificationError');
      toast.error(message);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateCertificationInput) => updateCertification(id!, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'certifications'),
      });
      toast.success(t('parapharmacy:certificationUpdated'));
      navigate('/parapharmacy/certifications');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateCertificationError');
      toast.error(message);
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const data: CreateCertificationInput = {
      type,
      slug,
      certifying_body: certifyingBody || null,
      logo_url: logoUrl || null,
      verification_url: verificationUrl || null,
      is_active: isActive,
      display_order: displayOrder,
      translations: translations.map((t) => ({
        id: t.id ?? '',
        locale: t.locale,
        name: t.name,
        description: t.description || null,
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
          onClick={() => navigate('/parapharmacy/certifications')}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          {t('common:back')}
        </Button>
        <div>
          <h1 className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editCertification')
              : t('parapharmacy:addCertification')}
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
                  htmlFor="type"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:type')} <span className="text-red-600">*</span>
                </label>
                <Input
                  id="type"
                  value={type}
                  onChange={(e) => { setType(e.target.value); }}
                  required
                  placeholder="organic, vegan, halal, fair-trade"
                />
                <p className="text-xs text-gray-500 mt-1">
                  {t('parapharmacy:typeHelp')}
                </p>
              </div>

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
                  placeholder="ecocert-organic"
                />
                <p className="text-xs text-gray-500 mt-1">
                  {t('parapharmacy:slugHelp')}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="certifying_body"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:certifyingBody')}
                </label>
                <Input
                  id="certifying_body"
                  value={certifyingBody}
                  onChange={(e) => { setCertifyingBody(e.target.value); }}
                  placeholder="Ecocert, NSF, USDA"
                />
              </div>

              <div>
                <label
                  htmlFor="display_order"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:displayOrder')} <span className="text-red-600">*</span>
                </label>
                <Input
                  id="display_order"
                  type="number"
                  value={displayOrder}
                  onChange={(e) => { setDisplayOrder(parseInt(e.target.value, 10)); }}
                  required
                  min="0"
                />
                <p className="text-xs text-gray-500 mt-1">
                  {t('parapharmacy:displayOrderHelp')}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor="logo_url"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:logoUrl')}
                </label>
                <Input
                  id="logo_url"
                  type="url"
                  value={logoUrl}
                  onChange={(e) => { setLogoUrl(e.target.value); }}
                  placeholder="https://example.com/logo.png"
                />
              </div>

              <div>
                <label
                  htmlFor="verification_url"
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('parapharmacy:verificationUrl')}
                </label>
                <Input
                  id="verification_url"
                  type="url"
                  value={verificationUrl}
                  onChange={(e) => { setVerificationUrl(e.target.value); }}
                  placeholder="https://example.com/verify"
                />
              </div>
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_active"
                checked={isActive}
                onChange={(e) => { setIsActive(e.target.checked); }}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="is_active" className="text-sm text-gray-700">
                {t('parapharmacy:isActive')}
              </label>
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
            onClick={() => navigate('/parapharmacy/certifications')}
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
