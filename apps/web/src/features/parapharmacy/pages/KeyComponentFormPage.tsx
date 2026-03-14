import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/atoms/Button/Button';
import { Input } from '@/components/atoms/Input/Input';
import { Spinner } from '@/components/atoms/Spinner/Spinner';
import { TranslationEditor, type Translation } from '../components';
import {
  fetchKeyComponent,
  createKeyComponent,
  updateKeyComponent,
  type CreateKeyComponentInput,
} from '../api/keyComponentApi';
import { toast } from 'sonner';

export function KeyComponentFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const isEdit = !!id && id !== 'new';

  const [slug, setSlug] = useState('');
  const [isAllergen, setIsAllergen] = useState(false);
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', description: '' },
  ]);

  const { data: keyComponent, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'key-components', id],
    queryFn: () => fetchKeyComponent(id!),
    enabled: isEdit,
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'key-components'] });
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'key-components'] });
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

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
          <h1 className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editKeyComponent')
              : t('parapharmacy:addKeyComponent')}
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
                onChange={(e) => setSlug(e.target.value)}
                required
                placeholder="gelatin-capsule"
              />
              <p className="text-xs text-gray-500 mt-1">
                {t('parapharmacy:slugHelp')}
              </p>
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_allergen"
                checked={isAllergen}
                onChange={(e) => setIsAllergen(e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="is_allergen" className="text-sm text-gray-700">
                {t('parapharmacy:isAllergen')}
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
