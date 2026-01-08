import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TranslationEditor, Translation } from '../components/TranslationEditor';
import {
  fetchIngredient,
  createIngredient,
  updateIngredient,
  type CreateIngredientInput,
} from '../api/ingredientApi';
import { toast } from 'sonner';

export function IngredientFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const isEdit = !!id && id !== 'new';

  const [slug, setSlug] = useState('');
  const [casNumber, setCasNumber] = useState('');
  const [isAllergen, setIsAllergen] = useState(false);
  const [allergenCode, setAllergenCode] = useState('');
  const [regulatoryStatus, setRegulatoryStatus] = useState<
    'approved' | 'restricted' | 'banned'
  >('approved');
  const [notes, setNotes] = useState('');
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', name: '', description: '' },
  ]);

  const { data: ingredient, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'ingredients', id],
    queryFn: () => fetchIngredient(id!),
    enabled: isEdit,
  });

  useEffect(() => {
    if (ingredient) {
      setSlug(ingredient.slug);
      setCasNumber(ingredient.cas_number || '');
      setIsAllergen(ingredient.is_allergen);
      setAllergenCode(ingredient.allergen_code || '');
      setRegulatoryStatus(
        (ingredient.regulatory_status as 'approved' | 'restricted' | 'banned') ||
          'approved'
      );
      setNotes(ingredient.notes || '');

      // Load translations - ingredient should have translations loaded via HasTranslations trait
      // For now, create default English translation with current data
      setTranslations([
        {
          locale: 'en',
          name: ingredient.name,
          description: ingredient.description || '',
        },
      ]);
    }
  }, [ingredient]);

  const createMutation = useMutation({
    mutationFn: createIngredient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'ingredients'] });
      toast.success(t('parapharmacy:ingredientCreated'));
      navigate('/parapharmacy/ingredients');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createIngredientError');
      toast.error(message);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateIngredientInput) => updateIngredient(id!, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'ingredients'] });
      toast.success(t('parapharmacy:ingredientUpdated'));
      navigate('/parapharmacy/ingredients');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateIngredientError');
      toast.error(message);
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const data: CreateIngredientInput = {
      slug,
      cas_number: casNumber || null,
      is_allergen: isAllergen,
      allergen_code: allergenCode || null,
      regulatory_status: regulatoryStatus,
      notes: notes || null,
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
        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:basicInformation')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="slug">
                  {t('parapharmacy:slug')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="slug"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  required
                  placeholder="vitamin-c-ascorbic-acid"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  {t('parapharmacy:slugHelp')}
                </p>
              </div>

              <div>
                <Label htmlFor="cas_number">{t('parapharmacy:casNumber')}</Label>
                <Input
                  id="cas_number"
                  value={casNumber}
                  onChange={(e) => setCasNumber(e.target.value)}
                  placeholder="50-81-7"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="regulatory_status">
                  {t('parapharmacy:regulatoryStatus')}{' '}
                  <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={regulatoryStatus}
                  onValueChange={(value: 'approved' | 'restricted' | 'banned') =>
                    setRegulatoryStatus(value)
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="approved">
                      {t('parapharmacy:regulatoryStatus.approved')}
                    </SelectItem>
                    <SelectItem value="restricted">
                      {t('parapharmacy:regulatoryStatus.restricted')}
                    </SelectItem>
                    <SelectItem value="banned">
                      {t('parapharmacy:regulatoryStatus.banned')}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label htmlFor="allergen_code">{t('parapharmacy:allergenCode')}</Label>
                <Input
                  id="allergen_code"
                  value={allergenCode}
                  onChange={(e) => setAllergenCode(e.target.value)}
                  placeholder="EU14"
                  disabled={!isAllergen}
                />
              </div>
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

            <div>
              <Label htmlFor="notes">{t('parapharmacy:notes')}</Label>
              <Textarea
                id="notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={3}
                placeholder={t('parapharmacy:notesPlaceholder')}
              />
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
  );
}
