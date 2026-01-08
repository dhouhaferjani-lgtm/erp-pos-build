import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TranslationEditor, Translation } from '../components/TranslationEditor';
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
        id: t.id,
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
        {t('common:loading')}
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
        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:basicInformation')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <Label htmlFor="slug">
                {t('parapharmacy:slug')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="slug"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                required
                placeholder="gelatin-capsule"
              />
              <p className="text-xs text-muted-foreground mt-1">
                {t('parapharmacy:slugHelp')}
              </p>
            </div>

            <div className="flex items-center space-x-2">
              <Checkbox
                id="is_allergen"
                checked={isAllergen}
                onCheckedChange={(checked) => setIsAllergen(checked as boolean)}
              />
              <Label htmlFor="is_allergen" className="font-normal">
                {t('parapharmacy:isAllergen')}
              </Label>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:translations')}</CardTitle>
          </CardHeader>
          <CardContent>
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
          </CardContent>
        </Card>

        <div className="flex justify-end gap-4">
          <Button
            type="button"
            variant="outline"
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
