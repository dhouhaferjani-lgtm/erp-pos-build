import { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
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
  fetchKeyComponent,
  createKeyComponent,
  updateKeyComponent,
  type CreateKeyComponentInput,
} from '../api/keyComponentApi';
import { parapharmacyListInvalidationPredicate } from './tenantScope';
import { toast } from 'sonner';
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function KeyComponentFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null);
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null);
  const isEdit = !!id && id !== 'new';
  const { handleSubmit: handleFormSubmit } = useForm();

  const [slug, setSlug] = useState('');
  const [isAllergen, setIsAllergen] = useState(false);
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', description: '' },
  ]);

  const { data: keyComponent, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'key-components', id]),
    queryFn: () => fetchKeyComponent(id!),
    enabled: isEdit && !!tenantId && !!companyId,
  });

  useEffect(() => {
    if (keyComponent) {
      setSlug(keyComponent.slug);
      setIsAllergen(keyComponent.is_allergen);

      setTranslations([
        {
          locale: 'en',
          name: keyComponent.name,
          description: keyComponent.description || '',
        },
      ]);
    }
  }, [keyComponent]);

  const createMutation = useMutation({
    mutationFn: createKeyComponent,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'key-components'),
      });
      toast.success(t('parapharmacy:keyComponentCreated'));
      navigate('/parapharmacy/key-components');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createKeyComponentError');
      toast.error(message);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateKeyComponentInput) => updateKeyComponent(id!, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'key-components'),
      });
      toast.success(t('parapharmacy:keyComponentUpdated'));
      navigate('/parapharmacy/key-components');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateKeyComponentError');
      toast.error(message);
    },
  });

  const submitKeyComponent = () => {
    const data: CreateKeyComponentInput = {
      slug,
      is_allergen: isAllergen,
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
          onClick={() => navigate('/parapharmacy/key-components')}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          {t('common:back')}
        </Button>
        <div>
          <PageHeaderTitle className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editKeyComponent')
              : t('parapharmacy:addKeyComponent')}
          </PageHeaderTitle>
        </div>
      </div>

      <form onSubmit={(event) => { void handleFormSubmit(submitKeyComponent)(event) }} className="space-y-6">
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white shadow-sm`}>
          <div className={`border-b ${colorTokens.border.subtle} px-6 py-4`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('parapharmacy:basicInformation')}
            </h2>
          </div>
          <div className="px-6 py-4 space-y-4">
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
                placeholder="gelatin-capsule"
              />
              <p className={`text-xs ${colorTokens.text.subtle} mt-1`}>
                {t('parapharmacy:slugHelp')}
              </p>
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_allergen"
                checked={isAllergen}
                onChange={(e) => { setIsAllergen(e.target.checked); }}
                className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} focus:${colorTokens.intent.primary.ring}`}
              />
              <label htmlFor="is_allergen" className={`text-sm ${colorTokens.text.secondary}`}>
                {t('parapharmacy:isAllergen')}
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
            onClick={() => navigate('/parapharmacy/key-components')}
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
