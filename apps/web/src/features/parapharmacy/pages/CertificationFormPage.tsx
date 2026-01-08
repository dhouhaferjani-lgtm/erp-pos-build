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
  fetchCertification,
  createCertification,
  updateCertification,
  type CreateCertificationInput,
} from '../api/certificationApi';
import { toast } from 'sonner';

export function CertificationFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
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
    queryKey: ['parapharmacy', 'certifications', id],
    queryFn: () => fetchCertification(id!),
    enabled: isEdit,
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'certifications'] });
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'certifications'] });
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
        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:basicInformation')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="type">
                  {t('parapharmacy:type')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="type"
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                  required
                  placeholder="organic, vegan, halal, fair-trade"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  {t('parapharmacy:typeHelp')}
                </p>
              </div>

              <div>
                <Label htmlFor="slug">
                  {t('parapharmacy:slug')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="slug"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  required
                  placeholder="ecocert-organic"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  {t('parapharmacy:slugHelp')}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="certifying_body">{t('parapharmacy:certifyingBody')}</Label>
                <Input
                  id="certifying_body"
                  value={certifyingBody}
                  onChange={(e) => setCertifyingBody(e.target.value)}
                  placeholder="Ecocert, NSF, USDA"
                />
              </div>

              <div>
                <Label htmlFor="display_order">
                  {t('parapharmacy:displayOrder')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="display_order"
                  type="number"
                  value={displayOrder}
                  onChange={(e) => setDisplayOrder(parseInt(e.target.value, 10))}
                  required
                  min="0"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  {t('parapharmacy:displayOrderHelp')}
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="logo_url">{t('parapharmacy:logoUrl')}</Label>
                <Input
                  id="logo_url"
                  type="url"
                  value={logoUrl}
                  onChange={(e) => setLogoUrl(e.target.value)}
                  placeholder="https://example.com/logo.png"
                />
              </div>

              <div>
                <Label htmlFor="verification_url">{t('parapharmacy:verificationUrl')}</Label>
                <Input
                  id="verification_url"
                  type="url"
                  value={verificationUrl}
                  onChange={(e) => setVerificationUrl(e.target.value)}
                  placeholder="https://example.com/verify"
                />
              </div>
            </div>

            <div className="flex items-center space-x-2">
              <Checkbox
                id="is_active"
                checked={isActive}
                onCheckedChange={(checked) => setIsActive(checked as boolean)}
              />
              <Label htmlFor="is_active" className="font-normal">
                {t('parapharmacy:isActive')}
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
